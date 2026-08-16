# 社交平台整体设计（Social Platform Design）

- 日期：2026-08-16
- 状态：已确认，待实现
- 范围：图文短内容社区 + 即时通讯 + 直播/语音 + 虚拟经济，多语言、全球多区域

## 1. 目标与范围

打造一个图文短内容 + IM 的社交平台，附带直播（视频+弹幕+连麦）、语音（消息/1v1 通话/语聊房）、礼物打赏虚拟经济。支持 UI 多语言、内容翻译、多地区合规，全球多区域部署。iOS / Android / HarmonyOS 三端并行原生开发。

## 2. 系统总览

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

## 3. 子系统职责

### 3.1 contracts（gRPC 契约，新增顶层目录）

```
contracts/
├── buf.yaml                      # buf 配置（唯一生成入口）
├── common/types.proto            # 分页、错误、时间戳、区域枚举等公共类型
├── infra/infra_service.proto     # infrastructure 对外服务
├── user/user_service.proto       # service 对外服务（admin 调用用）
└── admin/admin_service.proto     # admin 对外服务（service/infra 调用用）
```

- 生成管线：CI 用 buf 生成三类桩并提交到各自子仓库（构建不依赖网络）
  - service/、admin/ → PHP 桩（grpc/grpc + google/protobuf）
  - infrastructure/ → Rust 桩（tonic）
- 版本规则：只加字段不改删，包名带 major 版本（`social.user.v1`）

### 3.2 service（webman v2）— 用户端业务单体

- **API 域**：auth（JWT 双令牌 + 黑名单）、profile、posts、likes、comments、follows、IM（会话/消息/WS 网关）、notifications、翻译调度、直播间/弹幕/连麦信令、语音通话/语聊房信令、虚拟经济（钱包/礼物/IAP 校验/分成）、GDPR 导出/删除
- **多语言错误体系**：错误返回 `{code, lang_key, params}`，文案由客户端按 locale 渲染
- **队列**（redis-queue）：审核触发、翻译调度、推送投递、异步统计、礼物特效广播
- **定时**（webman-crontab）：翻译预热、过期 token/消息清理、审计归档、分成结算
- **ID**：`erikwang2013/snowflake-php`（与 admin 一致）
- **契约**：OpenAPI 3.0 自动导出 → 三端生成类型化客户端

### 3.3 infrastructure（bee-rust）— 高吞吐计算层

不存业务主数据（MySQL 是唯一事实源），承接计算重/查询重能力：

- `bee_search`：动态/用户全文检索（中文分词、多语言索引）
- `bee_graph`：社交图 → 推荐流
- `bee_tsdb`：DAU、发帖、互动、直播观看、语音通话时长等时序统计
- `bee_cache/bee_kv`：时间线缓存、计数器（点赞数、观看数、在线人数）
- 随区域部署，读多写少，数据从中央复制

### 3.4 admin（open-admin 改造）

**复用**：JWT/RBAC/审计/文件管理/健康检查/中英 i18n 基建

**新增**：
- 内容审核工作台：动态/评论/图片双语对照审核、驳回原因多语言模板、用户处罚
- 举报处理队列
- GDPR 请求台（导出/删除工单）
- 数据看板对接 bee_tsdb
- i18n 词条管理（四端共用词条 CRUD）
- 礼物库管理（SKU、价格、特效、多语言名称）
- 直播 provider 配置（选路策略、切换顺序）
- 提现申请审核

### 3.5 media（自建媒体层，Node.js + 系统服务）

- `sfu/`：mediasoup，承载 1v1 通话、语聊房媒体面；只做媒体转发不做业务
- `srs/`：SRS 自建直播，RTMP 推流 → FFmpeg 转码 → HTTP-FLV/HLS 分发
- `coturn/`：TURN 中继，NAT 穿透兜底
- 信令一律复用 service 的 WS 网关转发

### 3.6 apps — 三端原生并行

- 共享 OpenAPI 契约，每端独立生成类型化客户端
- 统一基建模块：网络层（重试/鉴权刷新）、WS 客户端（IM/弹幕/通话信令）、i18n（本地资源 + 远程词条增量）、推送注册、主题
- HarmonyOS 注意：华为 Push Kit、ArkTS 并发模型适配

## 4. 后端通信（gRPC）

```
 service (webman/PHP) ──gRPC──▶ infrastructure (bee-rust/tonic)
      │                            ▲
      │ gRPC                        │ gRPC
      ▼                            │
 admin (webman/PHP) ──────gRPC─────┘
   （admin→service：封号/删内容/审核结果回调）
```

| 调用方 → 被调方 | 承载内容 |
|------|------|
| service → infra | 全文搜索、推荐流、时间线热缓存、计数读写、时序统计写入 |
| admin → infra | 看板统计查询、后台搜索 |
| admin → service | 用户处罚、内容删除、审核结果下发 |
| service → admin | 举报事件、审核任务入队（异步） |

边界：三端 App 与管理端前端（Flutter）走 HTTPS REST + WS，不直接碰 gRPC。

**运维前提**：PHP 侧 gRPC 依赖官方 `grpc` 扩展（C 扩展）+ `grpc/grpc` composer 包，服务端模式参照 workerman 官方 walkor/grpc 方案，部署文档需写清。

## 5. 多语言架构（三层）

| 层 | 方案 |
|----|------|
| **UI 层** | 每端 locale 资源（起步 zh/en，体系支持任意语言）；服务端只发错误码 + 模板 key |
| **内容层** | 发布时存原文 + 自动语言检测写入 `lang` 字段；读取时 reader.lang ≠ author.lang → 翻译服务（LLM/MT provider 抽象），结果缓存 Redis（bee_cache，TTL），带 `is_translated` 标记可切回原文；热门内容定时预热翻译 |
| **合规层** | 审核规则按区域生效（欧盟 GDPR 规则 vs 其他区）；举报/审核界面双语 |

弹幕是实时短文本，不做内容翻译，只做 UI i18n + 多语言敏感词过滤。

## 6. IM 架构

- **网关**：webman WS 网关，多实例 + Redis pub/sub 跨节点转发，`client_msg_id` 幂等去重
- **数据**：conversations / conversation_members / messages / message_reads；私聊 + 群聊（群上限 500）
- **投递**：在线 → WS 直推；离线 → APNs/FCM/华为推送
- **能力**：已读回执、正在输入、限时撤回、图片/语音消息（S3 上传 + 转码）
- 与动态流共用用户体系和通知体系

## 7. 直播架构（视频 + 弹幕 + 连麦，双轨制）

### 7.1 Provider 抽象（service 内）

```
LiveProvider 接口（admin 可配置）
├── provider_3rd   → 第三方直播云（默认主力）：推流/转码/CDN 分发/实时审核
└── provider_self  → 自建 SRS：推流/FFmpeg 转码/自有分发（审核调第三方审核 API）
```

| 机制 | 设计 |
|------|------|
| 选路策略 | 直播间创建时按区域默认 provider（admin 可配置覆盖）；无第三方覆盖或成本敏感区域 → 自建 |
| 故障容灾 | 主播端 SDK 双路推流（主=第三方，备=自建 SRS）；播放端按 provider 解析 URL，第三方故障自动切自建流 |
| 弹幕/连麦 | 与视频管线解耦：弹幕走 service WS，连麦走第三方 RTC |
| 合规 | 自建管线的实时音视频审核复用第三方审核 API（只买审核，不买承载） |

### 7.2 直播间

房间 CRUD、开播/关播状态机、封面、公告（多语言）、观看计数（bee_tsdb）、弹幕房间频道（Redis pub/sub）、连麦角色管理（主播/连麦位，service 签发第三方 RTC token）、在线/峰值/时长统计 → admin 看板。

## 8. 语音架构（三件套）

| 形态 | 实现 |
|------|------|
| 语音消息 | IM 消息类型扩展：S3 存储 + 转码（m4a）+ 时长 |
| 1v1 通话 | 信令走 WS 网关（offer/answer/ICE），铃响/接听/挂断状态机（Redis），媒体面走 mediasoup，通话记录落库 |
| 语聊房 | 房间管理复用直播间模式，上麦/下麦/听众由 service 管状态，媒体面走 mediasoup |

## 9. 虚拟经济（礼物打赏）

```
移动端 IAP（App Store / Google Play / 华为 IAP）──▶ service 钱包
                                                    │
   礼物库(admin 上架) ──▶ 打赏：校验余额→扣款→礼物记录→
                         直播间特效事件广播(WS)→主播收入入账(分成)
                                                    │
                              主播钱包 ──▶ 提现申请(admin 审核) ──▶ 支付网关(预留)
```

| 要点 | 说明 |
|------|------|
| 充值合规 | 移动端虚拟币必须走商店 IAP（Apple/Google/华为抽成），服务端只做 IAP 凭证校验 |
| 礼物 SKU | 礼物目录（价格、特效标识、多语言名称）由 admin 管理 |
| 流水审计 | 币余额变动全量流水 + 审计（复用 admin 审计体系） |
| 提现 | 接口预留，接入具体支付网关时再做；区域定价、未成年人限额进合规阶段 |

## 10. 核心数据模型

- 用户：users、user_profiles（多语言字段）
- 社交：follows、posts、post_translations、comments、comment_translations、likes、reports
- IM：conversations、conversation_members、messages、message_reads
- 直播：live_rooms、live_streams（含 provider）、danmaku_archive
- 语音：call_records、voice_rooms、voice_room_members
- 虚拟经济：wallets、currency_transactions、gift_catalog、gifts_given、streamer_earnings、withdrawals、products（IAP SKU）
- 平台：i18n_terms（四端共用词条）、moderation_queue、provider_configs、audit_logs

## 11. 数据库与存储选型

| 用途 | 存储 | 落地组件 |
|------|------|----------|
| 业务主数据（用户/动态/IM/钱包/审核/举报） | MySQL 8（中央主库 + 区域只读副本） | service 与 admin 共用，唯一事实源 |
| 热数据/会话/在线状态/计数器/弹幕频道/通话状态机 | Redis 7 | bee_kv / bee_cache（redis 特性） |
| 全文检索（动态/用户搜索、admin 后台搜索） | OpenSearch（单机起步） | bee_search（opensearch 特性） |
| 时序统计（DAU/趋势/直播观看/通话时长/看板） | QuestDB（单二进制起步） | bee_tsdb（questdb 特性，可换 influxdb） |
| 社交图 → 推荐流 | Neo4j 社区版（单机起步） | bee_graph（neo4j 特性，可换 nebulagraph） |
| 对象文件（图片/视频/语音/导出包） | S3（MinIO 或云厂商） | service 直连 + CDN 分发 |
| 审计日志 | MySQL audit_logs，到期归档对象存储 | 复用 admin 审计体系 |

选择原则：bee-rust 各组件为特性开关抽象，单机起步、随规模换分布式后端，不锁死；MySQL 始终是唯一事实源，计算层（索引/统计/图/缓存）只存可重建的派生数据。管理端前端（Flutter）不直接碰数据库，全部经 admin 后端。

## 12. 部署与运维（全球多区域）

- **起步架构**：中国区 + 海外区两大区；每区 webman 集群 + bee-rust 集群 + 本地 Redis + media（SFU/SRS/TURN）；MySQL 中央主库 + 各区只读副本；CDN 分区域
- **WS 就近接入**，跨区消息经中央协调；推送按区走对应厂商
- **演进路径**：流量增长后按用户 hash 分库分片
- **监控**：Prometheus metrics（沿用 open-admin 模式）、集中日志、告警（错误率/延迟/队列积压/媒体服务健康）

## 13. 安全与合规

- service 复刻 open-admin 的 18 层防御模式（XSS/SQLi/CSRF/限流/CSP）
- 审核管线：发布时多语言敏感词 → 图片/音视频审核（第三方 API）→ 人工审核台
- GDPR：数据导出、注销/删除权、日志留存策略、未成年人年龄门槛、区域规则差异化

## 14. 里程碑（单人全栈，约 8–9 个月）

| 阶段 | 内容 | 周期 |
|------|------|------|
| M0 地基 | monorepo 骨架、contracts(gRPC)+三端桩生成+端到端探活、三端工程初始化、CI(build+test)、bee-rust 服务骨架 | 1–2 周 |
| M1 闭环 | 注册/登录/资料、发动态/详情、简化时间线、点赞评论 | 3–4 周 |
| M2 社交完整 | 关注体系、完整信息流、全文搜索(bee_search)、通知 | 3–4 周 |
| M3 IM | WS 网关、会话、消息、离线推送、已读撤回 | 4–6 周 |
| M4 语音 | media 组件（mediasoup+coturn）、语音消息、1v1 通话、语聊房 | 4–5 周 |
| M5a 直播主力 | 第三方管线、直播间、弹幕、连麦 | 3–4 周 |
| M5b 直播补充 | 自建 SRS 接入、双推容灾、选路配置 | 2 周 |
| M6 虚拟经济 | IAP、钱包、礼物、分成、提现接口 | 4–5 周 |
| M7 多语言+合规 | 全端 i18n、内容翻译、审核台、GDPR、音视频审核接入 | 3–4 周 |
| M8 上线 | 双区部署（含 TURN 区域）、监控告警、压测、安全复查 | 2–3 周 |

每个里程碑是独立可交付切片，中途可停，产品始终完整可用。

## 15. 技术栈汇总

| 子系统 | 技术 |
|--------|------|
| service / admin | PHP 8.3+ / webman v2 / MySQL 8 / Redis 7 / S3 / grpc 扩展 / snowflake-php |
| infrastructure | Rust / bee-rust workspace（search/graph/tsdb/kv/cache）/ tonic |
| media | Node.js mediasoup / SRS / FFmpeg / coturn |
| contracts | protobuf / buf |
| apps | SwiftUI / Kotlin+Compose / ArkTS |
| 外部 | 第三方直播云、第三方 RTC、第三方审核 API、App Store/Google Play/华为 IAP、APNs/FCM/华为推送 |
