# M6b：支付渠道 — 设计

- 日期: 2026-08-28
- 目标: 微信/支付宝/Stripe 充值收款——建单、渠道异步回调验签、幂等入账；提现、对账、paypal/微信国际/支付宝国际、渠道签约资质、Flutter/前端集成归后续切片
- 原则: MySQL 是资金唯一事实源（不走 Redis 记账）；入账复用 `WalletService::credit` 幂等（currency_transactions UNIQUE(ref_type,ref_id)）；渠道未配置返回 503 不发网络请求（沿用 IapVerifier 模式）；回调验签失败不入账

## 1. 数据模型（新增表，database/install.sql）

| 表 | 用途 | 关键字段 |
|------|------|------|
| social_payments | 支付单，全渠道统一状态机 | user_id、platform(wechat/alipay/stripe)、trade_no（渠道交易号，UNIQUE(platform,trade_no)，回调前可空）、client_ref（客户端幂等键，UNIQUE 可空）、amount_cents（实付分）、currency（如 CNY/USD）、coins（到账币值）、status(pending/succeeded/failed)、payload（渠道原始回调 JSON，审计用）、created_at/updated_at |

- trade_no 建单时为空，渠道支付成功后由回调写入；MySQL 唯一索引允许多个 NULL，pending 单互不冲突
- client_ref 由客户端生成（UUID），唯一索引兜底并发建单

## 2. 核心流程

**建单** `POST /api/v1/payment/order`（登录态）
- 入参: platform、amount_cents、currency、client_ref
- client_ref 已存在 → 幂等返回原单（含当前 status），不新建
- 否则新建 pending 单；coins 由服务端按 amount_cents+currency 定价映射得出（定价表后续切片，本切片走 `config('payment.pricing')` 兜底）
- 返回订单数据（id、amount_cents、currency、coins、status），客户端据此唤起渠道 SDK 支付

**回调** `POST /api/v1/payment/callback/{platform}`（无鉴权，验签即鉴权）
- 原始 body + 请求头原样传入 PaymentVerifier（注入式 `?callable $verify`，单测可替换，默认真实实现，沿用 IapService 模式）
- 验签失败 → 403（渠道会重试，便于告警发现）；渠道未配置 → 503（不发网络请求）
- 按 (platform, trade_no) 查单：
  - 不存在 → 404（记录日志，防伪造回调）
  - 已 succeeded → 幂等返回原结果，不再入账
  - pending → 单事务: `social_payments.status=succeeded` + `WalletService::credit($uid,$coins,'payment',"platform:trade_no")` 加币
  - 回调金额 ≠ 订单 amount_cents → 单置 failed，422
- Stripe 集成契约: Checkout/PaymentIntent 必须带 `client_reference_id`（透传为 out_trade_no），否则首回调只能按 trade_no 查 pending 单会 404（微信/支付宝原生带 out_trade_no 无此问题）
- 状态机: pending → succeeded（终态）/ failed（终态）；succeeded 后任何重复回调直接返回原结果

## 3. 分阶段（本切片 3 步）

1. **骨架**: install.sql social_payments 表 + PaymentService（建单幂等 / 回调状态机 / 单事务入账）+ 单测
2. **三渠道验签**: PaymentVerifier——wechat RSA 验签 / alipay RSA2 验签 / stripe HMAC 验签，密钥走 `config('payment.*')`（env 驱动），未配置 503；回调路由接入
3. **收尾**: payment_e2e.php 黑盒（注入 mock verifier 模拟回调）+ 单测补全 + 文档 12 语言同步

## 4. 契约（与既有体系一致）

- 响应体沿用 `{"code":0,"message":"ok","lang_key":"ok","data":{...}}`
- 错误语义: 400 参数缺失/格式错；403 验签失败；404 单不存在；422 参数非法/金额不一致；503 渠道未配置
- 幂等语义: client_ref 幂等建单返回原单；trade_no 幂等回调返回原结果；入账幂等由 currency_transactions UNIQUE(ref_type,ref_id) 兜底（并发重复回调场景）
- lang_key 前缀 `payment.*`（order_created / order_exists / callback_verified / verify_failed / channel_not_configured / order_not_found / amount_mismatch）
- 回调成功必须 HTTP 2xx，渠道才停止重试；验签失败返回非 2xx 触发渠道重试
