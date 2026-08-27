// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
//! 语聊房状态机，移植自 PHP `app\room\RoomCenter`。
//! DB 即状态（voice_rooms.status + voice_room_members.role）；Redis 仅缓存在线成员
//! uid 集合 `im:room:{id}:online` 用于推帧。存储经 `RoomStore` trait 抽象。

use crate::protocol::{Envelope, T_ROOM_ANSWER, T_ROOM_CLOSED, T_ROOM_DOWN_MIC, T_ROOM_JOIN, T_ROOM_KICK_MIC, T_ROOM_LEAVE, T_ROOM_OFFER, T_ROOM_UP_MIC};
use serde_json::{json, Value};
use std::collections::{HashMap, HashSet};
use std::sync::atomic::{AtomicI64, Ordering};
use std::sync::Mutex;
use thiserror::Error;

pub const MIC_LIMIT: usize = 8; // 1 房主 + 7 麦位

#[derive(Debug, Error, PartialEq, Eq)]
pub enum RoomError {
    #[error("room_not_found")]
    RoomNotFound,
    #[error("room_forbidden")]
    Forbidden,
    #[error("room_mic_full")]
    MicFull,
}

#[derive(Debug, Clone, Default)]
pub struct VoiceRoomRow {
    pub id: i64,
    pub owner_id: i64,
    pub name: String,
    pub status: i32,
}

/// 存储契约：键名与命令序列与 PHP 版逐字节一致。
pub trait RoomStore: Send + Sync {
    fn create_room(&self, owner: i64, name: &str) -> i64;
    fn find_room(&self, id: i64) -> Option<VoiceRoomRow>;
    fn list_rooms(&self, offset: u64, limit: u64) -> Vec<VoiceRoomRow>;
    fn set_room_closed(&self, id: i64);
    fn member_role(&self, room_id: i64, uid: i64) -> Option<i32>;
    fn insert_member(&self, room_id: i64, uid: i64, role: i32);
    fn set_member_role(&self, room_id: i64, uid: i64, role: i32);
    fn delete_member(&self, room_id: i64, uid: i64);
    fn delete_room_members(&self, room_id: i64);
    fn member_uids(&self, room_id: i64) -> Vec<i64>;
    fn role_count(&self, room_id: i64, role: i32) -> u64;
    fn sadd(&self, key: &str, member: i64);
    fn srem(&self, key: &str, member: i64);
    fn smembers(&self, key: &str) -> Vec<i64>;
    fn scard(&self, key: &str) -> u64;
    fn del(&self, key: &str);
}

type SendFn = Box<dyn Fn(i64, String) + Send + Sync>;
type SfuFn = Box<dyn Fn(i64, String, Value) -> Value + Send + Sync>;

/// 语聊房状态机（无 IO；SFU 调用经注入 callable，默认实现见 [`sfu_http`]）。
pub struct RoomCenter<S: RoomStore> {
    store: S,
    send: SendFn,
    sfu: SfuFn,
}

impl<S: RoomStore> RoomCenter<S> {
    pub fn new(store: S, send: SendFn, sfu: SfuFn) -> Self {
        Self { store, send, sfu }
    }

    pub fn create(&self, owner: i64, name: &str) -> i64 {
        let room_id = self.store.create_room(owner, name);
        let _ = self.join(room_id, owner); // PHP create 同样忽略 join 异常
        room_id
    }

    pub fn join(&self, room_id: i64, uid: i64) -> Result<(), RoomError> {
        let room = self.store.find_room(room_id).ok_or(RoomError::RoomNotFound)?;
        if room.status != 1 {
            return Err(RoomError::RoomNotFound);
        }
        // firstOrCreate 语义：不存在才插入，已存在保留原 role
        if self.store.member_role(room_id, uid).is_none() {
            let role = if room.owner_id == uid { 1 } else { 0 };
            self.store.insert_member(room_id, uid, role);
        }
        self.store.sadd(&self.online_key(room_id), uid);
        self.store.sadd(&self.user_key(uid), room_id);
        // TOCTOU：sadd 后复核——close 并发在复核前删键则回滚
        let room = self.store.find_room(room_id).ok_or(RoomError::RoomNotFound)?;
        if room.status != 1 {
            self.store.delete_member(room_id, uid);
            self.store.srem(&self.online_key(room_id), uid);
            self.store.srem(&self.user_key(uid), room_id);
            return Err(RoomError::RoomNotFound);
        }
        self.broadcast(room_id, T_ROOM_JOIN, json!({"room_id": room_id, "user_id": uid}));
        Ok(())
    }

    pub fn leave(&self, room_id: i64, uid: i64) {
        if self.store.member_role(room_id, uid).is_none() {
            return;
        }
        self.store.delete_member(room_id, uid);
        self.store.srem(&self.online_key(room_id), uid);
        self.store.srem(&self.user_key(uid), room_id);
        if let Some(room) = self.store.find_room(room_id) {
            if room.owner_id == uid {
                let _ = self.close(room_id, uid);
                return;
            }
        }
        self.broadcast(room_id, T_ROOM_LEAVE, json!({"room_id": room_id, "user_id": uid}));
    }

    pub fn close(&self, room_id: i64, owner_uid: i64) -> Result<(), RoomError> {
        let room = self.store.find_room(room_id).ok_or(RoomError::RoomNotFound)?;
        if room.owner_id != owner_uid {
            return Err(RoomError::Forbidden);
        }
        self.store.set_room_closed(room_id);
        for uid in self.store.member_uids(room_id) {
            self.store.srem(&self.user_key(uid), room_id);
        }
        self.store.delete_room_members(room_id);
        // 先广播后删在线集合：broadcast 依赖该集合拿接收者
        self.broadcast(room_id, T_ROOM_CLOSED, json!({"room_id": room_id}));
        self.store.del(&self.online_key(room_id));
        Ok(())
    }

    pub fn up_mic(&self, room_id: i64, uid: i64) -> Result<(), RoomError> {
        if self.store.member_role(room_id, uid).is_none() {
            return Ok(());
        }
        if self.mic_count(room_id) >= MIC_LIMIT as u64 {
            return Err(RoomError::MicFull);
        }
        self.store.set_member_role(room_id, uid, 1);
        self.broadcast(room_id, T_ROOM_UP_MIC, json!({"room_id": room_id, "user_id": uid}));
        Ok(())
    }

    pub fn down_mic(&self, room_id: i64, uid: i64) {
        if self.store.member_role(room_id, uid).is_none() {
            return;
        }
        self.store.set_member_role(room_id, uid, 0);
        self.broadcast(room_id, T_ROOM_DOWN_MIC, json!({"room_id": room_id, "user_id": uid}));
    }

    pub fn kick_mic(&self, room_id: i64, owner_uid: i64, target_uid: i64) -> Result<(), RoomError> {
        let room = self.store.find_room(room_id).ok_or(RoomError::RoomNotFound)?;
        if room.owner_id != owner_uid {
            return Err(RoomError::Forbidden);
        }
        self.store.set_member_role(room_id, target_uid, 0);
        let kick = Envelope::frame(T_ROOM_KICK_MIC, json!({"room_id": room_id, "user_id": target_uid})).encode();
        (self.send)(target_uid, kick);
        self.broadcast(room_id, T_ROOM_DOWN_MIC, json!({"room_id": room_id, "user_id": target_uid}));
        Ok(())
    }

    /// SFU 信令转发：room_offer/answer/ice → media/sfu HTTP → 结果回推发送者（对齐 PHP sfuRelay）
    pub fn sfu_relay(&self, room_id: i64, uid: i64, frame_type: &str, data: Value) {
        let method = match frame_type {
            T_ROOM_OFFER => "produce",
            T_ROOM_ANSWER => "connect",
            _ => "transport",
        };
        let mut out = json!({"room_id": room_id});
        if let Value::Object(resp) = (self.sfu)(room_id, method.into(), data) {
            if let Value::Object(m) = &mut out {
                for (k, v) in resp {
                    m.entry(k).or_insert(v); // PHP 数组合并 `+`：左侧 room_id 优先
                }
            }
        }
        (self.send)(uid, Envelope::frame(frame_type, out).encode());
    }

    /// 麦位计数以 DB role=1 为准（PHP: VoiceRoomMember count）
    pub fn mic_count(&self, room_id: i64) -> u64 {
        self.store.role_count(room_id, 1)
    }

    pub fn find_room(&self, id: i64) -> Option<VoiceRoomRow> {
        self.store.find_room(id)
    }

    /// 开放房间列表（status=1），PHP: `where('status', 1)->forPage($page, 20)`。
    pub fn list_rooms(&self, offset: u64, limit: u64) -> Vec<VoiceRoomRow> {
        self.store.list_rooms(offset, limit)
    }

    /// 在线人数（PHP: `scard('im:room:{id}:online')`）
    pub fn online_count(&self, room_id: i64) -> u64 {
        self.store.scard(&self.online_key(room_id))
    }

    /// 成员列表 (uid, role)，对齐 PHP VoiceRoomMember::where(room_id)->orderByDesc('role')
    pub fn members(&self, room_id: i64) -> Vec<(i64, i32)> {
        let mut v: Vec<(i64, i32)> = self
            .store
            .member_uids(room_id)
            .into_iter()
            .filter_map(|uid| self.store.member_role(room_id, uid).map(|role| (uid, role)))
            .collect();
        v.sort_by_key(|(_, role)| std::cmp::Reverse(*role)); // 房主在前
        v
    }

    pub fn status(&self, room_id: i64) -> i32 {
        self.store.find_room(room_id).map(|r| r.status).unwrap_or(0)
    }

    /// WS 掉线 → 离开其所在全部房间（房主掉线即关房）
    pub fn on_disconnect(&self, uid: i64) {
        for room_id in self.store.smembers(&self.user_key(uid)) {
            self.leave(room_id, uid);
        }
    }

    fn broadcast(&self, room_id: i64, ty: &str, data: serde_json::Value) {
        let payload = Envelope::frame(ty, data).encode();
        for uid in self.store.smembers(&self.online_key(room_id)) {
            (self.send)(uid, payload.clone());
        }
    }

    fn online_key(&self, room_id: i64) -> String {
        format!("im:room:{}:online", room_id)
    }
    fn user_key(&self, uid: i64) -> String {
        format!("im:roomuser:{}", uid)
    }
}

/// 裸 TCP POST → media/sfu（env SFU_URL，默认 127.0.0.1:8790），对齐 PHP RoomCenter::sfuHttp。
/// ponytail: 同步阻塞（2s 连接 + 3s 读超时 + 1MB 上限）；接 WS worker 时改异步
pub fn sfu_http(room_id: i64, method: String, body: Value) -> Value {
    use std::io::{Read, Write};
    use std::net::TcpStream;
    use std::time::Duration;

    let default: std::net::SocketAddr = "127.0.0.1:8790".parse().unwrap();
    let saddr = std::env::var("SFU_URL").ok().and_then(|v| v.parse().ok()).unwrap_or(default);
    let Ok(mut sock) = TcpStream::connect_timeout(&saddr, Duration::from_secs(2)) else {
        return json!({}); // PHP 抛 sfu_unreachable；Rust 回空帧，错误语义在 WS worker 层定
    };
    let _ = sock.set_read_timeout(Some(Duration::from_secs(3)));
    let mut req_body = json!({"room_id": room_id, "method": method});
    if let Value::Object(b) = body {
        if let Value::Object(m) = &mut req_body {
            for (k, v) in b {
                m.entry(k).or_insert(v); // PHP `+`：room_id/method 优先
            }
        }
    }
    let payload = req_body.to_string();
    let req = format!(
        "POST /signal HTTP/1.1\r\nHost: sfu\r\nContent-Type: application/json\r\nContent-Length: {}\r\nConnection: close\r\n\r\n{}",
        payload.len(),
        payload
    );
    let _ = sock.write_all(req.as_bytes());
    let mut resp = Vec::new();
    let mut buf = [0u8; 8192];
    loop {
        match sock.read(&mut buf) {
            Ok(0) => break,
            Ok(n) => {
                resp.extend_from_slice(&buf[..n]);
                if resp.len() > 1_000_000 {
                    break;
                }
            }
            Err(_) => break,
        }
    }
    let json_part = String::from_utf8_lossy(&resp).split_once("\r\n\r\n").map(|(_, b)| b.to_string()).unwrap_or_default();
    serde_json::from_str(&json_part).unwrap_or_else(|_| json!({}))
}

/// 骨架内存实现：成员表 + 在线集合，键空间与 Redis 一致。
#[derive(Default)]
pub struct InMemoryRoomStore {
    rooms: Mutex<HashMap<i64, VoiceRoomRow>>,
    members: Mutex<HashMap<(i64, i64), i32>>, // (room_id, uid) → role
    sets: Mutex<HashMap<String, HashSet<i64>>>,
    next_id: AtomicI64,
}

impl InMemoryRoomStore {
    pub fn new() -> Self {
        Self::default()
    }
}

impl RoomStore for InMemoryRoomStore {
    fn create_room(&self, owner: i64, name: &str) -> i64 {
        let id = self.next_id.fetch_add(1, Ordering::SeqCst);
        self.rooms.lock().unwrap().insert(id, VoiceRoomRow { id, owner_id: owner, name: name.into(), status: 1 });
        id
    }

    fn find_room(&self, id: i64) -> Option<VoiceRoomRow> {
        self.rooms.lock().unwrap().get(&id).cloned()
    }

    fn set_room_closed(&self, id: i64) {
        if let Some(r) = self.rooms.lock().unwrap().get_mut(&id) {
            r.status = 0;
        }
    }

    fn list_rooms(&self, offset: u64, limit: u64) -> Vec<VoiceRoomRow> {
        let mut v: Vec<VoiceRoomRow> = self
            .rooms
            .lock()
            .unwrap()
            .values()
            .filter(|r| r.status == 1)
            .cloned()
            .collect();
        v.sort_by_key(|r| std::cmp::Reverse(r.id)); // 新创建在前（对齐 updated_at DESC）
        v.into_iter().skip(offset as usize).take(limit as usize).collect()
    }

    fn member_role(&self, room_id: i64, uid: i64) -> Option<i32> {
        self.members.lock().unwrap().get(&(room_id, uid)).copied()
    }

    fn insert_member(&self, room_id: i64, uid: i64, role: i32) {
        self.members.lock().unwrap().insert((room_id, uid), role);
    }

    fn set_member_role(&self, room_id: i64, uid: i64, role: i32) {
        if let Some(r) = self.members.lock().unwrap().get_mut(&(room_id, uid)) {
            *r = role;
        }
    }

    fn delete_member(&self, room_id: i64, uid: i64) {
        self.members.lock().unwrap().remove(&(room_id, uid));
    }

    fn delete_room_members(&self, room_id: i64) {
        self.members.lock().unwrap().retain(|(rid, _), _| *rid != room_id);
    }

    fn member_uids(&self, room_id: i64) -> Vec<i64> {
        self.members.lock().unwrap().iter().filter(|((rid, _), _)| *rid == room_id).map(|((_, uid), _)| *uid).collect()
    }

    fn role_count(&self, room_id: i64, role: i32) -> u64 {
        self.members.lock().unwrap().iter().filter(|((rid, _), r)| *rid == room_id && **r == role).count() as u64
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
    }
}
