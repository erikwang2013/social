# M6：直播/语音 + 熔断/降级/限流 Rust 化 — 设计

- 日期: 2026-08-27
- 目标: 直播/语音业务与弹性三件套（熔断/降级/限流）从 PHP 迁至 Rust（`infrastructure/crates/bee_live`）
- 原则: 状态机为纯逻辑（无 IO），存储经 trait 抽象；Redis 键与广播协议与 PHP 版逐字节兼容，切换零停机

## 1. 现状（PHP，待迁）

| 模块 | 文件 | 逻辑 |
|------|------|------|
| 直播状态机 | `service/app/live/LiveCenter.php` | create/join/leave/close/send_danmaku/mic_up/mic_down/on_disconnect + broadcast |
| 直播地址签发 | `service/app/live/LiveStreamService.php` | 纯函数 signPushUrl/signPlayUrl |
| 语聊房状态机 | `service/app/room/RoomCenter.php` | create/join/leave/close/up_mic/down_mic/kick_mic + SFU 转译 |
| 路由 | `service/config/route.php` L62-74 | 11 条 live/voice REST 路由 |
| 弹幕/在线 | Redis | `live:room:{id}:online\|danmaku\|mic`、`live:roomuser:{uid}`、`im:room:{id}:online`、`im:roomuser:{uid}` |
| 广播桥 | `social:live:broadcast` List | PHP WS worker 定时消费直推；payload = Envelope::encode = `{"type","data"}` JSON |

## 2. 目标架构（bee_live crate）

```
bee_live
├── protocol.rs     # Envelope JSON 兼容（type+data，无 seq 省略）
├── live.rs         # 直播状态机（纯逻辑）+ LiveStore trait（Redis 键协议）
├── voice.rs        # 语聊房状态机（纯逻辑）+ RoomStore trait
├── resilience.rs   # CircuitBreaker / RateLimiter / fallback（降级组合子）
├── http.rs         # axum REST 路由（11 条，handler 薄壳）
└── tests/          # 状态机 + 弹性三件套单元自检（InMemory store，无 IO）
```

关键决策：
- **状态机 = 纯函数 + Store trait**：`LiveStore`/`RoomStore` 抽象 sadd/srem/smembers/scard/lpush/ltrim/lrange/del + 房间行查询。骨架带 `InMemoryLiveStore`（测试用）；`RedisLiveStore`（`redis` crate）与 MySQL 持久化（经 bee_orm mysql feature 或直连 mysql_async）列为第二批。
- **熔断/限流做成中间件形态**：`RateLimiter` 固定窗口按 uid+path 计数（超限 429）；`CircuitBreaker` 闭合→打开（连续 N 失败）→半开（探测请求）→闭合，打开期直接短路；`fallback(fn, fallback_fn)` 组合子实现降级（主调用失败→降级提示/缓存值）。
- **广播桥保持兼容**：Rust 侧广播 = `rpush social:live:broadcast {type,data}`，PHP WS worker 无感知，切换只改路由指向。
- **媒体面不动**：RTMP/HLS/SFU 本就第三方，仅地址签发迁 Rust。

## 3. 分阶段

1. **本批（骨架）**: 状态机移植 + resilience 三件套 + protocol + axum 路由壳 + InMemory store + 单测全绿 + 编译通过
2. **下一批**: RedisLiveStore（redis crate）+ MySQL 持久化（live_rooms/voice_rooms/voice_room_members/call_records）→ live_e2e 对照跑绿
3. **再下一批**: SFU 转译（RoomCenter::sfuHttp）+ 语音上传存储（ffmpeg m4a ingest）+ 静态语音文件服务
4. **收尾**: PHP 路由 11 条改代理/下线，弹幕 keep/mic_limit 配置外置，文档 13 语言同步

## 4. 契约（与 PHP 版必须一致）

- 弹幕/在线/麦位键名与命令序列逐字节相同（TOCTOU 复核：sadd 后查房间状态，关播并发则回滚）
- 广播 payload `{"type": "live_join"|..., "data": {...}}`，JSON 无空格无转义斜杠
- HTTP 响应体 `{"code":0,"message":"ok","lang_key":"ok","data":{...}}` 结构一致；错误语义 400/403/404/422 对齐
- 关播先广播后删键（broadcast 依赖在线集合）
- mic_limit=8、danmaku_keep=200 配置化
