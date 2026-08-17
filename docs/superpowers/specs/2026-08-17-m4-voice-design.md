# M4 语音里程碑设计（Voice Design）

- 日期：2026-08-17
- 状态：已确认
- 范围：语音消息 + 1v1 通话 + 语聊房（三件套全做）；API 版本化机制（header 版）先行落地
- 上游设计：`docs/superpowers/specs/2026-08-16-social-platform-design.md`（§8 语音架构）

## 1. 目标

交付 M4 语音三件套：语音消息（IM 消息类型扩展 + 转码）、1v1 通话（WS 信令状态机 + P2P 媒体面）、语聊房（房间状态机 + mediasoup SFU）。同时落地 API header 版本化机制。

## 2. API 版本化（header 版，任务 0 先行）

**现状**：全部接口注册在 `/api/v1` 前缀组（`config/route.php`），10 个控制器，`AuthMiddleware` 组内挂载。

**机制**：客户端用无版本路径 `/api/xxx` + `Header: X-Api-Version: v1` 提交；全局中间件 `ApiVersionMiddleware`（`config/middleware.php`）路径重写后转交路由分发。

```
客户端: GET /api/auth/register + X-Api-Version: v1
  ▼ ApiVersionMiddleware
  读 X-Api-Version（缺省默认 v1）
  path 已是 /api/vX/... → 不重写（旧路径向后兼容）
  否则 → $request->withPath('/api/v{version}/auth/register')
  ▼ Route::dispatch → 命中既有 /api/v1 路由组（AuthMiddleware 照常生效）
```

- 非法版本（非 `v1|v2|...`）→ 400 + `lang_key`
- 零迁移：现有控制器/路由/E2E 路径全不动
- 未来 v2：注册 `/api/v2` 组 → `app\api\v2\*`，中间件无需改动
- M4 新接口注册在 `/api/v1/voice/*`（版本前缀保留，客户端无版本路径 + header 提交）

## 3. 数据模型（m4.sql）

**`social_messages` ALTER**：

```sql
ALTER TABLE `social_messages`
  ADD COLUMN `voice_url` VARCHAR(500) NOT NULL DEFAULT '' COMMENT '语音URL',
  ADD COLUMN `voice_duration` SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '语音时长(秒)';
-- type 语义扩展：1文本 2图片 3语音
```

**新表**：

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

## 4. 语音消息

```
客户端录音 ──multipart──▶ POST /api/v1/im/voice
  → 校验：≤2MB / ≤60s（FFprobe 读时长）
  → FFmpeg 统一转 m4a（AAC 32kbps 单声道）
  → 存储层落盘（本地 storage/voice/ 起步，S3 接口预留）
  → 返回 {voice_url, duration}
客户端再发 WS send 帧：{type:'send', data:{conversation_id, client_msg_id, type:3,
  voice_url, voice_duration}}（幂等/投递走既有 IM 链路，零新增）
```

- 历史消息 REST 自动带出 `voice_url/voice_duration`（模型 cast）
- 转码同步在请求内完成（单文件秒级）；量大再队列化（ponytail 标记）
- 环境前提：service 运行机需 FFmpeg 二进制（实施时验证，缺则安装）

## 5. 1v1 通话信令

**WS 帧**（复用既有网关，`call_*` 前缀）：

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

**状态机**（Redis 单 key）：

```
key: im:call:{call_id}  HSET: status/caller/callee/offer_at
status: 呼叫中 → 接通 | 未接 | 取消 | 结束 | 失败
```

- 空闲互斥：`SETNX im:callbusy:{uid}`（TTL 5min），冲突回 `already_in_call` 错误帧
- 30s 未接 → 未接，双端推 `call_timeout`，落库
- accept → `call_records` status=2 + started_at
- hangup/结束 → status=5 + ended_at，释放 busy key
- 任一方 WS 断开 → 推对方 `call_hangup` 并结束（ponytail: 不做重连恢复）
- 媒体面 P2P 直连（offer/answer/ICE 仅转发，媒体流不经服务端）；TURN 兜底（coturn 随语聊房交付）
- P2P ICE 15s 未连通 → `call_failed` + 结束（首版不自动切 SFU，ponytail 标记）；落库 status=5

**历史**：`GET /api/v1/voice/calls?page=` 分页返回（caller/callee/status/时长）。

## 6. 语聊房

**REST**：

```
POST   /api/v1/voice/rooms            创建（name）
GET    /api/v1/voice/rooms?page=      列表（含在线人数/麦位数）
GET    /api/v1/voice/rooms/{id}       详情（成员+麦位）
POST   /api/v1/voice/rooms/{id}/close 房主关房
```

**WS 帧**（`room_*` 前缀）：

```
room_join      {room_id}            入房（房主自动占麦位）
room_leave     {room_id}            离房（麦位释放；房主离房→关房）
room_up_mic    {room_id}            上麦
room_down_mic  {room_id}            下麦
room_offer/room_answer/room_ice     SFU 媒体信令（经 service 转发 SFU）
room_kick_mic  {room_id, user_id}   房主踢麦
```

- 麦位上限 8（1 房主 + 7 麦位，常量，后续 admin 可配）；满位回错误帧
- join/leave/麦位变更落 `voice_room_members` 表 + Redis 房间状态；变更推房内全部在线成员
- 房主离房 → 关房（全员推 `room_closed`）

**SFU 信令路径**（设计文档："信令一律复用 service WS 网关"）：

```
客户端 ──WS room_offer/answer/ice──▶ service（WS 网关）
                                        │ HTTP 短调用
                                        ▼
                                  media/sfu (Node + mediasoup)
```

- service 转发帧 → SFU（HTTP POST 转译 mediasoup API：rtpCapabilities、WebRtcTransport 创建/connect、produce/consume）；SFU 回 → service → WS 推客户端
- 每房间一个 mediasoup Router；空置 5min 自动释放（ponytail 标记）

**部署**：`media/sfu` 裸 Node 进程（开发）+ `docker-compose.yml` 预留生产；`coturn` 容器同块交付。

## 7. 测试策略

| 层 | 覆盖 |
|---|---|
| 单测 | ApiVersionMiddleware（默认/显式/非法/旧路径）、通话状态机（invite/accept/reject/cancel/timeout/hangup/互斥）、语聊房状态机（join/麦位/关房/满位/踢麦）、语音上传校验（类型/大小/时长） |
| 黑盒 E2E | 语音消息：上传→发帧→收帧→历史带出 duration；1v1：invite→accept→offer/answer/ICE 转发断言→hangup→call_records 落库；语聊房：join→up_mic→down_mic→leave→关房 |
| 构建 | Android 构建实测；iOS/HarmonyOS 提交注明 Linux 无法构建（M3 既定模式） |
| 真机手测 | SFU 真实音视频、P2P 通话音质（黑盒无法自动化 WebRTC） |

## 8. 实施顺序（依赖倒序流水）

0. API 版本化中间件（先行，独立可交付）
1. 语音消息（上传+转码+存储+模型+消息类型）
2. 1v1 通话信令状态机（+ call_records + 历史 REST）
3. 语聊房（REST + 房间状态机 + 麦位）
4. media/sfu（mediasoup + docker-compose）+ coturn
5. 三端客户端（语音录放 / 通话 UI / 语聊房 UI）
6. E2E + 全量回归
