# M6a：虚拟币 + 礼物 — 设计

- 日期: 2026-08-28
- 目标: 虚拟币钱包、礼物打赏、主播分成、移动端 IAP 凭证校验（App Store / Google Play / 华为）；支付渠道（微信/支付宝/Stripe/PayPal、提现、对账）归 M6b
- 原则: MySQL 是资金唯一事实源（不走 Redis 记账）；扣款在单事务内完成；入账幂等（ref_type+ref_id 唯一约束）；移动端充值必须走商店 IAP

## 1. 数据模型（新增表，database/install.sql）

| 表 | 用途 | 关键字段 |
|------|------|------|
| wallets | 用户虚拟币余额 | user_id UNIQUE、coins BIGINT |
| currency_transactions | 资金流水（余额变更唯一记录） | user_id、type(recharge/gift_sent/gift_received/admin_adjust)、amount 有符号、balance_after、ref_type/ref_id UNIQUE、created_at |
| gift_catalog | 礼物目录（admin 上架） | name、coins_price、effect_key、status、sort |
| gifts_given | 送礼记录 | from_uid、to_uid、room_id、room_type、gift_id、quantity、coins_total |
| streamer_earnings | 主播分成入账 | streamer_uid、gift_given_id UNIQUE、ratio、coins_amount |
| products | IAP SKU ↔ 币值 | platform(apple/google/huawei)、sku、UNIQUE(platform,sku)、coins、status |

## 2. 核心流程

- **充值（IAP）**：客户端购买 → 服务端凭证校验（App Store verifyReceipt / Google purchases.products / 华为 IAP 订单校验）→ products 映射币值 → wallets 加币 + currency_transactions(recharge) 幂等入账（按平台事务/订单号）
- **送礼**：校验余额 → `UPDATE wallets SET coins=coins-? WHERE user_id=? AND coins>=?` → INSERT gifts_given + gift_sent 流水 + streamer_earnings（分成入账）→ 广播礼物特效（WS Envelope `live_gift`，复用既有 broadcast 通道）
- 分成比例 admin 可配（默认 7:3），gifts_given 与 streamer_earnings 同事务
- 查询：`GET /api/v1/wallet/balance`、`GET /api/v1/wallet/transactions?page=`、`GET /api/v1/gifts`（目录）

## 3. 分阶段

1. **骨架**: install.sql 表 + Wallet 服务（余额/流水/记账，事务+幂等）+ 单测
2. **礼物**: gift_catalog admin 模块 + 送礼流程 + WS 特效广播 + 主播分成 + 单测
3. **IAP**: products + 三端凭证校验 + 幂等入账 + sandbox 处理
4. **收尾**: wallet_e2e.php 黑盒 + 文档 12 语言同步

## 4. 契约（与既有体系一致）

- 响应体沿用 `{"code":0,"message":"ok","lang_key":"ok","data":{...}}`；余额不足 422；重复入账幂等成功
- WS 礼物事件 payload `{"type":"live_gift","data":{"room_id","from_uid","to_uid","gift_id","quantity","effect_key"}}`，走既有 `social:live:broadcast` 队列，PHP WS worker 无感知
- 错误语义 400/403/404/422 对齐既有路由
