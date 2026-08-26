# Social Platform Overall Design

**语言 / Languages:** [中文](2026-08-16-social-platform-design.md) · [English](2026-08-16-social-platform-design.en.md) · [한국어](2026-08-16-social-platform-design.ko.md) · [Русский](2026-08-16-social-platform-design.ru.md) · [Deutsch](2026-08-16-social-platform-design.de.md) · [Français](2026-08-16-social-platform-design.fr.md) · [Español](2026-08-16-social-platform-design.es.md) · [Português](2026-08-16-social-platform-design.pt.md) · [हिन्दी](2026-08-16-social-platform-design.hi.md) · [العربية](2026-08-16-social-platform-design.ar.md) · [বাংলা](2026-08-16-social-platform-design.bn.md) · [Bahasa Indonesia](2026-08-16-social-platform-design.id.md) · [日本語](2026-08-16-social-platform-design.ja.md)

- Date: 2026-08-16
- Status: Confirmed, pending implementation
- Scope: image-and-text short-content community + instant messaging + live streaming/voice + virtual economy, multilingual, global multi-region

## 1. Goals and Scope

Build a social platform combining image-and-text short content + IM, with live streaming (video + danmaku + co-hosting), voice (messages / 1v1 calls / voice chat rooms), and a gift-tipping virtual economy. Support UI multilingualization, content translation, and multi-region compliance, with deployment across multiple global regions. Parallel native development on three platforms: iOS / Android / HarmonyOS.

## 2. System Overview

```
                    ┌─────────────────────────────────────────────┐
                    │   iOS (SwiftUI) │ Android (Kotlin+Compose)  │
                    │            HarmonyOS (ArkTS)                │
                    └───────┬─────────────────────────┬───────────┘
                            │  HTTPS / WSS（多区域就近接入）
                  ┌─────────▼──────────┐   ┌──────────────────────┐
                  │  CDN + 区域接入层   │   │ 厂商推送 APNs/FCM/华为 │
                  └─────────┬──────────┘   └──────────────────────┘
              ┌─────────────▼─────────────┐
              │   service (webman v2)     │──gRPC──▶ infrastructure
              │  业务单体：认证/资料/动态/ │          (bee-rust)
              │  点赞/评论/关注/IM 网关/   │  gRPC      搜索/推荐/图/
              │  翻译调度/审核/直播/语音/  │  ▲        时序/热数据
              │  虚拟经济                 │  │ gRPC
              └─────────────┬─────────────┘  │
                  ┌─────────▼─────────┐      │
                  │ MySQL + Redis     │      │
                  │ S3 对象存储       │      │
                  └───────────────────┘      │
              ┌──────────────────────────────┴──┐
              │   admin (open-admin 改造, webman) │
              │  审核台/举报/GDPR/看板/词条/礼物库/ │
              │  直播配置/提现审核                 │
              └──────────────────────────────────┘

  media/（自建媒体层，信令走 service WS 网关）
  ├── sfu/    mediasoup：1v1 通话、语聊房
  ├── srs/    SRS：自建直播（RTMP → FFmpeg 转码 → FLV/HLS）
  └── coturn/ TURN 中继

  外部：第三方直播云（推流/转码/CDN/实时审核）、第三方 RTC（连麦）、
        第三方审核 API、商店 IAP（App Store / Google Play / 华为）
```

## 3. Subsystem Responsibilities

### 3.1 contracts (gRPC contracts, new top-level directory)

```
contracts/
├── buf.yaml                      # buf 配置（唯一生成入口）
├── common/types.proto            # 分页、错误、时间戳、区域枚举等公共类型
├── infra/infra_service.proto     # infrastructure 对外服务
├── user/user_service.proto       # service 对外服务（admin 调用用）
└── admin/admin_service.proto     # admin 对外服务（service/infra 调用用）
```

- Generation pipeline: CI uses buf to generate three kinds of stubs and commits them to their respective sub-repos (builds do not depend on the network)
  - service/, admin/ → PHP stubs (grpc/grpc + google/protobuf)
  - infrastructure/ → Rust stubs (tonic)
- Versioning rule: only add fields, never modify or delete; package names carry the major version (`social.user.v1`)

### 3.2 service (webman v2) — user-facing business monolith

- **API domains**: auth (JWT dual tokens + blacklist), profile, posts, likes, comments, follows, IM (conversations/messages/WS gateway), notifications, translation scheduling, live room/danmaku/co-host signaling, voice call/voice chat room signaling, virtual economy (wallet/gifts/IAP verification/revenue share), GDPR export/delete
- **Multilingual error system**: errors return `{code, lang_key, params}`; copy is rendered client-side per locale
- **Queues** (redis-queue): moderation triggers, translation scheduling, push delivery, async statistics, gift-effect broadcast
- **Scheduled tasks** (webman-crontab): translation pre-warming, expired token/message cleanup, audit archiving, revenue-share settlement
- **IDs**: `erikwang2013/snowflake-php` (consistent with admin)
- **Contracts**: OpenAPI 3.0 auto-export → typed clients generated for the three platforms

### 3.3 infrastructure (bee-rust) — high-throughput compute layer

Does not store business primary data (MySQL is the sole source of truth); it takes on compute-heavy / query-heavy capabilities:

- `bee_search`: full-text search over posts/users (Chinese word segmentation, multilingual indexing)
- `bee_graph`: social graph → recommendation feed
- `bee_tsdb`: time-series statistics for DAU, posting, interactions, live views, voice call duration, etc.
- `bee_cache/bee_kv`: timeline cache, counters (like counts, view counts, online user counts)
- Deployed per region; read-heavy, write-light; data replicated from the central site

### 3.4 admin (open-admin rework)

**Reused**: JWT/RBAC/audit/file management/health checks/zh-en i18n infrastructure

**New**:
- Content moderation workbench: bilingual side-by-side review of posts/comments/images, multilingual rejection-reason templates, user penalties
- Report handling queue
- GDPR request desk (export/delete tickets)
- Data dashboards backed by bee_tsdb
- i18n term management (CRUD for terms shared by the four clients)
- Gift catalog management (SKU, prices, effects, multilingual names)
- Live provider configuration (routing strategy, switch order)
- Withdrawal request review

### 3.5 media (self-hosted media layer, Node.js + system services)

- `sfu/`: mediasoup; carries the media plane of 1v1 calls and voice chat rooms; media forwarding only, no business logic
- `srs/`: self-hosted SRS live streaming; RTMP ingest → FFmpeg transcoding → HTTP-FLV/HLS delivery
- `coturn/`: TURN relay, fallback for NAT traversal
- All signaling reuses the service WS gateway for forwarding

### 3.6 apps — parallel native development on three platforms

- Shared OpenAPI contract; each platform generates its own typed client
- Unified infrastructure modules: networking layer (retry/auth refresh), WS client (IM/danmaku/call signaling), i18n (local resources + remote incremental terms), push registration, theming
- HarmonyOS notes: Huawei Push Kit, ArkTS concurrency model adaptation

## 4. Backend Communication (gRPC)

```
 service (webman/PHP) ──gRPC──▶ infrastructure (bee-rust/tonic)
      │                            ▲
      │ gRPC                        │ gRPC
      ▼                            │
 admin (webman/PHP) ──────gRPC─────┘
   （admin→service：封号/删内容/审核结果回调）
```

| Caller → Callee | Payload |
|------|------|
| service → infra | full-text search, recommendation feed, timeline hot cache, counter reads/writes, time-series stat writes |
| admin → infra | dashboard stats queries, backend search |
| admin → service | user penalties, content deletion, moderation result delivery |
| service → admin | report events, moderation task enqueueing (async) |

Boundary: the three platform apps and the admin frontend (Flutter) use HTTPS REST + WS and never touch gRPC directly.

**Operations prerequisite**: PHP-side gRPC requires the official `grpc` extension (C extension) + the `grpc/grpc` composer package; the server-side pattern follows workerman's official walkor/grpc approach; the deployment docs must spell this out.

## 5. Multilingual Architecture (three layers)

| Layer | Approach |
|----|------|
| **UI layer** | per-platform locale resources (start with zh/en; the system supports any language); the server only sends error codes + template keys |
| **Content layer** | on publish, store the original text + auto language detection written into the `lang` field; on read, reader.lang ≠ author.lang → translation service (LLM/MT provider abstraction), results cached in Redis (bee_cache, TTL), with an `is_translated` flag to switch back to the original; popular content pre-warmed on a schedule |
| **Compliance layer** | moderation rules apply per region (EU GDPR rules vs other regions); bilingual report/moderation UI |

Danmaku is real-time short text: no content translation, only UI i18n + multilingual sensitive-word filtering.

## 6. IM Architecture

- **Gateway**: webman WS gateway, multi-instance with Redis pub/sub cross-node forwarding, `client_msg_id` idempotent deduplication
- **Data**: conversations / conversation_members / messages / message_reads; 1v1 + group chats (group cap 500)
- **Delivery**: online → direct WS push; offline → APNs/FCM/Huawei push
- **Capabilities**: read receipts, typing indicator, time-limited recall, image/voice messages (S3 upload + transcoding)
- Shares the user system and notification system with the feed

## 7. Live Streaming Architecture (video + danmaku + co-hosting, dual-track)

### 7.1 Provider abstraction (inside service)

```
LiveProvider 接口（admin 可配置）
├── provider_3rd   → 第三方直播云（默认主力）：推流/转码/CDN 分发/实时审核
└── provider_self  → 自建 SRS：推流/FFmpeg 转码/自有分发（审核调第三方审核 API）
```

| Mechanism | Design |
|------|------|
| Routing strategy | default provider chosen per region at room creation (admin-configurable override); regions without third-party coverage or sensitive to cost → self-hosted |
| Failure failover | broadcaster SDK dual ingest (primary = third-party, backup = self-hosted SRS); players resolve URLs by provider and automatically switch to the self-hosted stream if the third party fails |
| Danmaku/co-hosting | decoupled from the video pipeline: danmaku goes through service WS, co-hosting through third-party RTC |
| Compliance | the self-hosted pipeline's real-time audio/video moderation reuses third-party moderation APIs (buy moderation only, not transport) |

### 7.2 Live Rooms

Room CRUD, start/end streaming state machine, cover, announcements (multilingual), view counts (bee_tsdb), danmaku room channels (Redis pub/sub), co-host role management (host/co-host seats; service issues third-party RTC tokens), online/peak/duration statistics → admin dashboards.

## 8. Voice Architecture (three-in-one)

| Form | Implementation |
|------|------|
| Voice messages | IM message-type extension: S3 storage + transcoding (m4a) + duration |
| 1v1 calls | signaling over the WS gateway (offer/answer/ICE), ring/answer/hangup state machine (Redis), media plane over mediasoup, call records persisted |
| Voice chat rooms | room management reuses the live-room pattern; on-mic/off-mic/listener states managed by service; media plane over mediasoup |

## 9. Virtual Economy (top-ups + gift tipping + withdrawals)

```
移动端 IAP（App Store/Google Play/华为）──┐
国内：微信支付 / 支付宝（APP/H5）          ├─▶ PaymentProvider ─▶ 钱包
国外：微信国际 / 支付宝国际 / Stripe / PayPal│    （按 region 选路）
                                          └─▶ payments 支付单（幂等+验签+对账）
   礼物库(admin 上架) ──▶ 打赏：校验余额→扣款→礼物记录→
                         直播间特效事件广播(WS)→主播收入入账(分成)
主播钱包 ──▶ payouts 提现单 ──▶ 国内：商家转账 │ 国外：Stripe Connect/PayPal
```

### 9.1 Payment Channels (domestic vs. international)

```
PaymentProvider 接口（admin 配置）
├── 国内（CNY）
│   ├── wechat_cn    微信支付（APP/H5）
│   ├── alipay_cn    支付宝（APP/WAP）
│   └── 提现：商家转账（零钱/银行卡）
├── 国外（USD/EUR/...）
│   ├── wechat_global  微信国际支付（境外商户）
│   ├── alipay_global  支付宝国际（Alipay+）
│   ├── stripe         卡 / Apple Pay / Google Pay / SEPA
│   ├── paypal
│   └── 提现：Stripe Connect / PayPal 批量打款
└── 移动端虚拟币充值：App Store / Google Play / 华为 IAP（商店政策强制，服务端凭证校验）
```

| Mechanism | Design |
|------|------|
| Channel routing | channels chosen by user region + currency + admin merchant rules, with configurable fallback order (domestic/international naturally split) |
| Payment orders | unified payments model: user/channel/amount/currency/state machine, idempotent across all channels |
| Callbacks | unified signature verification wrapper (RSA/HMAC), idempotent callbacks, daily reconciliation job (verifies against channel statements) |
| Withdrawals | payouts: domestic merchant transfer; international Stripe Connect/PayPal payout; split-account/payout mode chosen by channel capability |
| Pricing | regional pricing tables (admin): virtual currency × currency prices, centrally managed FX rates |
| Risk control | limits/frequency caps/anomalous-order alerts, full audit trail (reuses the audit system) |
| Gift SKU | gift catalog (prices, effect identifiers, multilingual names) managed by admin |

Compliance: mobile virtual-currency top-ups must go through store IAP (Apple/Google/Huawei take a cut); WeChat/Alipay are used for H5/Web and region-specific scenarios; withdrawals involve fund settlement, so the platform lands them via licensed channels' split-account/payout interfaces; channel contracting qualifications to be confirmed before M6b; minors' limits enter the compliance phase.

## 10. Core Data Models

- Users: users, user_profiles (multilingual fields)
- Social: follows, posts, post_translations, comments, comment_translations, likes, reports
- IM: conversations, conversation_members, messages, message_reads
- Live: live_rooms, live_streams (with provider), danmaku_archive
- Voice: call_records, voice_rooms, voice_room_members
- Virtual economy: wallets, currency_transactions, gift_catalog, gifts_given, streamer_earnings, withdrawals, payments, payouts, price_plans (regional pricing/FX rates), merchant_configs (channel merchant configs), products (IAP SKUs)
- Platform: i18n_terms (terms shared by the four clients), moderation_queue, provider_configs, audit_logs

## 11. Database and Storage Choices

| Purpose | Storage | Component |
|------|------|----------|
| Business primary data (users/posts/IM/wallet/moderation/reports) | MySQL 8 (central primary + regional read-only replicas) | shared by service and admin; sole source of truth |
| Hot data/sessions/online status/counters/danmaku channels/call state machines | Redis 7 | bee_kv / bee_cache (redis feature) |
| Full-text search (post/user search, admin backend search) | OpenSearch (single-node to start) | bee_search (opensearch feature) |
| Time-series stats (DAU/trends/live views/call duration/dashboards) | QuestDB (single binary to start) | bee_tsdb (questdb feature, swappable with influxdb) |
| Social graph → recommendation feed | Neo4j Community (single-node to start) | bee_graph (neo4j feature, swappable with nebulagraph) |
| Object files (images/videos/voice/export packages) | S3 (MinIO or cloud provider) | service direct access + CDN delivery |
| Audit logs | MySQL audit_logs, archived to object storage on expiry | reuses the admin audit system |

Selection principles: bee-rust components are feature-flag abstractions — start single-node, swap in distributed backends as scale grows, no lock-in; MySQL is always the sole source of truth; the compute layer (indexes/stats/graph/cache) stores only reconstructible derived data. The admin frontend (Flutter) never touches the database directly; everything goes through the admin backend.

## 12. Deployment and Operations (global multi-region)

- **Initial architecture**: two major regions — China + overseas; each region runs a webman cluster + bee-rust cluster + local Redis + media (SFU/SRS/TURN); MySQL central primary + per-region read-only replicas; CDN split by region
- **WS edge access**: nearest-region connection; cross-region messages coordinated centrally; push routed to the corresponding vendor per region
- **Evolution path**: after traffic growth, shard databases by user hash
- **Monitoring**: Prometheus metrics (following the open-admin pattern), centralized logs, alerts (error rate/latency/queue backlog/media service health)

## 13. Security and Compliance

- service replicates open-admin's 18-layer defense pattern (XSS/SQLi/CSRF/rate limiting/CSP)
- Moderation pipeline: multilingual sensitive-word filter on publish → image/audio-video moderation (third-party APIs) → human moderation workbench
- GDPR: data export, erasure/deletion rights, log retention policy, minors' age gate, differentiated regional rules

## 14. Milestones (solo full-stack, ~9–10 months)

| Phase | Content | Duration |
|------|------|------|
| M0 Foundation | monorepo skeleton, contracts(gRPC) + three-platform stub generation + end-to-end liveness probes, three-platform project init, CI (build+test), bee-rust service skeleton | 1–2 weeks |
| M1 Closed loop | register/login/profile, post/feed detail, simplified timeline, likes and comments | 3–4 weeks |
| M2 Social complete | follow system, full feed, full-text search (bee_search), notifications | 3–4 weeks |
| M3 IM | WS gateway, conversations, messages, offline push, read/recall | 4–6 weeks |
| M4 Voice | media components (mediasoup+coturn), voice messages, 1v1 calls, voice chat rooms | 4–5 weeks |
| M5a Live primary | third-party pipeline, live rooms, danmaku, co-hosting | 3–4 weeks |
| M5b Live add-on | self-hosted SRS integration, dual-ingest failover, routing config | 2 weeks |
| M6a Virtual currency + gifts | IAP, wallet, gifts, revenue share | 2–3 weeks |
| M6b Payment channels | WeChat/Alipay/WeChat Global/Alipay Global/Stripe/PayPal, withdrawals, reconciliation | 3–4 weeks |
| M7 Multilingual + compliance | full-platform i18n, content translation, moderation workbench, GDPR, audio/video moderation integration | 3–4 weeks |
| M8 Launch | two-region deployment (incl. regional TURN), monitoring/alerts, load testing, security re-review | 2–3 weeks |

Each milestone is an independently shippable slice; the project can stop at any point with the product always fully usable.

## 15. Tech Stack Summary

| Subsystem | Technology |
|--------|------|
| service / admin | PHP 8.3+ / webman v2 / MySQL 8 / Redis 7 / S3 / grpc extension / snowflake-php |
| infrastructure | Rust / bee-rust workspace (search/graph/tsdb/kv/cache) / tonic |
| media | Node.js mediasoup / SRS / FFmpeg / coturn |
| contracts | protobuf / buf |
| apps | SwiftUI / Kotlin+Compose / ArkTS |
| External | third-party live cloud, third-party RTC, third-party moderation APIs, WeChat Pay/Alipay/WeChat Pay Global/Alipay Global/Stripe/PayPal, App Store/Google Play/Huawei IAP, APNs/FCM/Huawei push |

## 16. Team Planning (real staffing, steady cadence)

### 16.1 Org Structure

```
技术负责人 / PM（1人，兼任 contracts 契约 owner）
├── 后端组（2人）       webman service 主力 + admin 改造/支付专项
├── 平台组（2人）       Rust ×1（infrastructure）、音视频 ×1（media）
├── 客户端组（3人）     iOS、Android、HarmonyOS 各 1
├── 质量与运维（2人）   QA ×1、DevOps ×1
└── 支持（弹性）        UI/UX ×1（常驻）、支付/合规顾问（按需）、本地化（外包）
```

### 16.2 Role Details

| Role | People | Responsibilities | Key skills | Start |
|------|---|------|----------|------|
| Tech lead/PM | 1 | contracts(gRPC) owner, cross-subsystem coordination, milestone push | PHP/architecture/project management | M0 |
| Backend PHP · service | 1 | auth/posts/IM WS gateway/live-voice signaling/translation scheduling/moderation triggers/GDPR | webman/Redis/MySQL/WS | M0 |
| Backend PHP · admin+payments | 1 | open-admin 8-module rework, PaymentProvider all channels, reconciliation, withdrawals | PHP/payment channel experience | M0 (payments M6) |
| iOS engineer | 1 | SwiftUI client, APNs, WS, WebRTC integration, i18n | Swift/SwiftUI | M0 |
| Android engineer | 1 | Kotlin+Compose, FCM, WS, WebRTC, i18n | Kotlin/Compose | M0 |
| HarmonyOS engineer | 1 | ArkTS client, Push Kit, i18n | ArkTS/HarmonyOS ecosystem | M0 |
| Rust engineer | 1 | bee-rust service-ification (search/graph/tsdb) + tonic gRPC | Rust/axum/tonic | end of M1 |
| Audio/video engineer | 1 | media components (mediasoup/SRS/FFmpeg/coturn), dual-ingest failover, regional TURN deployment | Node.js/WebRTC/SRS/transcoding | end of M3 |
| UI/UX designer | 1 | three-platform design system, live/gift/voice visuals, i18n copy guidelines | Figma/multilingual design | M0 |
| QA | 1 | three-platform + backend + media regression, load testing, moderation/payment flow validation | mobile/API testing | M1 |
| DevOps | 1 | CI/CD, two-region deployment, Prometheus monitoring, media service ops, logging | Docker/K8s/Prometheus | M2 |
| Payments/finance advisor | flexible | channel contracting qualifications, reconciliation rules, risk-control limits, revenue-share settlement | payments industry/finance | from M6 |
| Compliance/legal advisor | flexible | GDPR, regional regulations, content moderation rules, store policies | data compliance | from M7 |
| Localization | outsourced | term translation and review, multilingual copy | translation/review | from M7 |

### 16.3 Milestone Cadence

| Phase | Team | Parallel focus |
|------|------|----------|
| M0–M2 | lead + 2 backend + 3 mobile + design + QA | contracts first; three platforms parallelize on OpenAPI; Rust joins for search |
| M3–M4 | + audio/video, DevOps | audio/video builds media in parallel with IM/voice |
| M5 | full team | dual-track live; backend supports media |
| M6 | + payments advisor | payments track + reconciliation |
| M7 | + compliance advisor, localization | i18n across all platforms + compliance wrap-up |
| M8 | full team assurance | two-region launch, load testing, security re-review |

### 16.4 Hiring Priorities

1. Backend PHP ×2 + tech lead (core of the foundation period; backend is the largest workload area)
2. Mobile ×3 (three-platform parallelism is the hard constraint on total timeline — earlier is better)
3. UI/UX, QA
4. Rust, DevOps (on board before M1–M2)
5. Audio/video (end of M3)
6. Payments/compliance advisors, localization (on demand at M6/M7)

### 16.5 Risks and Fallbacks

- Audio/video and payment channels are the two hardest roles to hire (scarce experts); reserve outsourcing/advisor fallback plans
- If a HarmonyOS engineer is hard to hire, an Android engineer can double up first (ArkTS shares its roots with TS and is quick to pick up); the three-platform parallel cadence is unaffected
