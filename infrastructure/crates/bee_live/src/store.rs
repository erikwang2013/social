// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
//! 生产存储实现：MySQL（room/member CRUD，表前缀 social_）+ Redis（实时态 + 广播队列）。
//! Redis 失败静默降级（对齐 PHP WsRedis try/catch 返回 null 语义）；MySQL 失败视为记录
//! 不存在，连续失败由 http.rs 熔断器切入 503 降级。
//! 命名规范：库 social、表 social_*、唯一带 social 前缀的 Redis 键为广播队列 social:live:broadcast；
//! 状态键 live:room:* / im:room:* 与 PHP 逐字节一致（PHP 侧无前缀）。

use crate::live::{LiveStore, RoomRow};
use crate::voice::{RoomStore, VoiceRoomRow};
use mysql_async::prelude::Queryable;
use mysql_async::{Conn, Opts, OptsBuilder, Params};
use idgen_rs::{FastIdGenerator, IGOptions};
use redis::Commands;
use std::sync::{Mutex, OnceLock};

/// 广播队列键（PHP LiveCenter/WsServer 同键：rpush → lrange/ltrim 消费）。
pub const BROADCAST_QUEUE: &str = "social:live:broadcast";

pub fn mysql_opts() -> Opts {
    let host = std::env::var("DB_HOST").unwrap_or_else(|_| "127.0.0.1".into());
    let port = std::env::var("DB_PORT").ok().and_then(|v| v.parse().ok()).unwrap_or(3306);
    let db = std::env::var("DB_DATABASE").unwrap_or_else(|_| "social".into());
    let user = std::env::var("DB_USERNAME").unwrap_or_else(|_| "root".into());
    let pass = std::env::var("DB_PASSWORD").unwrap_or_default();
    OptsBuilder::default()
        .ip_or_hostname(host)
        .tcp_port(port)
        .db_name(Some(db))
        .user(Some(user))
        .pass(Some(pass))
        .into()
}

/// open_admin 库（erik_storage_provider 所在），凭据同 DB_HOST/USER/PASS。
pub fn open_admin_opts() -> Opts {
    let host = std::env::var("DB_HOST").unwrap_or_else(|_| "127.0.0.1".into());
    let port = std::env::var("DB_PORT").ok().and_then(|v| v.parse().ok()).unwrap_or(3306);
    let db = std::env::var("DB_OPEN_ADMIN_DATABASE").unwrap_or_else(|_| "open_admin".into());
    let user = std::env::var("DB_USERNAME").unwrap_or_else(|_| "root".into());
    let pass = std::env::var("DB_PASSWORD").unwrap_or_default();
    OptsBuilder::default()
        .ip_or_hostname(host)
        .tcp_port(port)
        .db_name(Some(db))
        .user(Some(user))
        .pass(Some(pass))
        .into()
}

/// 进程级 snowflake 生成器（对齐 PHP config/snowflake.php 的 worker_id 命名）。
/// ponytail: next_id() as i64 的天花板由 i64 符号位决定，即 2^63 毫秒 ≈ 2.9 亿年；
/// 时间戳字段 54 位（timestamp_shift = 6+6）约 572877 年才用尽，更不是瓶颈。
/// base_time 越大符号位越早用尽，故须 ≤ 1_700_000_000_000（对齐 PHP start_timestamp）。
/// base_time 只让两套生成器的**时间戳部分**可比；位布局不同（PHP 5+5+12，idgen_rs 6+6），
/// ID 数值域不互换。PHP 的 datacenter_id/worker_id 两个 0-31 字段此处只取 worker_id。
/// ponytail: SNOWFLAKE_WORKER_ID 越界静默 % 64（0 与 64 撞同一 worker_id），仅告警不 panic。
/// ponytail: next_id() 遇时钟回拨 >10ms 会进程级 panic（idgen_rs 无 catch），低频写可接受。
fn id_generator() -> &'static FastIdGenerator {
    static GEN: OnceLock<FastIdGenerator> = OnceLock::new();
    GEN.get_or_init(|| {
        let raw = std::env::var("SNOWFLAKE_WORKER_ID")
            .ok()
            .and_then(|v| v.parse::<u64>().ok())
            .unwrap_or(1);
        let worker = raw % 64;
        if raw > 63 {
            eprintln!(
                "SNOWFLAKE_WORKER_ID={} 超出 idgen_rs 6bit 范围 0..=63，已取模为 {}；同毫秒内可能与其他节点撞号",
                raw, worker
            );
        }
        // base_time 必须与 PHP 一致，否则两套生成器产出的 id 时间戳不可比
        let mut opts = IGOptions::new(worker as u16);
        opts.base_time = std::env::var("SNOWFLAKE_START_TIMESTAMP")
            .ok()
            .and_then(|v| v.parse().ok())
            .unwrap_or(1_700_000_000_000);
        FastIdGenerator::new(&opts)
    })
}

/// Redis 连接助手：单连接 + Mutex，与 PHP WsRedis（单例连接）同构。
pub struct RedisConn {
    conn: Mutex<redis::Connection>,
}

impl RedisConn {
    pub fn from_env() -> Option<Self> {
        let host = std::env::var("REDIS_HOST").unwrap_or_else(|_| "127.0.0.1".into());
        let port = std::env::var("REDIS_PORT").ok().and_then(|v| v.parse().ok()).unwrap_or(6379);
        let conn = redis::Client::open(format!("redis://{}:{}/", host, port)).ok()?.get_connection().ok()?;
        Some(Self { conn: Mutex::new(conn) })
    }

    /// 广播帧入队 social:live:broadcast，PHP WS worker 消费直推。
    pub fn enqueue_broadcast(&self, payload: &str) {
        if let Ok(mut c) = self.conn.lock() {
            let _ = c.rpush::<_, _, i64>(BROADCAST_QUEUE, payload);
        }
    }

    pub fn sadd(&self, key: &str, member: i64) {
        if let Ok(mut c) = self.conn.lock() {
            let _ = c.sadd::<_, _, bool>(key, member);
        }
    }

    pub fn srem(&self, key: &str, member: i64) {
        if let Ok(mut c) = self.conn.lock() {
            let _ = c.srem::<_, _, bool>(key, member);
        }
    }

    pub fn smembers(&self, key: &str) -> Vec<i64> {
        let mut c = match self.conn.lock() {
            Ok(c) => c,
            Err(_) => return Vec::new(),
        };
        c.smembers(key).unwrap_or_default()
    }

    pub fn scard(&self, key: &str) -> u64 {
        let mut c = match self.conn.lock() {
            Ok(c) => c,
            Err(_) => return 0,
        };
        c.scard(key).unwrap_or(0)
    }

    pub fn del(&self, key: &str) {
        if let Ok(mut c) = self.conn.lock() {
            let _ = c.del::<_, i64>(key);
        }
    }

    /// 弹幕：LPUSH + LTRIM 保 keep 条，与 PHP lPush/lTrim 同构。
    pub fn danmaku_push(&self, key: &str, msg_json: &str, keep: usize) {
        if let Ok(mut c) = self.conn.lock() {
            let _ = c.lpush::<_, _, i64>(key, msg_json);
            let _ = c.ltrim::<_, i64>(key, 0, keep as isize - 1);
        }
    }

    pub fn danmaku_range(&self, key: &str, limit: usize) -> Vec<String> {
        let mut c = match self.conn.lock() {
            Ok(c) => c,
            Err(_) => return Vec::new(),
        };
        c.lrange(key, 0, limit as isize).unwrap_or_default()
    }
}

/// 直播存储：MySQL social_live_rooms + Redis 实时态，键名与 PHP 逐字节一致。
/// ponytail: 同步 trait 内 block_on + 每操作新建连接（对齐 PHP-FPM 每请求连接）；
/// 批次 3 接 axum 时改 spawn_blocking，批量接口换常驻 Pool。
pub struct MySqlLiveStore {
    opts: Opts,
    rt: tokio::runtime::Runtime,
    redis: RedisConn,
}

impl MySqlLiveStore {
    pub fn from_env() -> Option<Self> {
        Some(Self {
            opts: mysql_opts(),
            rt: tokio::runtime::Runtime::new().ok()?,
            redis: RedisConn::from_env()?,
        })
    }
}

impl LiveStore for MySqlLiveStore {
    fn create_room(&self, owner: i64, title: &str) -> i64 {
        let opts = self.opts.clone();
        let title = title.to_string();
        let id = id_generator().next_id();
        self.rt.block_on(async move {
            let Ok(mut conn) = Conn::new(opts).await else { return 0 };
            if conn
                .exec_drop(
                    "INSERT INTO social_live_rooms (id, owner_id, title, status, started_at, created_at, updated_at) VALUES (?, ?, ?, 1, NOW(), NOW(), NOW())",
                    Params::from((id as i64, owner, title.as_str())),
                )
                .await
                .is_err()
            {
                return 0;
            }
            id as i64
        })
    }

    fn set_room_urls(&self, id: i64, push_url: &str, play_url: &str) {
        let opts = self.opts.clone();
        let (push, play) = (push_url.to_string(), play_url.to_string());
        self.rt.block_on(async move {
            let Ok(mut conn) = Conn::new(opts).await else { return };
            let _ = conn
                .exec_drop(
                    "UPDATE social_live_rooms SET push_url = ?, play_url = ?, updated_at = NOW() WHERE id = ?",
                    Params::from((push.as_str(), play.as_str(), id)),
                )
                .await;
        });
    }

    fn find_room(&self, id: i64) -> Option<RoomRow> {
        let opts = self.opts.clone();
        self.rt.block_on(async move {
            let mut conn = Conn::new(opts).await.ok()?;
            conn.exec_first::<(i64, i64, String, i32, String, String, Option<String>, Option<String>), _, _>(
                "SELECT id, owner_id, title, status, push_url, play_url, DATE_FORMAT(started_at, '%Y-%m-%d %H:%i:%s'), DATE_FORMAT(ended_at, '%Y-%m-%d %H:%i:%s') FROM social_live_rooms WHERE id = ?",
                Params::from((id,)),
            )
            .await
            .ok()
            .flatten()
            .map(|(id, owner_id, title, status, push_url, play_url, started_at, ended_at)| RoomRow {
                id,
                owner_id,
                title,
                status,
                push_url,
                play_url,
                started_at,
                ended_at,
            })
        })
    }

    fn set_room_closed(&self, id: i64, ended_at: &str) {
        let _ = ended_at; // 真实时钟走 MySQL NOW()；占位时钟仅供 InMemory 测试
        let opts = self.opts.clone();
        self.rt.block_on(async move {
            let Ok(mut conn) = Conn::new(opts).await else { return };
            let _ = conn
                .exec_drop(
                    "UPDATE social_live_rooms SET status = 0, ended_at = NOW(), updated_at = NOW() WHERE id = ?",
                    Params::from((id,)),
                )
                .await;
        });
    }

    fn list_rooms(&self, offset: u64, limit: u64) -> Vec<RoomRow> {
        let opts = self.opts.clone();
        self.rt.block_on(async move {
            let Ok(mut conn) = Conn::new(opts).await else { return Vec::new() };
            conn.exec::<(i64, i64, String, i32, String, String, Option<String>, Option<String>), _, _>(
                "SELECT id, owner_id, title, status, push_url, play_url, DATE_FORMAT(started_at, '%Y-%m-%d %H:%i:%s'), DATE_FORMAT(ended_at, '%Y-%m-%d %H:%i:%s') FROM social_live_rooms WHERE status = 1 ORDER BY started_at DESC LIMIT ? OFFSET ?",
                Params::from((limit, offset)),
            )
            .await
            .unwrap_or_default()
            .into_iter()
            .map(|(id, owner_id, title, status, push_url, play_url, started_at, ended_at)| RoomRow {
                id,
                owner_id,
                title,
                status,
                push_url,
                play_url,
                started_at,
                ended_at,
            })
            .collect()
        })
    }

    fn sadd(&self, key: &str, member: i64) {
        self.redis.sadd(key, member)
    }
    fn srem(&self, key: &str, member: i64) {
        self.redis.srem(key, member)
    }
    fn smembers(&self, key: &str) -> Vec<i64> {
        self.redis.smembers(key)
    }
    fn scard(&self, key: &str) -> u64 {
        self.redis.scard(key)
    }
    fn del(&self, key: &str) {
        self.redis.del(key)
    }
    fn danmaku_push(&self, key: &str, msg_json: &str, keep: usize) {
        self.redis.danmaku_push(key, msg_json, keep)
    }
    fn danmaku_range(&self, key: &str, limit: usize) -> Vec<String> {
        self.redis.danmaku_range(key, limit)
    }
}

/// 语聊房存储：MySQL social_voice_rooms + social_voice_room_members + Redis 在线集合。
pub struct MySqlRoomStore {
    opts: Opts,
    rt: tokio::runtime::Runtime,
    redis: RedisConn,
}

impl MySqlRoomStore {
    pub fn from_env() -> Option<Self> {
        Some(Self {
            opts: mysql_opts(),
            rt: tokio::runtime::Runtime::new().ok()?,
            redis: RedisConn::from_env()?,
        })
    }
}

impl RoomStore for MySqlRoomStore {
    fn create_room(&self, owner: i64, name: &str) -> i64 {
        let opts = self.opts.clone();
        let name = name.to_string();
        let id = id_generator().next_id();
        self.rt.block_on(async move {
            let Ok(mut conn) = Conn::new(opts).await else { return 0 };
            if conn
                .exec_drop(
                    "INSERT INTO social_voice_rooms (id, owner_id, name, status, created_at, updated_at) VALUES (?, ?, ?, 1, NOW(), NOW())",
                    Params::from((id as i64, owner, name.as_str())),
                )
                .await
                .is_err()
            {
                return 0;
            }
            id as i64
        })
    }

    fn find_room(&self, id: i64) -> Option<VoiceRoomRow> {
        let opts = self.opts.clone();
        self.rt.block_on(async move {
            let mut conn = Conn::new(opts).await.ok()?;
            conn.exec_first::<(i64, i64, String, i32), _, _>("SELECT id, owner_id, name, status FROM social_voice_rooms WHERE id = ?", Params::from((id,)))
                .await
                .ok()
                .flatten()
                .map(|(id, owner_id, name, status)| VoiceRoomRow { id, owner_id, name, status })
        })
    }

    fn set_room_closed(&self, id: i64) {
        let opts = self.opts.clone();
        self.rt.block_on(async move {
            let Ok(mut conn) = Conn::new(opts).await else { return };
            let _ = conn
                .exec_drop("UPDATE social_voice_rooms SET status = 0, updated_at = NOW() WHERE id = ?", Params::from((id,)))
                .await;
        });
    }

    fn list_rooms(&self, offset: u64, limit: u64) -> Vec<VoiceRoomRow> {
        let opts = self.opts.clone();
        self.rt.block_on(async move {
            let Ok(mut conn) = Conn::new(opts).await else { return Vec::new() };
            conn.exec::<(i64, i64, String, i32), _, _>(
                "SELECT id, owner_id, name, status FROM social_voice_rooms WHERE status = 1 ORDER BY updated_at DESC LIMIT ? OFFSET ?",
                Params::from((limit, offset)),
            )
            .await
            .unwrap_or_default()
            .into_iter()
            .map(|(id, owner_id, name, status)| VoiceRoomRow { id, owner_id, name, status })
            .collect()
        })
    }

    fn member_role(&self, room_id: i64, uid: i64) -> Option<i32> {
        let opts = self.opts.clone();
        self.rt.block_on(async move {
            let mut conn = Conn::new(opts).await.ok()?;
            conn.exec_first::<i32, _, _>("SELECT role FROM social_voice_room_members WHERE room_id = ? AND user_id = ?", Params::from((room_id, uid)))
                .await
                .ok()
                .flatten()
        })
    }

    fn insert_member(&self, room_id: i64, uid: i64, role: i32) {
        let opts = self.opts.clone();
        let id = id_generator().next_id();
        self.rt.block_on(async move {
            let Ok(mut conn) = Conn::new(opts).await else { return };
            // firstOrCreate 语义：唯一键冲突时保留原 role 不覆盖
            let _ = conn
                .exec_drop(
                    "INSERT INTO social_voice_room_members (id, room_id, user_id, role, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW()) ON DUPLICATE KEY UPDATE role = role",
                    Params::from((id as i64, room_id, uid, role)),
                )
                .await;
        });
    }

    fn set_member_role(&self, room_id: i64, uid: i64, role: i32) {
        let opts = self.opts.clone();
        self.rt.block_on(async move {
            let Ok(mut conn) = Conn::new(opts).await else { return };
            let _ = conn
                .exec_drop(
                    "UPDATE social_voice_room_members SET role = ?, updated_at = NOW() WHERE room_id = ? AND user_id = ?",
                    Params::from((role, room_id, uid)),
                )
                .await;
        });
    }

    fn delete_member(&self, room_id: i64, uid: i64) {
        let opts = self.opts.clone();
        self.rt.block_on(async move {
            let Ok(mut conn) = Conn::new(opts).await else { return };
            let _ = conn.exec_drop("DELETE FROM social_voice_room_members WHERE room_id = ? AND user_id = ?", Params::from((room_id, uid))).await;
        });
    }

    fn delete_room_members(&self, room_id: i64) {
        let opts = self.opts.clone();
        self.rt.block_on(async move {
            let Ok(mut conn) = Conn::new(opts).await else { return };
            let _ = conn.exec_drop("DELETE FROM social_voice_room_members WHERE room_id = ?", Params::from((room_id,))).await;
        });
    }

    fn member_uids(&self, room_id: i64) -> Vec<i64> {
        let opts = self.opts.clone();
        self.rt.block_on(async move {
            let Ok(mut conn) = Conn::new(opts).await else { return Vec::new() };
            conn.exec::<i64, _, _>("SELECT user_id FROM social_voice_room_members WHERE room_id = ?", Params::from((room_id,)))
                .await
                .unwrap_or_default()
        })
    }

    fn role_count(&self, room_id: i64, role: i32) -> u64 {
        let opts = self.opts.clone();
        self.rt.block_on(async move {
            let Ok(mut conn) = Conn::new(opts).await else { return 0 };
            conn.exec_first::<u64, _, _>("SELECT COUNT(*) FROM social_voice_room_members WHERE room_id = ? AND role = ?", Params::from((room_id, role)))
                .await
                .ok()
                .flatten()
                .unwrap_or(0)
        })
    }

    fn sadd(&self, key: &str, member: i64) {
        self.redis.sadd(key, member)
    }
    fn srem(&self, key: &str, member: i64) {
        self.redis.srem(key, member)
    }
    fn smembers(&self, key: &str) -> Vec<i64> {
        self.redis.smembers(key)
    }
    fn scard(&self, key: &str) -> u64 {
        self.redis.scard(key)
    }
    fn del(&self, key: &str) {
        self.redis.del(key)
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn it_enabled() -> bool {
        std::env::var("LIVE_IT").is_ok()
    }

    #[test]
    fn mysql_live_store_roundtrip() {
        if !it_enabled() {
            eprintln!("skipped: LIVE_IT 未设置（需本机 MySQL + Redis）");
            return;
        }
        let Some(s) = MySqlLiveStore::from_env() else {
            eprintln!("skipped: Redis 不可用");
            return;
        };
        let id = s.create_room(999001, "it-live");
        assert!(id > 0, "create_room 失败（MySQL 不可用？）");
        let row = s.find_room(id).expect("room 应可查");
        assert_eq!(row.title, "it-live");
        assert_eq!(row.status, 1);
        s.set_room_urls(id, "rtmp://127.0.0.1/live/1", "http://127.0.0.1/hls/1.m3u8");
        assert_eq!(s.find_room(id).unwrap().push_url, "rtmp://127.0.0.1/live/1");
        let online = "social:it:live:online";
        s.sadd(online, 999001);
        s.sadd(online, 999002);
        assert_eq!(s.scard(online), 2);
        assert_eq!(s.smembers(online).len(), 2);
        let dk = "social:it:live:danmaku";
        s.danmaku_push(dk, "{\"n\":1}", 5);
        s.danmaku_push(dk, "{\"n\":2}", 5);
        assert_eq!(s.danmaku_range(dk, 5).len(), 2);
        s.set_room_closed(id, "1970-01-01 00:00:00");
        assert_eq!(s.find_room(id).unwrap().status, 0);
        s.del(online);
        s.del(dk);
        let opts = s.opts.clone();
        s.rt.block_on(async move {
            if let Ok(mut c) = Conn::new(opts).await {
                let _ = c.exec_drop("DELETE FROM social_live_rooms WHERE id = ?", Params::from((id,))).await;
            }
        });
    }

    #[test]
    fn mysql_voice_store_roundtrip() {
        if !it_enabled() {
            eprintln!("skipped: LIVE_IT 未设置（需本机 MySQL + Redis）");
            return;
        }
        let Some(s) = MySqlRoomStore::from_env() else {
            eprintln!("skipped: Redis 不可用");
            return;
        };
        let room_id = s.create_room(999001, "it-voice");
        assert!(room_id > 0, "create_room 失败（MySQL 不可用？）");
        s.insert_member(room_id, 999001, 1);
        s.insert_member(room_id, 999002, 0);
        s.insert_member(room_id, 999001, 1); // firstOrCreate：重复插入保留原 role
        assert_eq!(s.member_role(room_id, 999001), Some(1));
        assert_eq!(s.role_count(room_id, 1), 1);
        assert_eq!(s.member_uids(room_id).len(), 2);
        let online = format!("social:it:room:{}:online", room_id);
        s.sadd(&online, 999002);
        assert!(s.smembers(&online).contains(&999002));
        s.set_member_role(room_id, 999002, 1);
        assert_eq!(s.role_count(room_id, 1), 2);
        s.delete_member(room_id, 999002);
        assert_eq!(s.member_uids(room_id).len(), 1);
        s.set_room_closed(room_id);
        assert_eq!(s.find_room(room_id).unwrap().status, 0);
        s.del(&online);
        let opts = s.opts.clone();
        s.rt.block_on(async move {
            if let Ok(mut c) = Conn::new(opts).await {
                let _ = c.exec_drop("DELETE FROM social_voice_rooms WHERE id = ?", Params::from((room_id,))).await;
                let _ = c.exec_drop("DELETE FROM social_voice_room_members WHERE room_id = ?", Params::from((room_id,))).await;
            }
        });
    }
}
