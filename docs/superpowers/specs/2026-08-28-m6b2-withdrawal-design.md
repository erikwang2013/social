# M6b2：提现 — 设计

- 日期: 2026-08-28
- 目标: 用户申请提现——余额扣减、client_ref 幂等建单、pending 单取消退回；渠道打款（微信/支付宝/Stripe Payout 实付）与对账归后续切片（需渠道签约资质）
- 原则: MySQL 是资金唯一事实源；申请即扣减（pending 资金已出余额，不加冻结列）；退回复用 `WalletService::credit` 幂等（currency_transactions UNIQUE(ref_type,ref_id)）；沿用 M6b 建单幂等模式

## 1. 数据模型（新增表，database/install.sql）

| 表 | 用途 | 关键字段 |
|------|------|------|
| social_withdrawals | 提现单，全渠道统一状态机 | user_id、platform(wechat/alipay/stripe)、account（收款账户 JSON，如 {"alipay":"u@x.com"}）、coins（提现虚拟币数，钱包单位；货币换算归打款切片）、currency（默认 CNY）、status(pending/cancelled/succeeded/failed)、reason（取消/失败原因）、client_ref（客户端幂等键，UNIQUE）、created_at/updated_at |

- 扣款流水: `WalletService::debit($uid, $coins, 'withdraw', "platform:client_ref", ..., 'withdraw')` —— 负向流水，UNIQUE(ref_type,ref_id) 幂等兜底
- 退回流水: `WalletService::credit($uid, $coins, 'withdraw_refund', "withdraw:{id}")` —— 每单唯一，重复取消不重复退

## 2. 核心流程

**申请** `POST /api/v1/wallet/withdraw`（登录态）
- 入参: platform、coins、currency、account（JSON 字符串）、client_ref
- 校验: platform 白名单 400；coins > 0 且 ≥ `config('payment.withdraw_min_coins', 100)`（默认 100 币）422；account 非空 422；client_ref 格式 400
- client_ref 已存在 → 幂等返回原单（含当前 status），不重复扣款
- 单事务: `social_withdrawals` 建 pending 单 + `WalletService::debit($uid, $coins, 'withdraw', ...)` 扣币；debit 余额不足 → 抛异常回滚 → 422 余额不足（无残留单）
- 返回提现单数据（id、coins、currency、status、balance）

**取消** `POST /api/v1/wallet/withdraw/{id}/cancel`（登录态，仅本人）
- lockForUpdate 查单；非本人 404；status ≠ pending → 422 已处理
- 单事务: status=pending → cancelled + reason='用户取消' + `credit` 退回
- 重复取消: 幂等返回原结果（credit 唯一索引兜底）

**列表** `GET /api/v1/wallet/withdrawals?page=`（登录态，本人）
- 20/页倒序，返回含 status/coins/reason/created_at

## 3. 分阶段（本切片 4 步）

1. 设计 + 骨架: install.sql social_withdrawals 表 + Withdrawal model + WithdrawalService（申请/取消/列表）+ 单测
2. 路由接入: /wallet/withdraw、/wallet/withdraw/{id}/cancel、/wallet/withdrawals（认证组）
3. 单测补全（幂等/余额不足/非本人/重复取消）
4. 收尾: 13 语言 README 里程碑行同步（提现已交付，对账后续）+ 提交

## 4. 契约（与 M6b 一致）

- 响应体沿用 `{"code":0,"message":"ok","lang_key":"ok","data":{...}}`
- 错误语义: 400 参数缺失/格式错；404 单不存在；422 余额不足/低于最低提现额/单已处理；503 不适用
- 幂等语义: client_ref 幂等建单返回原单；取消幂等（credit 唯一索引兜底）
- lang_key 前缀 `withdraw.*`（created / exists / params_invalid / below_min / insufficient / not_found / not_owner / already_processed / cancelled / refund_failed / list_failed）
- 渠道打款（真实转账）与对账归后续切片: 本切片仅维护 pending 单与余额状态机，打款结果标记（succeeded/failed）待渠道签约后接入
