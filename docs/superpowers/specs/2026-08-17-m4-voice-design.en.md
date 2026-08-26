# M4 Voice Milestone Design (Voice Design)

**语言 / Languages:** [中文](2026-08-17-m4-voice-design.md) · [English](2026-08-17-m4-voice-design.en.md) · [한국어](2026-08-17-m4-voice-design.ko.md) · [Русский](2026-08-17-m4-voice-design.ru.md) · [Deutsch](2026-08-17-m4-voice-design.de.md) · [Français](2026-08-17-m4-voice-design.fr.md) · [Español](2026-08-17-m4-voice-design.es.md) · [Português](2026-08-17-m4-voice-design.pt.md) · [हिन्दी](2026-08-17-m4-voice-design.hi.md) · [العربية](2026-08-17-m4-voice-design.ar.md) · [বাংলা](2026-08-17-m4-voice-design.bn.md) · [Bahasa Indonesia](2026-08-17-m4-voice-design.id.md) · [日本語](2026-08-17-m4-voice-design.ja.md)

- Date: 2026-08-17
- Status: Confirmed
- Scope: voice messages + 1v1 calls + voice chat rooms (all three); API versioning mechanism (header-based) lands first
- Upstream design: `docs/superpowers/specs/2026-08-16-social-platform-design.md` (§8 Voice Architecture)

## 1. Goal

Deliver the M4 voice trio: voice messages (IM message type extension + transcoding), 1v1 calls (WS signaling state machine + P2P media plane), voice chat rooms (room state machine + mediasoup SFU). Also implement the API header versioning mechanism.

## 2. API Versioning (header-based, Task 0 first)

**Current state**: all endpoints are registered under the `/api/v1` prefix group (`config/route.php`), 10 controllers, with `AuthMiddleware` mounted in the group.

**Mechanism**: the client submits a versionless path `/api/xxx` + `Header: X-Api-Version: v1`; the global middleware `ApiVersionMiddleware` (`config/middleware.php`) rewrites the path and hands it to the router.

```
客户端: GET /api/auth/register + X-Api-Version: v1
  ▼ ApiVersionMiddleware
  读 X-Api-Version（缺省默认 v1）
  path 已是 /api/vX/... → 不重写（旧路径向后兼容）
  否则 → $request->withPath('/api/v{version}/auth/register')
  ▼ Route::dispatch → 命中既有 /api/v1 路由组（AuthMiddleware 照常生效）
```

- Invalid version (not `v1|v2|...`) → 400 + `lang_key`
- Zero migration: existing controllers/routes/E2E paths all unchanged
- Future v2: register the `/api/v2` group → `app\api\v2\*`, no middleware changes needed
- M4 new endpoints are registered under `/api/v1/voice/*` (version prefix kept; the client uses a versionless path + header)

## 3. Data Model (m4.sql)

**`social_messages` ALTER**:

```sql
ALTER TABLE `social_messages`
  ADD COLUMN `voice_url` VARCHAR(500) NOT NULL DEFAULT '' COMMENT '语音URL',
  ADD COLUMN `voice_duration` SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '语音时长(秒)';
-- type 语义扩展：1文本 2图片 3语音
```

**New tables**:

```sql
CREATE TABLE `social_call_records` (
  id BIGINT UNSIGNED AUTO_INCREMENT,
  caller_id BIGINT UNSIGNED NOT NULL,
  callee_id BIGINT UNSIGNED NOT NULL,
  status TINYINT NOT NULL DEFAULT 1 COMMENT '1呼叫中 2接通 3未接 4取消 5结束',
  started_at TIMESTAMP NULL COMMENT '接通时间',
  ended_at TIMESTAMP NULL,
  created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
  PRIMARY KEY(id), KEY idx_callee(callee_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='1v1通话记录';

CREATE TABLE `social_voice_rooms` (
  id BIGINT UNSIGNED AUTO_INCREMENT,
  owner_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(100) NOT NULL,
  status TINYINT NOT NULL DEFAULT 1 COMMENT '1开 0关',
  created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
  PRIMARY KEY(id), KEY idx_status(status, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='语聊房';

CREATE TABLE `social_voice_room_members` (
  id BIGINT UNSIGNED AUTO_INCREMENT,
  room_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  role TINYINT NOT NULL DEFAULT 0 COMMENT '0听众 1麦位',
  created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
  PRIMARY KEY(id), UNIQUE KEY uk_room_uid(room_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='语聊房成员';
```

## 4. Voice Messages

```
客户端录音 ──multipart──▶ POST /api/v1/im/voice
  → 校验：≤2MB / ≤60s（FFprobe 读时长）
  → FFmpeg 统一转 m4a（AAC 32kbps 单声道）
  → 存储层落盘（本地 storage/voice/ 起步，S3 接口预留）
  → 返回 {voice_url, duration}
客户端再发 WS send 帧：{type:'send', data:{conversation_id, client_msg_id, type:3,
  voice_url, voice_duration}}（幂等/投递走既有 IM 链路，零新增）
```

- Historical message REST automatically includes `voice_url/voice_duration` (model cast)
- Transcoding completes synchronously within the request (seconds per file); queue it when volume grows (ponytail note)
- Environment prerequisite: the service host needs the FFmpeg binary (verify during implementation; install if missing)

## 5. 1v1 Call Signaling

**WS frames** (reuse the existing gateway, `call_*` prefix):

```
call_invite   {to_user_id}            主叫发起
call_accept   {call_id}               被叫接听
call_reject   {call_id}               被叫拒绝
call_cancel   {call_id}               主叫取消
call_timeout  {call_id}               30s 无人接听（服务端推双方）
call_offer    {call_id, sdp}          主叫 offer（经服务端转发被叫）
call_answer   {call_id, sdp}          被叫 answer 回传
call_ice      {call_id, candidate}    ICE 候选双向转发
call_hangup   {call_id}               任一方挂断 → 推双方
call_failed   {call_id}               P2P 15s 未连通 → 推双方
```

**State machine** (single Redis key):

```
key: im:call:{call_id}  HSET: status/caller/callee/offer_at
status: 呼叫中 → 接通 | 未接 | 取消 | 结束 | 失败
```

- Idle mutex: `SETNX im:callbusy:{uid}` (TTL 5min), on conflict return the `already_in_call` error frame
- No answer within 30s → unanswered, push `call_timeout` to both ends, persist
- accept → `call_records` status=2 + started_at
- hangup/end → status=5 + ended_at, release the busy key
- Either side's WS disconnects → push `call_hangup` to the other side and end (ponytail: no reconnect recovery)
- Media plane is direct P2P (offer/answer/ICE are only relayed, media streams never pass through the server); TURN fallback (coturn ships with voice chat rooms)
- P2P ICE not connected within 15s → `call_failed` + end (v1 does not auto-switch to SFU, ponytail note); persist status=5

**History**: `GET /api/v1/voice/calls?page=` paginated response (caller/callee/status/duration).

## 6. Voice Chat Rooms

**REST**:

```
POST   /api/v1/voice/rooms            创建（name）
GET    /api/v1/voice/rooms?page=      列表（含在线人数/麦位数）
GET    /api/v1/voice/rooms/{id}       详情（成员+麦位）
POST   /api/v1/voice/rooms/{id}/close 房主关房
```

**WS frames** (`room_*` prefix):

```
room_join      {room_id}            入房（房主自动占麦位）
room_leave     {room_id}            离房（麦位释放；房主离房→关房）
room_up_mic    {room_id}            上麦
room_down_mic  {room_id}            下麦
room_offer/room_answer/room_ice     SFU 媒体信令（经 service 转发 SFU）
room_kick_mic  {room_id, user_id}   房主踢麦
```

- Mic slots capped at 8 (1 owner + 7 mic slots, constant, admin-configurable later); return an error frame when full
- join/leave/mic changes persist to the `voice_room_members` table + Redis room state; push changes to all online members in the room
- Owner leaves → close the room (push `room_closed` to everyone)

**SFU signaling path** (per the design doc: "all signaling reuses the service WS gateway"):

```
客户端 ──WS room_offer/answer/ice──▶ service（WS 网关）
                                        │ HTTP 短调用
                                        ▼
                                  media/sfu (Node + mediasoup)
```

- service relays frames → SFU (HTTP POST translating the mediasoup API: rtpCapabilities, WebRtcTransport create/connect, produce/consume); SFU response → service → WS push to the client
- One mediasoup Router per room; auto-released after 5min idle (ponytail note)

**Deployment**: `media/sfu` bare Node process (dev) + `docker-compose.yml` reserved for production; the `coturn` container ships in the same block.

## 7. Test Strategy

| Layer | Coverage |
|---|---|
| Unit | ApiVersionMiddleware (default/explicit/invalid/legacy path), call state machine (invite/accept/reject/cancel/timeout/hangup/mutex), voice room state machine (join/mic slots/close/full/kick), voice upload validation (type/size/duration) |
| Black-box E2E | Voice messages: upload → send frame → receive frame → history carries duration; 1v1: invite→accept→assert offer/answer/ICE relay→hangup→call_records persisted; voice rooms: join→up_mic→down_mic→leave→close room |
| Build | Android build actually tested; iOS/HarmonyOS commits note that Linux cannot build (established M3 pattern) |
| Real-device manual | Real SFU audio/video, P2P call quality (black-box cannot automate WebRTC) |

## 8. Implementation Order (reverse-dependency pipeline)

0. API versioning middleware (first, independently deliverable)
1. Voice messages (upload + transcoding + storage + model + message type)
2. 1v1 call signaling state machine (+ call_records + history REST)
3. Voice chat rooms (REST + room state machine + mic slots)
4. media/sfu (mediasoup + docker-compose) + coturn
5. Three-platform clients (voice record/playback / call UI / voice room UI)
6. E2E + full regression
