# Social Platform

**语言 / Languages:** [中文](README.md) · [English](docs/README.en.md)

多语言社交平台 monorepo：图文社区 + 即时消息 + 直播/语音 + 虚拟经济；PHP + Rust 双栈，Android 原生客户端 + Flutter 管理后台。

## 项目宠物

<img src="docs/diagrams/mascot.svg" width="140" alt="Bub — 项目宠物">

**Bub** · 消息气泡 —— 平台上最小的单位：图文、语音、直播弹幕、礼物打赏，最后都装进一只气泡里送出去；它会笑、会招手，左下那条尾巴指着消息要去的地方。

矢量源文件 [`docs/diagrams/mascot.svg`](docs/diagrams/mascot.svg)；简化标记 [`mascot-mark.svg`](docs/diagrams/mascot-mark.svg) 另作 `favicon.svg`，已嵌入 service / admin 站点与管理后台安装向导。


<!-- pet-expressions:start -->
## 项目宠物表情形态 · Bub

<table align="center">
<tr><td align="center"><img src="docs/pet-expressions/smile.svg" width="88" alt="笑"><br><b>笑</b><br><code>:emoji-smile:</code></td><td align="center"><img src="docs/pet-expressions/joy.svg" width="88" alt="欢乐"><br><b>欢乐</b><br><code>:emoji-joy:</code></td><td align="center"><img src="docs/pet-expressions/cry.svg" width="88" alt="哭"><br><b>哭</b><br><code>:emoji-cry:</code></td><td align="center"><img src="docs/pet-expressions/depressed.svg" width="88" alt="沮丧"><br><b>沮丧</b><br><code>:emoji-depressed:</code></td><td align="center"><img src="docs/pet-expressions/terrified.svg" width="88" alt="惊恐"><br><b>惊恐</b><br><code>:emoji-terrified:</code></td><td align="center"><img src="docs/pet-expressions/angry.svg" width="88" alt="愤怒"><br><b>愤怒</b><br><code>:emoji-angry:</code></td></tr>
<tr><td align="center"><img src="docs/pet-expressions/coquettish.svg" width="88" alt="撒娇"><br><b>撒娇</b><br><code>:emoji-coquettish:</code></td><td align="center"><img src="docs/pet-expressions/cute.svg" width="88" alt="卖萌"><br><b>卖萌</b><br><code>:emoji-cute:</code></td><td align="center"><img src="docs/pet-expressions/pleasing.svg" width="88" alt="讨好"><br><b>讨好</b><br><code>:emoji-pleasing:</code></td><td align="center"><img src="docs/pet-expressions/knocking.svg" width="88" alt="敲打"><br><b>敲打</b><br><code>:emoji-knocking:</code></td><td align="center"><img src="docs/pet-expressions/facepalm.svg" width="88" alt="捂脸"><br><b>捂脸</b><br><code>:emoji-facepalm:</code></td><td align="center"><img src="docs/pet-expressions/tongue.svg" width="88" alt="吐舌头"><br><b>吐舌头</b><br><code>:emoji-tongue:</code></td></tr>
</table>

> 12 种彩色动态形态：笑 / 欢乐 / 哭 / 沮丧 / 惊恐 / 愤怒 / 撒娇 / 卖萌 / 讨好 / 敲打 / 捂脸 / 吐舌头。每个 SVG 自带表情、情绪特效与整体动效，并遵循 `prefers-reduced-motion`。
<!-- pet-expressions:end -->

<!-- assets-license:start -->
## 视觉资产授权 / Visual Assets License

本项目的项目宠物、图片、图标、表情（包括 `docs/pet-expressions/` 等目录下的
SVG / PNG）**不是开源资产**，版权归作者所有。未经书面许可不得复制、修改、再分发或
用于商业用途；商业使用需取得商业授权，联系 **erik@erik.xyz**。详见
[ASSETS-LICENSE.md](ASSETS-LICENSE.md)。

> 代码部分仍遵循仓库原有 `LICENSE`；第三方素材（如明确标注的 Go Gopher 等）仍遵循其原许可证。
<!-- assets-license:end -->


## 项目介绍

- **原生客户端**：Android（Kotlin + Compose），另有 Flutter 管理后台
- **业务服务**：webman v2（PHP 8.3）承载 REST 与 WebSocket 双通道；直播 / 语聊房 / 1v1 通话状态机已迁 Rust（infrastructure/bee-rust），PHP 控制器经 gRPC 直连；API 通过 `X-Api-Version` 版本化（默认 v1，兼容 `/api/vX` 旧路径）
- **自建媒体层**：mediasoup SFU + coturn TURN，1v1 语音通话与语聊房（8 席：房主 + 7 麦位）媒体转发
- **状态分层**：MySQL 为业务事实，Redis 承载会话 / IM / 通话 / 房间实时状态
- **里程碑**：M0–M5 已交付（语音消息、1v1 通话、语聊房、直播）；M6 交付 live/voice 状态机 Rust 化迁移（PHP 经 gRPC 直连 Rust，熔断/降级/限流）；M6a 交付虚拟经济：钱包（余额/流水，MySQL 唯一事实源）、礼物打赏与主播分成、移动端 IAP 充值（App Store / Google Play / 华为）；M6b 交付支付渠道：充值入账骨架（微信/支付宝/Stripe 回调验签、服务端定价、幂等入账；提现与内部对账均已交付）；M6c 交付 CDN 存储：服务商管理端可配置（S3 兼容：AWS S3 / Cloudflare R2 / 阿里 OSS / 腾讯 COS / Backblaze B2），图片/语音/文件经对象存储 + CDN 分发；M6d 交付管理报表与起始页统计：报表模块（用户/支付/提现——日期筛选、汇总、趋势、分布、Excel 导出），起始页（仪表盘）新增平台统计卡片；v1.1 交付主键治理：bee_live 的 3 处 MySQL 写入改用 idgen_rs snowflake 显式主键，去除对 last_insert_id() 的依赖，对应表去 AUTO_INCREMENT；v1.1.1–v1.1.6 为文档 / CI / 稳定性修复，无新功能

## 功能总览

![功能总览](docs/diagrams/features.svg)

图内功能随里程碑持续更新，最新纳入管理报表（用户 / 支付 / 提现）与起始页平台统计。

## 架构设计

![架构设计](docs/diagrams/architecture.svg)

## 业务核心流程

![业务核心流程](docs/diagrams/core-flow.svg)

## 生命周期

![生命周期](docs/diagrams/lifecycle.svg)

## 功能设计

![功能设计](docs/diagrams/module-design.svg)

## 项目结构

![项目结构](docs/diagrams/structure.svg)

| 目录 | 说明 | 技术 |
|------|------|------|
| contracts/ | gRPC 契约（proto，buf 生成入口） | protobuf / buf |
| service/ | 用户端业务服务（REST :8788 + WS :8789） | webman v2 (PHP 8.3) |
| admin/ | 管理后台（open-admin 改造） | webman v2 + Flutter |
| infrastructure/ | 高吞吐计算层（live/voice gRPC 服务） | bee-rust (tonic) |
| media/sfu/ | 自建媒体层（mediasoup SFU :8790 + coturn :3478） | Node.js（M4 启用） |
| apps/ | Android 原生客户端 | Kotlin + Compose |

service 内部结构：

```
service/
├── app/
│   ├── controller/   # REST 控制器（auth/post/follow/im/voice/wallet/gift/payment/...）
│   ├── common/        # WalletService（余额/流水/幂等记账）· GiftService（送礼/分成）· PaymentService（幂等建单/入账）· PaymentVerifier（三渠道验签）
│   ├── ws/           # WsServer · Envelope 帧协议 · Deliverer 推送 · ConnectionRegistry
│   ├── call/         # CallCenter：1v1 通话状态机（M6 迁 Rust，PHP 侧保留供 WS 信令）
│   ├── room/         # RoomCenter：语聊房（M6 迁 Rust，PHP 侧保留供 WS 信令）
│   ├── live/         # LiveCenter：直播房（M6 迁 Rust，PHP 侧保留供 WS 信令）
│   ├── model/        # 数据模型
│   ├── process/      # Http / WsServer 自定义进程
│   └── storage/      # 语音文件存储（m4a；M6 起由 Rust VoiceStorage 承载）
├── config/           # route.php（/api/v1 路由组）· process.php（:8788/:8789）· payment.php（渠道密钥/定价）
└── tests/            # phpunit 单元测试（含 PaymentServiceTest）+ im_e2e.php / voice_e2e.php / live_e2e.php / wallet_e2e.php / payment_e2e.php 黑盒 E2E
```

## 一键安装

前置要求：PHP ≥ 8.3（composer）、MySQL、Redis（Docker 可选，用于媒体层）。

```bash
./install.sh
```

脚本会依次：对 `service/` 与 `admin/` 各执行一次 `composer install`；以 `database/install.sql` 建库（幂等，CREATE IF NOT EXISTS）；为两个服务生成 `.env`（随机 JWT / APP 密钥，不覆盖已存在文件）；可选启动媒体层（`docker compose up -d` 起 media/sfu 与 coturn，`--skip-media` 跳过）；最后打印各服务启动命令与访问地址。

## 安装说明（手工安装）

1. 安装依赖：

```bash
cd service && composer install
cd admin && composer install
```

2. 建库：

```bash
mysql -u root -p < database/install.sql
```

3. 配置环境：将 `service/.env.example`、`admin/.env.example` 复制为 `.env`，填入 DB / Redis / JWT / APP 密钥（生产环境务必使用随机密钥）。

4. 启动服务：

```bash
cd service && php start.php start -d   # HTTP :8788 · WS :8789
cd admin && php start.php start -d     # admin :8787
```

5. 启动媒体层（可选）：

```bash
cd media/sfu && docker compose up -d --build   # SFU :8790 · coturn :3478
```

## 使用说明

### 依赖

- PHP ≥ 8.3（composer）
- Redis（默认 127.0.0.1:6379）
- Node.js ≥ 18（SFU 本地调试）
- Docker（SFU / coturn 容器）

### 启动业务服务

```bash
cd service
composer install
php start.php start -d      # HTTP :8788 · WS :8789
```

按需在 `service/.env` 配置 `REDIS`、`SFU_URL`（默认 127.0.0.1:8790）。

### 启动媒体层

```bash
cd media/sfu
docker compose up -d --build   # SFU :8790（RTC UDP 10000-10200）· coturn :3478
```

### 客户端

| 端 | 打开 / 构建方式 | 平台要求 |
|----|----------------|----------|
| Android | `cd apps/android && ./gradlew assembleDebug` | Linux / macOS 可构建 |

### 测试

```bash
cd service && vendor/bin/phpunit      # service 单元测试（229 tests / 660 assertions；直播与语音用例需 Rust gRPC 服务在跑）
cd admin   && vendor/bin/phpunit      # admin 单元测试（98 tests / 340 assertions）
cd infrastructure && cargo test --workspace   # Rust 17 crates（204 tests）
DB_PASS='' php tests/api/run.php      # API 自动化（116 用例，需先起 admin :8791 / service :8788）
cd tests/e2e && npx playwright test   # UI 端到端（41 用例）

cd service                            # 以下黑盒 E2E 需 service 运行中（:8788/:8789）+ Redis
php tests/im_e2e.php                  # IM：双用户 WS 收发 / 已读 / 撤回 / 离线队列
php tests/voice_e2e.php               # 语音：API 版本化 / 语音消息 / 1v1 通话信令 / 语聊房
php tests/live_e2e.php                # 直播：开播 / 进房 / 弹幕 / 上下麦 / 关播
php tests/wallet_e2e.php              # 钱包：余额 / 充值 / 送礼 / 主播分成
php tests/payment_e2e.php             # 支付：建单 / 回调验签入账 / 幂等 / 金额校验
php tests/storage_e2e.php             # 存储：图片上传 / local 与 s3 / URL 前缀与活动服务商匹配

cd media/sfu
npm run smoke                         # SFU /signal 协议冒烟（需 Docker 容器或本地 node）
```

测试报告（全量汇总 + 4 份分报告，13 种语言）本地存储于 [`docs/test-reports/`](docs/test-reports/SUMMARY.md)。

## 文档

- 总体设计：`docs/superpowers/specs/2026-08-16-social-platform-design.md`
- M4 语音设计：`docs/superpowers/specs/2026-08-17-m4-voice-design.md`
- 实施计划：`docs/superpowers/plans/2026-08-17-m4-voice.md`
- M6d 报表 + 起始页统计设计：`docs/superpowers/specs/2026-08-31-m6d-reports-dashboard-design.md`
- M6d 实施计划：`docs/superpowers/plans/2026-08-31-m6d-reports-dashboard.md`

## 欢迎支持

如果这个项目对你有帮助，欢迎扫描二维码打赏支持，谢谢！

**微信支付**

<img src="docs/weixinpay.png" width="160" height="175" alt="微信支付">

**支付宝**

<img src="docs/alipay.png" width="160" height="175" alt="支付宝">


## 项目授权 / Project License

Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz

- 本项目仅供个人学习、研究使用。
- 未经作者书面授权，禁止任何形式的商业使用（包括但不限于商业部署、付费服务、二次销售、SaaS 运营及以本项目为基础提供商业服务）。
- 商业授权请联系 **erik@erik.xyz**。
- 未经授权的商业使用，作者将在全球范围内依法追究法律责任，并保留终身追责的权利。
- 第三方组件仍遵循其各自原有的许可证；视觉资产适用仓库内的 ASSETS-LICENSE.md（如适用）。

**English:** This project is provided for personal study and research only. Commercial use of any kind requires prior written authorization from the author (erik@erik.xyz). Unauthorized commercial use will be pursued for legal liability worldwide, and the author reserves the right to pursue such liability for life. Third-party components remain under their original licenses; visual assets are governed by ASSETS-LICENSE.md where applicable.
