# Social Service（用户端业务服务）

**语言 / Languages:** [中文](README.md) · [English](README.en.md)

webman v2（PHP 8.3）用户端业务服务：REST :8788 + WebSocket :8789 双通道；直播 / 语聊房 / 1v1 通话状态机已迁 Rust（infrastructure/bee-rust），PHP 控制器经 gRPC 直连。

## 功能

- **REST 接口**：auth / post / follow / im / voice / wallet / gift / payment / withdrawal 等控制器，`/api/v1` 路由组，`X-Api-Version` 版本化（默认 v1，兼容 `/api/vX` 旧路径）
- **WebSocket**：WsServer · Envelope 帧协议 · Deliverer 推送 · ConnectionRegistry
- **语音/直播**：1v1 通话 / 语聊房（8 麦位）/ 直播状态机由 Rust 承载，PHP 侧保留供 WS 信令
- **存储**：语音文件（m4a）经 Rust VoiceStorage（object_store，S3 兼容）上传分发，服务商管理端可配置
- **虚拟经济**：钱包（余额/流水，MySQL 唯一事实源）、礼物打赏与主播分成、移动端 IAP 充值
- **支付渠道**：微信 / 支付宝 / Stripe 回调验签、服务端定价、幂等入账；提现与内部对账

## 一键安装

前置要求：PHP ≥ 8.3（composer）、MySQL、Redis。

仓库根目录执行：

```bash
./install.sh
```

脚本会对 `service/` 与 `admin/` 各执行一次 `composer install`，以根目录 `database/install.sql` 建库（幂等），生成 `service/.env` 与 `admin/.env`（不覆盖已存在文件），并打印各服务启动命令与访问地址。

## 安装说明

1. 安装依赖：

```bash
cd service && composer install
```

2. 建库（service 与 admin 共用同一数据库）：

```bash
mysql -u root -p < ../database/install.sql
```

3. 配置环境：手工安装时复制 `.env.example` 为 `.env`，填入 DB / Redis / JWT 密钥（生产环境务必随机生成）；`install.sh` 会自动生成。按需配置 `REDIS`、`SFU_URL`（默认 127.0.0.1:8790）。

4. 启动服务：

```bash
php start.php start -d      # HTTP :8788 · WS :8789
```

## 使用说明

### 路由与进程

- `config/route.php`：`/api/v1` 路由组（默认 v1，兼容 `/api/vX` 旧路径）
- `config/process.php`：注册 HTTP :8788 与 WsServer :8789 两个进程
- `config/payment.php`：支付渠道密钥与定价

### 测试

```bash
vendor/bin/phpunit      # 单元测试（含 PaymentServiceTest / WalletServiceTest / VoiceStorageTest 等）

php tests/im_e2e.php          # IM 黑盒 E2E（需 :8788/:8789 运行中 + Redis）
php tests/voice_e2e.php       # 语音 E2E：版本化 / 语音消息 / 通话 / 语聊房
php tests/live_e2e.php        # 直播 E2E：房间 / 弹幕 / 上麦 / 关闭（RTMP 推流，HLS 拉流）
php tests/wallet_e2e.php      # 钱包 E2E：余额 / 流水 / 送礼分成
php tests/payment_e2e.php     # 支付 E2E：建单 / 回调验签 / 幂等入账
php tests/storage_e2e.php     # 存储 E2E：上传 URL 与活动服务商匹配（local/s3）
```

> 媒体层（SFU / coturn）本地调试：`cd media/sfu && npm run smoke`；容器方式 `docker compose up -d --build`。
