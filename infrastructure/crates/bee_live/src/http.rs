// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
//! axum REST 壳：11 条 live/voice 路由 + 熔断/限流接入点。
//! uid 取自 `X-User-Id` header（对应 PHP AuthMiddleware 注入的 `$request->uid`）。
//! 广播回调 rpush `social:live:broadcast`（PHP WS worker 消费直推）；Redis 不可用时静默丢弃。

use crate::live::{InMemoryLiveStore, LiveCenter, LiveConfig, LiveError};
use crate::resilience::{BreakerError, CircuitBreaker, RateLimiter};
use crate::store::RedisConn;
use crate::upload::{valid_file_name, VoiceStorage};
use crate::voice::{sfu_http, InMemoryRoomStore, RoomCenter, RoomError};
use axum::extract::{Multipart, Path, State};
use axum::http::{header, HeaderMap, StatusCode};
use axum::response::{IntoResponse, Response};
use axum::routing::{get, post};
use axum::{Json, Router};
use serde_json::{json, Value};
use std::sync::Arc;
use std::time::Duration;

#[derive(Clone)]
pub struct AppState {
    pub live: Arc<LiveCenter<InMemoryLiveStore>>,
    pub voice: Arc<RoomCenter<InMemoryRoomStore>>,
    pub voice_storage: Arc<VoiceStorage>,
    pub limiter: Arc<RateLimiter>,
    pub breaker: Arc<CircuitBreaker>,
}

pub fn app() -> Router {
    let queue: Option<Arc<RedisConn>> = RedisConn::from_env().map(Arc::new);
    let live_send = queue.clone();
    let voice_send = queue;
    let state = AppState {
        live: Arc::new(LiveCenter::new(
            InMemoryLiveStore::new(),
            LiveConfig::from_env(),
            Box::new(move |_uid, payload| {
                if let Some(q) = &live_send {
                    q.enqueue_broadcast(&payload);
                }
            }),
        )),
        voice: Arc::new(RoomCenter::new(
            InMemoryRoomStore::new(),
            Box::new(move |_uid, payload| {
                if let Some(q) = &voice_send {
                    q.enqueue_broadcast(&payload);
                }
            }),
            Box::new(sfu_http),
        )),
        voice_storage: Arc::new(VoiceStorage::new(std::env::var("VOICE_DIR").unwrap_or_else(|_| "storage/voice".into()))),
        limiter: Arc::new(RateLimiter::new(60, Duration::from_secs(60))),
        breaker: Arc::new(CircuitBreaker::new(3, Duration::from_secs(10))),
    };
    Router::new()
        .route("/api/v1/live/rooms", get(live_rooms).post(live_create))
        .route("/api/v1/live/rooms/{id}", get(live_detail))
        .route("/api/v1/live/rooms/{id}/close", post(live_close))
        .route("/api/v1/live/rooms/{id}/mic", post(live_mic_up).delete(live_mic_down))
        .route("/api/v1/voice/calls", get(voice_calls))
        .route("/api/v1/voice/rooms", get(voice_rooms).post(voice_create_room))
        .route("/api/v1/voice/rooms/{id}", get(voice_room_detail))
        .route("/api/v1/voice/rooms/{id}/close", post(voice_close_room))
        .route("/api/v1/im/voice", post(voice_upload))
        .route("/api/v1/voice/{file}", get(voice_file))
        .with_state(state)
}

fn ok(data: Value) -> Response {
    (StatusCode::OK, Json(json!({"code": 0, "message": "ok", "lang_key": "ok", "data": data}))).into_response()
}

fn fail(status: StatusCode, code: i32, msg: &str, lang_key: &str) -> Response {
    (status, Json(json!({"code": code, "message": msg, "lang_key": lang_key}))).into_response()
}

fn uid_of(headers: &HeaderMap) -> i64 {
    headers
        .get("x-user-id")
        .and_then(|v| v.to_str().ok())
        .and_then(|s| s.parse().ok())
        .unwrap_or(0)
}

/// 限流 + 熔断包裹：超限 429；业务失败按错误语义；熔断打开 → 503 降级提示。
fn guarded<T>(
    state: &AppState,
    uid: i64,
    path: &str,
    biz: impl FnOnce() -> Result<T, LiveError>,
    map: impl FnOnce(&LiveError) -> (StatusCode, i32, &'static str, &'static str),
) -> Response
where
    T: Into<Value>,
{
    if !state.limiter.allow(&format!("{}:{}", uid, path)) {
        return fail(StatusCode::TOO_MANY_REQUESTS, 429, "请求过于频繁", "rate_limited");
    }
    match state.breaker.call(biz) {
        Ok(v) => ok(v.into()),
        Err(BreakerError::Open) => fail(StatusCode::SERVICE_UNAVAILABLE, 503, "服务繁忙，请稍后重试", "degraded"),
        Err(BreakerError::Inner(e)) => {
            let (s, c, m, k) = map(&e);
            fail(s, c, m, k)
        }
    }
}

fn live_map(e: &LiveError) -> (StatusCode, i32, &'static str, &'static str) {
    match e {
        LiveError::RoomNotFound => (StatusCode::NOT_FOUND, 404, "直播间不存在或已结束", "live.room_not_found"),
        LiveError::Forbidden => (StatusCode::FORBIDDEN, 403, "仅房主可关播", "live.room_forbidden"),
        LiveError::MicFull => (StatusCode::UNPROCESSABLE_ENTITY, 422, "麦位已满", "live.mic_full"),
    }
}

fn voice_map(e: &RoomError) -> (StatusCode, i32, &'static str, &'static str) {
    match e {
        RoomError::RoomNotFound => (StatusCode::NOT_FOUND, 404, "房间不存在或已关闭", "voice.room_not_found"),
        RoomError::Forbidden => (StatusCode::FORBIDDEN, 403, "仅房主可关房", "voice.room_forbidden"),
        RoomError::MicFull => (StatusCode::UNPROCESSABLE_ENTITY, 422, "麦位已满", "voice.mic_full"),
    }
}

async fn live_create(State(st): State<AppState>, headers: HeaderMap, Json(body): Json<Value>) -> Response {
    let uid = uid_of(&headers);
    let title = body.get("title").and_then(|v| v.as_str()).unwrap_or("").trim().to_string();
    if title.is_empty() || title.chars().count() > 100 {
        return fail(StatusCode::BAD_REQUEST, 400, "标题需 1-100 字符", "live.title_invalid");
    }
    guarded(&st, uid, "/api/v1/live/rooms", || Ok(st.live.create(uid, &title)), live_map)
}

async fn live_rooms(State(st): State<AppState>, headers: HeaderMap) -> Response {
    // ponytail: 骨架用内存态空列表；DB 列表查询（status=1 分页）在 MySQL 批次补
    let uid = uid_of(&headers);
    guarded(&st, uid, "/api/v1/live/rooms", || Ok::<_, LiveError>(json!({"list": []})), live_map)
}

async fn live_detail(State(st): State<AppState>, headers: HeaderMap, Path(id): Path<i64>) -> Response {
    let uid = uid_of(&headers);
    guarded(&st, uid, "/api/v1/live/rooms/{id}", || {
        let room = st.live.find_room(id).ok_or(LiveError::RoomNotFound)?;
        if room.status != 1 {
            return Err(LiveError::RoomNotFound);
        }
        Ok(json!({
            "id": room.id, "owner_id": room.owner_id, "title": room.title,
            "status": room.status,
            "push_url": if uid == room.owner_id { room.push_url } else { String::new() },
            "play_url": room.play_url,
            "online_count": st.live.online_count(id),
            "mic_users": st.live.mic_users(id),
            "danmaku": st.live.recent_danmaku(id, 50),
        }))
    }, live_map)
}

async fn live_close(State(st): State<AppState>, headers: HeaderMap, Path(id): Path<i64>) -> Response {
    let uid = uid_of(&headers);
    guarded(&st, uid, "/api/v1/live/rooms/{id}/close", || st.live.close(id, uid), live_map)
}

async fn live_mic_up(State(st): State<AppState>, headers: HeaderMap, Path(id): Path<i64>) -> Response {
    let uid = uid_of(&headers);
    guarded(&st, uid, "/api/v1/live/rooms/{id}/mic", || st.live.mic_up(id, uid), live_map)
}

async fn live_mic_down(State(st): State<AppState>, headers: HeaderMap, Path(id): Path<i64>) -> Response {
    let uid = uid_of(&headers);
    st.live.mic_down(id, uid);
    ok(json!({"room_id": id}))
}

async fn voice_calls(State(st): State<AppState>, headers: HeaderMap) -> Response {
    // ponytail: 通话记录查询（call_records 分页）在 MySQL 批次补，骨架返回空列表
    let uid = uid_of(&headers);
    guarded(&st, uid, "/api/v1/voice/calls", || Ok::<_, LiveError>(json!({"list": []})), live_map)
}

async fn voice_create_room(State(st): State<AppState>, headers: HeaderMap, Json(body): Json<Value>) -> Response {
    let uid = uid_of(&headers);
    let name = body.get("name").and_then(|v| v.as_str()).unwrap_or("").trim().to_string();
    if name.is_empty() || name.chars().count() > 100 {
        return fail(StatusCode::BAD_REQUEST, 400, "房名需 1-100 字符", "voice.name_invalid");
    }
    let room_id = st.voice.create(uid, &name);
    ok(json!({"room_id": room_id}))
}

async fn voice_rooms(State(st): State<AppState>, headers: HeaderMap) -> Response {
    // ponytail: DB 列表查询（voice_rooms status=1 分页）在 MySQL 批次补
    let uid = uid_of(&headers);
    guarded(&st, uid, "/api/v1/voice/rooms", || Ok::<_, LiveError>(json!({"list": []})), live_map)
}

async fn voice_room_detail(State(st): State<AppState>, headers: HeaderMap, Path(id): Path<i64>) -> Response {
    let uid = uid_of(&headers);
    guarded(&st, uid, "/api/v1/voice/rooms/{id}", || {
        let room = st.voice.find_room(id).ok_or(LiveError::RoomNotFound)?;
        if room.status != 1 {
            return Err(LiveError::RoomNotFound);
        }
        Ok(json!({"id": room.id, "owner_id": room.owner_id, "name": room.name, "status": room.status, "members": []}))
    }, live_map)
}

async fn voice_close_room(State(st): State<AppState>, headers: HeaderMap, Path(id): Path<i64>) -> Response {
    let uid = uid_of(&headers);
    if !st.limiter.allow(&format!("{}:/api/v1/voice/rooms/{}/close", uid, id)) {
        return fail(StatusCode::TOO_MANY_REQUESTS, 429, "请求过于频繁", "rate_limited");
    }
    match st.breaker.call(|| st.voice.close(id, uid)) {
        Ok(()) => ok(json!({"room_id": id})),
        Err(BreakerError::Open) => fail(StatusCode::SERVICE_UNAVAILABLE, 503, "服务繁忙，请稍后重试", "degraded"),
        Err(BreakerError::Inner(e)) => {
            let (s, c, m, k) = voice_map(&e);
            fail(s, c, m, k)
        }
    }
}

/// 上传语音：multipart field=voice → {voice_url, voice_duration}（对齐 PHP ImVoiceController::upload）
async fn voice_upload(State(st): State<AppState>, headers: HeaderMap, mut multipart: Multipart) -> Response {
    let uid = uid_of(&headers);
    if !st.limiter.allow(&format!("{}:/api/v1/im/voice", uid)) {
        return fail(StatusCode::TOO_MANY_REQUESTS, 429, "请求过于频繁", "rate_limited");
    }
    let mut bytes = None;
    while let Ok(Some(field)) = multipart.next_field().await {
        if field.name() == Some("voice") {
            match field.bytes().await {
                Ok(b) => {
                    bytes = Some(b);
                    break;
                }
                Err(_) => return fail(StatusCode::BAD_REQUEST, 400, "缺少 voice 文件", "voice.file_required"),
            }
        }
    }
    let Some(bytes) = bytes else {
        return fail(StatusCode::BAD_REQUEST, 400, "缺少 voice 文件", "voice.file_required");
    };
    if bytes.len() as u64 > crate::upload::MAX_BYTES {
        return fail(StatusCode::PAYLOAD_TOO_LARGE, 413, "voice.too_large", "voice.too_large");
    }
    let tmp = std::env::temp_dir().join(format!("voice_up_{}_{}.upload", uid, std::process::id()));
    if std::fs::write(&tmp, &bytes).is_err() {
        return fail(StatusCode::INTERNAL_SERVER_ERROR, 500, "voice.io_failed", "voice.io_failed");
    }
    let out = match st.voice_storage.ingest(&tmp) {
        Ok(v) => v,
        Err(e) => {
            let _ = std::fs::remove_file(&tmp);
            let (s, c, k) = e.status();
            return fail(StatusCode::from_u16(s).unwrap(), c, k, k); // PHP: message = lang_key
        }
    };
    let _ = std::fs::remove_file(&tmp);
    ok(json!({"voice_url": format!("/api/v1{}", out.url), "voice_duration": out.duration}))
}

/// 静态语音文件（白名单防路径穿越），对齐 PHP ImVoiceController::voiceFile
async fn voice_file(State(st): State<AppState>, Path(file): Path<String>) -> Response {
    if !valid_file_name(&file) {
        return fail(StatusCode::BAD_REQUEST, 400, "bad file", "voice.bad_file");
    }
    match std::fs::read(st.voice_storage.path_of(&file)) {
        Ok(bytes) => (StatusCode::OK, [(header::CONTENT_TYPE, "audio/mp4")], bytes).into_response(),
        Err(_) => fail(StatusCode::NOT_FOUND, 404, "not found", "voice.not_found"),
    }
}
