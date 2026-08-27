// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
//! 直播状态机，移植自 PHP `app\live\LiveCenter`。
//! DB（live_rooms.status）为事实；Redis 承载在线集合/弹幕/麦位实时态，关播即销毁。
//! 存储经 `LiveStore` trait 抽象：骨架带 `InMemoryLiveStore`（测试/原型），
//! Redis + MySQL 实现为第二批（键名与命令序列与本 trait 契约一致）。

use crate::protocol::{Envelope, T_DANMAKU, T_LIVE_CLOSED, T_LIVE_JOIN, T_LIVE_LEAVE, T_LIVE_MIC_DOWN, T_LIVE_MIC_UP};
use serde_json::json;
use std::collections::{HashMap, HashSet, VecDeque};
use std::sync::atomic::{AtomicI64, Ordering};
use std::sync::Mutex;
use thiserror::Error;

pub const MIC_LIMIT_DEFAULT: usize = 8;
pub const DANMAKU_KEEP_DEFAULT: usize = 200;

#[derive(Debug, Error, PartialEq, Eq)]
pub enum LiveError {
    #[error("live_room_not_found")]
    RoomNotFound,
    #[error("live_room_forbidden")]
    Forbidden,
    #[error("live_mic_full")]
    MicFull,
}

#[derive(Debug, Clone, Default)]
pub struct RoomRow {
    pub id: i64,
    pub owner_id: i64,
    pub title: String,
    pub status: i32,
    pub push_url: String,
    pub play_url: String,
    pub started_at: Option<String>,
    pub ended_at: Option<String>,
}

/// 存储契约：键名与命令序列与 PHP 版逐字节一致。
pub trait LiveStore: Send + Sync {
    fn create_room(&self, owner: i64, title: &str) -> i64;
    fn set_room_urls(&self, id: i64, push_url: &str, play_url: &str);
    fn find_room(&self, id: i64) -> Option<RoomRow>;
    fn set_room_closed(&self, id: i64, ended_at: &str);
    fn sadd(&self, key: &str, member: i64);
    fn srem(&self, key: &str, member: i64);
    fn smembers(&self, key: &str) -> Vec<i64>;
    fn scard(&self, key: &str) -> u64;
    fn del(&self, key: &str);
    fn danmaku_push(&self, key: &str, msg_json: &str, keep: usize);
    fn danmaku_range(&self, key: &str, limit: usize) -> Vec<String>;
}

#[derive(Debug, Clone)]
pub struct LiveConfig {
    pub rtmp_host: String,
    pub hls_host: String,
    pub mic_limit: usize,
    pub danmaku_keep: usize,
}

impl LiveConfig {
    pub fn from_env() -> Self {
        Self {
            rtmp_host: std::env::var("LIVE_RTMP_HOST").unwrap_or_else(|_| "127.0.0.1".into()),
            hls_host: std::env::var("LIVE_HLS_HOST").unwrap_or_else(|_| "127.0.0.1".into()),
            mic_limit: std::env::var("LIVE_MIC_LIMIT").ok().and_then(|v| v.parse().ok()).unwrap_or(MIC_LIMIT_DEFAULT),
            danmaku_keep: std::env::var("LIVE_DANMAKU_KEEP").ok().and_then(|v| v.parse().ok()).unwrap_or(DANMAKU_KEEP_DEFAULT),
        }
    }
}

pub fn sign_push_url(room_id: i64, rtmp_host: &str) -> String {
    format!("rtmp://{}/live/{}", rtmp_host, room_id)
}

pub fn sign_play_url(room_id: i64, hls_host: &str) -> String {
    format!("http://{}/hls/{}.m3u8", hls_host, room_id)
}

type SendFn = Box<dyn Fn(i64, String) + Send + Sync>;

/// 直播状态机（无 IO）。`send` 注入投递回调：生产路径做本机直推或
/// rpush `social:live:broadcast`（PHP WS worker 消费），协议见 `protocol::Envelope`。
pub struct LiveCenter<S: LiveStore> {
    store: S,
    cfg: LiveConfig,
    send: SendFn,
}

impl<S: LiveStore> LiveCenter<S> {
    pub fn new(store: S, cfg: LiveConfig, send: SendFn) -> Self {
        Self { store, cfg, send }
    }

    pub fn create(&self, owner: i64, title: &str) -> i64 {
        let id = self.store.create_room(owner, title);
        let push = sign_push_url(id, &self.cfg.rtmp_host);
        let play = sign_play_url(id, &self.cfg.hls_host);
        self.store.set_room_urls(id, &push, &play);
        // 开播瞬间房主 WS 未连（REST 触发），join 不广播——否则入跨进程队列成幽灵帧
        let _ = self.join(id, owner, false);
        id
    }

    pub fn find_room(&self, id: i64) -> Option<RoomRow> {
        self.store.find_room(id)
    }

    pub fn join(&self, room_id: i64, uid: i64, broadcast: bool) -> Result<(), LiveError> {
        let room = self.store.find_room(room_id).ok_or(LiveError::RoomNotFound)?;
        if room.status != 1 {
            return Err(LiveError::RoomNotFound);
        }
        self.store.sadd(&self.online_key(room_id), uid);
        self.store.sadd(&self.user_key(uid), room_id);
        // TOCTOU：sadd 后复核——close 并发在复核前删键则回滚，避免在线键复活
        let room = self.store.find_room(room_id).ok_or(LiveError::RoomNotFound)?;
        if room.status != 1 {
            self.store.srem(&self.online_key(room_id), uid);
            self.store.srem(&self.user_key(uid), room_id);
            return Err(LiveError::RoomNotFound);
        }
        if broadcast {
            self.broadcast(room_id, T_LIVE_JOIN, json!({"room_id": room_id, "user_id": uid}));
        }
        Ok(())
    }

    pub fn leave(&self, room_id: i64, uid: i64) {
        self.store.srem(&self.online_key(room_id), uid);
        self.store.srem(&self.user_key(uid), room_id);
        self.store.srem(&self.mic_key(room_id), uid);
        self.broadcast(room_id, T_LIVE_LEAVE, json!({"room_id": room_id, "user_id": uid}));
    }

    pub fn close(&self, room_id: i64, owner_uid: i64) -> Result<(), LiveError> {
        let room = self.store.find_room(room_id).ok_or(LiveError::RoomNotFound)?;
        if room.owner_id != owner_uid {
            return Err(LiveError::Forbidden);
        }
        if room.status != 1 {
            return Err(LiveError::RoomNotFound);
        }
        self.store.set_room_closed(room_id, &now());
        // 先广播后删键：broadcast 依赖在线集合拿接收者
        self.broadcast(room_id, T_LIVE_CLOSED, json!({"room_id": room_id}));
        for uid in self.store.smembers(&self.online_key(room_id)) {
            self.store.srem(&self.user_key(uid), room_id);
        }
        self.store.del(&self.online_key(room_id));
        self.store.del(&self.danmaku_key(room_id));
        self.store.del(&self.mic_key(room_id));
        Ok(())
    }

    pub fn send_danmaku(&self, room_id: i64, uid: i64, content: &str) -> Result<serde_json::Value, LiveError> {
        let room = self.store.find_room(room_id).ok_or(LiveError::RoomNotFound)?;
        if room.status != 1 {
            return Err(LiveError::RoomNotFound);
        }
        let msg = json!({"room_id": room_id, "user_id": uid, "nickname": "", "content": content});
        self.store.danmaku_push(&self.danmaku_key(room_id), &msg.to_string(), self.cfg.danmaku_keep);
        self.broadcast(room_id, T_DANMAKU, msg.clone());
        Ok(msg)
    }

    pub fn mic_up(&self, room_id: i64, uid: i64) -> Result<(), LiveError> {
        let room = self.store.find_room(room_id).ok_or(LiveError::RoomNotFound)?;
        if room.status != 1 {
            return Err(LiveError::RoomNotFound);
        }
        if self.mic_count(room_id) >= self.cfg.mic_limit as u64 {
            return Err(LiveError::MicFull);
        }
        self.store.sadd(&self.mic_key(room_id), uid);
        self.broadcast(room_id, T_LIVE_MIC_UP, json!({"room_id": room_id, "user_id": uid}));
        Ok(())
    }

    pub fn mic_down(&self, room_id: i64, uid: i64) {
        self.store.srem(&self.mic_key(room_id), uid);
        self.broadcast(room_id, T_LIVE_MIC_DOWN, json!({"room_id": room_id, "user_id": uid}));
    }

    pub fn mic_count(&self, room_id: i64) -> u64 {
        self.store.scard(&self.mic_key(room_id))
    }

    pub fn online_count(&self, room_id: i64) -> u64 {
        self.store.scard(&self.online_key(room_id))
    }

    /// 倒序还原时间序（PHP: lRange 后 array_reverse）
    pub fn recent_danmaku(&self, room_id: i64, limit: usize) -> Vec<serde_json::Value> {
        self.store
            .danmaku_range(&self.danmaku_key(room_id), limit)
            .into_iter()
            .rev()
            .map(|s| serde_json::from_str(&s).unwrap_or(json!({})))
            .collect()
    }

    pub fn mic_users(&self, room_id: i64) -> Vec<i64> {
        self.store.smembers(&self.mic_key(room_id))
    }

    /// 断线/登出清理：连接断开路径统一调用
    pub fn on_disconnect(&self, uid: i64) {
        for room_id in self.store.smembers(&self.user_key(uid)) {
            self.leave(room_id, uid);
        }
        self.store.del(&self.user_key(uid));
    }

    fn broadcast(&self, room_id: i64, ty: &str, data: serde_json::Value) {
        let payload = Envelope::frame(ty, data).encode();
        for uid in self.store.smembers(&self.online_key(room_id)) {
            (self.send)(uid, payload.clone());
        }
    }

    fn online_key(&self, room_id: i64) -> String {
        format!("live:room:{}:online", room_id)
    }
    fn danmaku_key(&self, room_id: i64) -> String {
        format!("live:room:{}:danmaku", room_id)
    }
    fn mic_key(&self, room_id: i64) -> String {
        format!("live:room:{}:mic", room_id)
    }
    fn user_key(&self, uid: i64) -> String {
        format!("live:roomuser:{}", uid)
    }
}

fn now() -> String {
    // ponytail: 内存态占位时钟（与 PHP date('Y-m-d H:i:s') 同构）；Redis/MySQL 批改用真实时钟
    std::time::SystemTime::now()
        .duration_since(std::time::UNIX_EPOCH)
        .map(|d| format!("1970-01-01 00:00:{}", d.as_secs() % 60))
        .unwrap_or_else(|_| "1970-01-01 00:00:00".into())
}

/// 骨架内存实现：键空间与 Redis 一致，供测试/原型使用。
#[derive(Default)]
pub struct InMemoryLiveStore {
    rooms: Mutex<HashMap<i64, RoomRow>>,
    sets: Mutex<HashMap<String, HashSet<i64>>>,
    danmaku: Mutex<HashMap<String, VecDeque<String>>>,
    next_id: AtomicI64,
}

impl InMemoryLiveStore {
    pub fn new() -> Self {
        Self::default()
    }
}

impl LiveStore for InMemoryLiveStore {
    fn create_room(&self, owner: i64, title: &str) -> i64 {
        let id = self.next_id.fetch_add(1, Ordering::SeqCst);
        let mut rooms = self.rooms.lock().unwrap();
        rooms.insert(id, RoomRow { id, owner_id: owner, title: title.into(), status: 1, ..Default::default() });
        id
    }

    fn set_room_urls(&self, id: i64, push_url: &str, play_url: &str) {
        if let Some(r) = self.rooms.lock().unwrap().get_mut(&id) {
            r.push_url = push_url.into();
            r.play_url = play_url.into();
        }
    }

    fn find_room(&self, id: i64) -> Option<RoomRow> {
        self.rooms.lock().unwrap().get(&id).cloned()
    }

    fn set_room_closed(&self, id: i64, ended_at: &str) {
        if let Some(r) = self.rooms.lock().unwrap().get_mut(&id) {
            r.status = 0;
            r.ended_at = Some(ended_at.into());
        }
    }

    fn sadd(&self, key: &str, member: i64) {
        self.sets.lock().unwrap().entry(key.into()).or_default().insert(member);
    }

    fn srem(&self, key: &str, member: i64) {
        if let Some(s) = self.sets.lock().unwrap().get_mut(key) {
            s.remove(&member);
        }
    }

    fn smembers(&self, key: &str) -> Vec<i64> {
        self.sets.lock().unwrap().get(key).map(|s| s.iter().copied().collect()).unwrap_or_default()
    }

    fn scard(&self, key: &str) -> u64 {
        self.sets.lock().unwrap().get(key).map(|s| s.len() as u64).unwrap_or(0)
    }

    fn del(&self, key: &str) {
        self.sets.lock().unwrap().remove(key);
        self.danmaku.lock().unwrap().remove(key);
    }

    fn danmaku_push(&self, key: &str, msg_json: &str, keep: usize) {
        let mut m = self.danmaku.lock().unwrap();
        let q = m.entry(key.into()).or_insert_with(VecDeque::new);
        q.push_front(msg_json.to_string());
        while q.len() > keep {
            q.pop_back();
        }
    }

    fn danmaku_range(&self, key: &str, limit: usize) -> Vec<String> {
        self.danmaku.lock().unwrap().get(key).map(|q| q.iter().take(limit).cloned().collect()).unwrap_or_default()
    }
}
