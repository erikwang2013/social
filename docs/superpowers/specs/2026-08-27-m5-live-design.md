# M5 直播设计（最小垂直切片）

日期：2026-08-27
范围：M5a 直播主力 —— 第三方直播管线 + 直播间 + 弹幕 + 连麦
原则：先打通「开播 → 观众进房 → 弹幕 → 连麦」主链路，再补边角。

## 1. 总体架构

```
客户端(OBS/推流端) ──RTMP──> [第三方管线: SRS / nginx-rtmp] ──HLS──> 观众播放器
                                  ▲ play_url / push_url 由 service 签发
客户端(App) ──REST /api/v1/live/*──> service (webman :8788) 直播间 CRUD
客户端(App) ──WS :8789──> service LiveCenter 弹幕/连麦/进出房广播
状态分层：MySQL social_live_rooms 为事实；Redis 承载在线集合/弹幕/麦位实时态
```

- **管线解耦**：媒体面走第三方 RTMP 服务器（SRS 或 nginx-rtmp 均可），service 只签发推流/播放地址，不转发媒体流。本机无媒体服务器时 API 状态机照常工作（地址照常返回，联调时指向真实服务器）。
- **连麦不做 WebRTC SFU 对接**（M4 SFU 已覆盖语音场景）：M5 最小切片里连麦 = 麦位集合 + 上/下麦广播，媒体面留给推流端多路 RTMP（OBS 多流）或后续增强。

## 2. 数据模型

`database/install.sql` 追加一张表（业务表区段末尾）：

```sql
CREATE TABLE IF NOT EXISTS `social_live_rooms` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `owner_id` BIGINT UNSIGNED NOT NULL,
  `title` VARCHAR(100) NOT NULL,
  `status` TINYINT NOT NULL DEFAULT 1 COMMENT '1直播中 0已结束',
  `push_url` VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'RTMP推流地址',
  `play_url` VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'HLS播放地址',
  `started_at` TIMESTAMP NULL, `ended_at` TIMESTAMP NULL,
  `created_at` TIMESTAMP NULL, `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`), KEY `idx_status_updated` (`status`, `updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='直播间';
```

- **弹幕不入库**：实时走 Redis List（保留最近 200 条，`live:room:{id}:danmaku`）。
- **连麦不入库**：麦位 uid 集合走 Redis Set（`live:room:{id}:mic`），关播即销毁。
- 参照 `social_voice_rooms`：BigInt 主键、status/updated_at 索引、`utf8mb4`。

## 3. REST API（/api/v1 组，AuthMiddleware 内）

| 方法 | 路径 | 说明 |
|------|------|------|
| POST | /live/rooms | 开播。请求 `{title}` → 创建房间，签发 push_url/play_url 并落库 |
| GET | /live/rooms | 直播中列表（status=1，按 started_at 倒序），含在线人数（Redis scard） |
| GET | /live/rooms/{id} | 详情：标题/房主/状态/play_url/在线数/麦位列表/最近弹幕 |
| POST | /live/rooms/{id}/close | 关播（仅房主），广播 live_closed，Redis 键清理 |
| POST | /live/rooms/{id}/mic | 上麦（进连麦），广播 live_mic_up；超 8 人 422 |
| DELETE | /live/rooms/{id}/mic | 下麦，广播 live_mic_down |

响应沿用 `{code, message, lang_key?, data}` 约定；错误码 404（房间不存在）/ 403（非房主）/ 422（参数/满员）。**id 直接用自增数字 id**（service 端所有控制器均如此，保持一致，不做 hashid）。

## 4. WS 帧协议（Envelope 新增常量）

| 入帧 | 出帧/广播 | 说明 |
|------|-----------|------|
| `live_join` {room_id} | `live_join`（对房内广播） | 进房，sadd 在线集合 |
| `live_leave` {room_id} | `live_leave`（广播） | 退房/断线，srem 在线集合；房主断线不自动关播 |
| `danmaku_send` {room_id, content} | `danmaku` {room_id, user_id, nickname, content}（广播，回执 ack） | 弹幕；空/超长(>200 字) 422；入 Redis List 保留 200 条 |
| `live_mic_up` {room_id} | `live_mic_up` {room_id, user_id}（广播） | 上麦，与 REST mic 等价（REST 优先，WS 为便捷入口） |
| `live_mic_down` {room_id} | `live_mic_down`（广播） | 下麦 |
| — | `live_closed` {room_id}（广播） | 关播；后续入房请求 404 |

校验失败回 `error` 帧（复用 T_ERROR）。帧结构 `{type, seq?, data}` 与既有协议一致。

## 5. 服务端组件

- `app/live/LiveCenter.php`：仿 RoomCenter。构造注入 sendFn（默认 `Deliverer::pushToMember`）。方法：create/join/leave/close/sendDanmaku/micUp/micDown/roomInfo。Redis 键：
  - `live:room:{id}:online`（Set，在线 uid）
  - `live:roomuser:{uid}`（Set，用户所在直播房间，断线清理）
  - `live:room:{id}:danmaku`（List，左压右裁，保留 200）
  - `live:room:{id}:mic`（Set，麦位 uid，上限 8）
- `app/live/LiveStreamService.php`：地址签发（纯函数，便于单测）。
  - `signPushUrl(roomId)` → `rtmp://{host}/live/{roomId}`
  - `signPlayUrl(roomId)` → `http://{host}/hls/{roomId}.m3u8`
  - host 来自 `config/live.php`（`LIVE_RTMP_HOST` / `LIVE_HLS_HOST` env，默认 127.0.0.1）
- `app/controller/LiveController.php`：REST 五个端点，薄壳调 LiveCenter。
- `app/ws/ActionHandler.php`：新增 live_join/live_leave/danmaku_send/live_mic_up/live_mic_down 五个 case（进房/弹幕先校验房间存在与状态）。
- `app/model/LiveRoom.php`：Eloquent 模型（对照 VoiceRoom）。
- `config/live.php`：管线配置（推流/播放 host，注释齐全）。
- `config/route.php`：/api/v1 组内注册六个路由。

## 6. 关播清理（一致性）

close 时：
1. DB status=0 + ended_at（事实）
2. 广播 live_closed（对在线集合）
3. Redis 删除 `live:room:{id}:online|danmaku|mic` + 每个在线用户 `live:roomuser:{uid}`（幂等，键不存在忽略）

顺序：先广播后删键，避免集合已删广播不到人；参照 RoomCenter TOCTOU 复核思路（join 时 sadd 后复核房间状态）。

## 7. 复用与差异

- 复用：Envelope 帧格式、Deliverer 推送、WsRedis、RoomCenter 的 Redis 集合 + TOCTOU 复核模式、控制器 json() 响应约定。
- 差异：无 SFU HTTP 转译（直播媒体面不在 service）；弹幕/麦位刻意不入 MySQL（防膨胀）；关播广播后直接清理键（无恢复路径）。

## 8. 非目标（本切片不做）

- 弹幕历史落库查询（Redis 200 条上限即全部）、礼物/虚拟币（M6）、自建 SRS 部署（M5b）、转码/多码率、审核。
