// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
//! M6: live/voice gRPC 服务 — PHP controllers 经 gRPC 调 Rust 状态机。
//! 统一 LiveReply：code=0 成功（data_json 业务数据 / bytes_data 文件字节）；
//! code>0 即 HTTP status，message/lang_key 对齐 PHP lang_key 语义。

tonic::include_proto!("social");

use bee_live::live::{LiveCenter, LiveConfig, LiveError};
use bee_live::store::{MySqlLiveStore, MySqlRoomStore, RedisConn, mysql_opts};
use bee_live::upload::{MAX_BYTES, valid_file_name, VoiceStorage};
use bee_live::voice::{RoomCenter, RoomError, sfu_http};
use mysql_async::prelude::Queryable;
use mysql_async::{Conn, Params};
use serde_json::{json, Value};
use social::live::v1::{
    CreateRoomRequest, CreateVoiceRoomRequest, GetVoiceFileRequest, IdRequest, ListRoomsRequest,
    LiveReply, UploadVoiceRequest,
    live_service_server::LiveService,
    voice_service_server::VoiceService,
};
use std::sync::Arc;
use tonic::{Request, Response, Status};

pub use social::live::v1::live_service_server::LiveServiceServer as LiveSrvServer;
pub use social::live::v1::voice_service_server::VoiceServiceServer as VoiceSrvServer;

fn ok_reply(data: Value) -> LiveReply {
    LiveReply {
        code: 0,
        message: "ok".into(),
        lang_key: "ok".into(),
        data_json: data.to_string(),
        bytes_data: Vec::new(),
    }
}

fn err_reply(status: u16, lang_key: &str, message: &str) -> LiveReply {
    LiveReply {
        code: status as i32,
        message: message.into(),
        lang_key: lang_key.into(),
        data_json: String::new(),
        bytes_data: Vec::new(),
    }
}

fn live_err(e: LiveError) -> LiveReply {
    match e {
        LiveError::RoomNotFound => err_reply(404, "live.room_not_found", "直播间不存在或已结束"),
        LiveError::Forbidden => err_reply(403, "live.room_forbidden", "仅房主可关播"),
        LiveError::MicFull => err_reply(422, "live.mic_full", "麦位已满"),
    }
}

fn room_err(e: RoomError) -> LiveReply {
    match e {
        RoomError::RoomNotFound => err_reply(404, "voice.room_not_found", "房间不存在或已关闭"),
        RoomError::Forbidden => err_reply(403, "voice.room_forbidden", "仅房主可关房"),
        RoomError::MicFull => err_reply(422, "voice.mic_full", "麦位已满"),
    }
}

/// 同步 store（内部 block_on，见 bee_live store.rs ponytail 注）经 spawn_blocking
/// 隔离，避免在 tokio worker 内二次 block_on panic。
async fn blocking<T, F>(f: F) -> Result<T, Status>
where
    F: FnOnce() -> T + Send + 'static,
    T: Send + 'static,
{
    tokio::task::spawn_blocking(f).await.map_err(|_| Status::internal("live worker panic"))
}

pub struct LiveSvc {
    live: Arc<LiveCenter<MySqlLiveStore>>,
}

impl LiveSvc {
    pub fn from_env() -> Option<Self> {
        let store = MySqlLiveStore::from_env()?;
        let queue = RedisConn::from_env();
        let send = Box::new(move |_uid: i64, payload: String| {
            if let Some(q) = &queue {
                q.enqueue_broadcast(&payload);
            }
        });
        Some(Self { live: Arc::new(LiveCenter::new(store, LiveConfig::from_env(), send)) })
    }
}

#[tonic::async_trait]
impl LiveService for LiveSvc {
    async fn create_room(&self, req: Request<CreateRoomRequest>) -> Result<Response<LiveReply>, Status> {
        let r = req.into_inner();
        let title = r.title.trim().to_string();
        if title.is_empty() || title.chars().count() > 100 {
            return Ok(Response::new(err_reply(400, "live.title_invalid", "标题需 1-100 字符")));
        }
        let live = Arc::clone(&self.live);
        let reply = blocking(move || {
            let room_id = live.create(r.uid, &title);
            let (push, play) = live.find_room(room_id).map(|x| (x.push_url, x.play_url)).unwrap_or_default();
            ok_reply(json!({"room_id": room_id, "push_url": push, "play_url": play}))
        })
        .await?;
        Ok(Response::new(reply))
    }

    async fn list_rooms(&self, req: Request<ListRoomsRequest>) -> Result<Response<LiveReply>, Status> {
        let r = req.into_inner();
        let limit = if r.limit > 0 { r.limit as u64 } else { 20 };
        let offset = r.offset.max(0) as u64;
        let live = Arc::clone(&self.live);
        let reply = blocking(move || {
            let list: Vec<Value> = live
                .list_rooms(offset, limit)
                .into_iter()
                .map(|room| {
                    json!({
                        "id": room.id, "owner_id": room.owner_id, "title": room.title,
                        "play_url": room.play_url,
                        "online_count": live.online_count(room.id),
                        "mic_count": live.mic_count(room.id),
                    })
                })
                .collect();
            ok_reply(json!({"list": list}))
        })
        .await?;
        Ok(Response::new(reply))
    }

    async fn room_detail(&self, req: Request<IdRequest>) -> Result<Response<LiveReply>, Status> {
        let r = req.into_inner();
        let live = Arc::clone(&self.live);
        let reply = blocking(move || {
            let Some(room) = live.find_room(r.id) else {
                return err_reply(404, "live.room_not_found", "直播间不存在或已结束");
            };
            if room.status != 1 {
                return err_reply(404, "live.room_not_found", "直播间不存在或已结束");
            }
            let push_url = if r.uid == room.owner_id { room.push_url } else { String::new() };
            ok_reply(json!({
                "id": room.id, "owner_id": room.owner_id, "title": room.title, "status": room.status,
                "push_url": push_url, "play_url": room.play_url,
                "online_count": live.online_count(room.id),
                "mic_users": live.mic_users(room.id),
                "danmaku": live.recent_danmaku(room.id, 50),
            }))
        })
        .await?;
        Ok(Response::new(reply))
    }

    async fn close_room(&self, req: Request<IdRequest>) -> Result<Response<LiveReply>, Status> {
        let r = req.into_inner();
        let live = Arc::clone(&self.live);
        let reply = blocking(move || match live.close(r.id, r.uid) {
            Ok(()) => ok_reply(json!({"room_id": r.id})),
            Err(e) => live_err(e),
        })
        .await?;
        Ok(Response::new(reply))
    }

    async fn mic_up(&self, req: Request<IdRequest>) -> Result<Response<LiveReply>, Status> {
        let r = req.into_inner();
        let live = Arc::clone(&self.live);
        let reply = blocking(move || match live.mic_up(r.id, r.uid) {
            Ok(()) => ok_reply(json!({"room_id": r.id})),
            Err(e) => live_err(e),
        })
        .await?;
        Ok(Response::new(reply))
    }

    async fn mic_down(&self, req: Request<IdRequest>) -> Result<Response<LiveReply>, Status> {
        let r = req.into_inner();
        let live = Arc::clone(&self.live);
        let reply = blocking(move || {
            live.mic_down(r.id, r.uid);
            ok_reply(json!({"room_id": r.id}))
        })
        .await?;
        Ok(Response::new(reply))
    }
}

pub struct VoiceSvc {
    voice: Arc<RoomCenter<MySqlRoomStore>>,
    storage: Arc<VoiceStorage>,
}

impl VoiceSvc {
    pub fn from_env() -> Option<Self> {
        let store = MySqlRoomStore::from_env()?;
        let queue = RedisConn::from_env();
        let send = Box::new(move |_uid: i64, payload: String| {
            if let Some(q) = &queue {
                q.enqueue_broadcast(&payload);
            }
        });
        let voice = Arc::new(RoomCenter::new(store, send, Box::new(sfu_http)));
        let storage = Arc::new(VoiceStorage::new(std::env::var("VOICE_DIR").unwrap_or_else(|_| "storage/voice".into())));
        Some(Self { voice, storage })
    }
}

#[tonic::async_trait]
impl VoiceService for VoiceSvc {
    async fn create_room(&self, req: Request<CreateVoiceRoomRequest>) -> Result<Response<LiveReply>, Status> {
        let r = req.into_inner();
        let name = r.name.trim().to_string();
        if name.is_empty() || name.chars().count() > 100 {
            return Ok(Response::new(err_reply(400, "voice.name_invalid", "房名需 1-100 字符")));
        }
        let voice = Arc::clone(&self.voice);
        let reply = blocking(move || {
            let room_id = voice.create(r.uid, &name);
            ok_reply(json!({"room_id": room_id}))
        })
        .await?;
        Ok(Response::new(reply))
    }

    async fn list_rooms(&self, req: Request<ListRoomsRequest>) -> Result<Response<LiveReply>, Status> {
        let r = req.into_inner();
        let limit = if r.limit > 0 { r.limit as u64 } else { 20 };
        let offset = r.offset.max(0) as u64;
        let voice = Arc::clone(&self.voice);
        let reply = blocking(move || {
            let list: Vec<Value> = voice
                .list_rooms(offset, limit)
                .into_iter()
                .map(|room| {
                    json!({
                        "id": room.id, "owner_id": room.owner_id, "name": room.name,
                        "online_count": voice.online_count(room.id),
                        "mic_count": voice.mic_count(room.id),
                    })
                })
                .collect();
            ok_reply(json!({"list": list}))
        })
        .await?;
        Ok(Response::new(reply))
    }

    async fn room_detail(&self, req: Request<IdRequest>) -> Result<Response<LiveReply>, Status> {
        let r = req.into_inner();
        let voice = Arc::clone(&self.voice);
        let reply = blocking(move || {
            let Some(room) = voice.find_room(r.id) else {
                return err_reply(404, "voice.room_not_found", "房间不存在或已关闭");
            };
            if room.status != 1 {
                return err_reply(404, "voice.room_not_found", "房间不存在或已关闭");
            }
            let members: Vec<Value> = voice
                .members(r.id)
                .into_iter()
                .map(|(user_id, role)| json!({"user_id": user_id, "role": role}))
                .collect();
            ok_reply(json!({
                "id": room.id, "owner_id": room.owner_id, "name": room.name, "status": room.status,
                "members": members,
            }))
        })
        .await?;
        Ok(Response::new(reply))
    }

    async fn close_room(&self, req: Request<IdRequest>) -> Result<Response<LiveReply>, Status> {
        let r = req.into_inner();
        let voice = Arc::clone(&self.voice);
        let reply = blocking(move || match voice.close(r.id, r.uid) {
            Ok(()) => ok_reply(json!({"room_id": r.id})),
            Err(e) => room_err(e),
        })
        .await?;
        Ok(Response::new(reply))
    }

    /// 1v1 通话记录（social_call_records），PHP: `caller_id = ? OR callee_id = ?` 倒序分页
    async fn list_calls(&self, req: Request<ListRoomsRequest>) -> Result<Response<LiveReply>, Status> {
        let r = req.into_inner();
        let limit = if r.limit > 0 { r.limit as u64 } else { 20 };
        let offset = r.offset.max(0) as u64;
        let opts = mysql_opts();
        let Ok(mut conn) = Conn::new(opts).await else {
            return Ok(Response::new(err_reply(500, "voice.db_error", "语音服务暂不可用")));
        };
        let rows: Vec<(i64, i64, i64, i8, Option<String>, Option<String>, Option<String>, Option<String>)> = match conn
            .exec(
                "SELECT id, caller_id, callee_id, status, DATE_FORMAT(started_at, '%Y-%m-%d %H:%i:%s'), DATE_FORMAT(ended_at, '%Y-%m-%d %H:%i:%s'), DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s'), DATE_FORMAT(updated_at, '%Y-%m-%d %H:%i:%s') FROM social_call_records WHERE caller_id = ? OR callee_id = ? ORDER BY id DESC LIMIT ? OFFSET ?",
                Params::from((r.uid, r.uid, limit, offset)),
            )
            .await
        {
            Ok(rows) => rows,
            Err(_) => return Ok(Response::new(err_reply(500, "voice.db_error", "语音服务暂不可用"))),
        };
        let list: Vec<Value> = rows
            .into_iter()
            .map(|(id, caller_id, callee_id, status, started_at, ended_at, created_at, updated_at)| {
                json!({
                    "id": id, "caller_id": caller_id, "callee_id": callee_id, "status": status,
                    "started_at": started_at, "ended_at": ended_at,
                    "created_at": created_at, "updated_at": updated_at,
                })
            })
            .collect();
        Ok(Response::new(ok_reply(json!({"list": list}))))
    }

    /// 上传语音 → {voice_url, voice_duration}（对齐 PHP ImVoiceController::upload）
    async fn upload_voice(&self, req: Request<UploadVoiceRequest>) -> Result<Response<LiveReply>, Status> {
        let r = req.into_inner();
        if r.voice.is_empty() {
            return Ok(Response::new(err_reply(400, "voice.file_required", "缺少 voice 文件")));
        }
        if r.voice.len() as u64 > MAX_BYTES {
            return Ok(Response::new(err_reply(413, "voice.too_large", "voice.too_large")));
        }
        let storage = Arc::clone(&self.storage);
        let reply = blocking(move || {
            let tmp = std::env::temp_dir().join(format!("voice_grpc_{}_{}.upload", r.uid, std::process::id()));
            if std::fs::write(&tmp, &r.voice).is_err() {
                return err_reply(500, "voice.io_failed", "voice.io_failed");
            }
            let out = match storage.ingest(&tmp) {
                Ok(v) => v,
                Err(e) => {
                    let _ = std::fs::remove_file(&tmp);
                    let (s, _, k) = e.status();
                    return err_reply(s, k, k); // PHP: message = lang_key
                }
            };
            let _ = std::fs::remove_file(&tmp);
            ok_reply(json!({
                "voice_url": format!("/api/v1{}", out.url),
                "voice_duration": out.duration,
            }))
        })
        .await?;
        Ok(Response::new(reply))
    }

    /// 静态语音文件（白名单防路径穿越），PHP 端以 audio/mp4 转发
    async fn get_voice_file(&self, req: Request<GetVoiceFileRequest>) -> Result<Response<LiveReply>, Status> {
        let r = req.into_inner();
        if !valid_file_name(&r.file) {
            return Ok(Response::new(err_reply(400, "voice.bad_file", "bad file")));
        }
        let storage = Arc::clone(&self.storage);
        let reply = blocking(move || match std::fs::read(storage.path_of(&r.file)) {
            Ok(bytes) => LiveReply {
                code: 0,
                message: "ok".into(),
                lang_key: "ok".into(),
                data_json: String::new(),
                bytes_data: bytes,
            },
            Err(_) => err_reply(404, "voice.not_found", "not found"),
        })
        .await?;
        Ok(Response::new(reply))
    }
}
