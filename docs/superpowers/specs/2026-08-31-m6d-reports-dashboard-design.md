# M6d 管理报表 + 起始页统计 + 一键安装 设计

**日期**: 2026-08-31
**里程碑**: M6d
**状态**: 已批准

## 1. 背景与目标

M6a–M6c 已交付虚拟经济、支付渠道、CDN 存储。管理后台（Flutter admin + open-admin PHP）已有仪表盘（展示 admin 用户/日志），但缺少面向业务数据的报表与平台统计；仓库 README 家族（根 + 12 语言 + service/admin）普遍缺一键安装与安装说明；service/README 仍是 webman 模板。

M6d 目标：

1. **管理端报表功能**：新增「报表」模块（用户/支付/提现三类），日期范围筛选 + 汇总 + 趋势 + 分布 + Excel 导出
2. **起始页统计功能**：Flutter admin 起始页（仪表盘）增加平台统计卡片（社交用户/支付/提现真实业务数据）
3. **一键安装**：根 `install.sh` 一条命令完成依赖安装 + 建库 + 配置 + 启动
4. **文档收尾**：13 语言 README 补一键安装/安装/使用说明，更新功能总览与 features.svg

## 2. 数据源

admin 模型直连 service 库（`connection='social'`，`social_` 前缀无前缀表）：

| 报表 | 模型 | 表 | 聚合维度 |
|------|------|-----|---------|
| 用户报表 | `SocialUser` | social_users | 区间新增/总用户/活跃趋势（按日）、状态分布 |
| 支付报表 | `Payment` | social_payments | 订单数/成功金额、按日趋势、渠道(platform)/状态分布 |
| 提现报表 | `Withdrawal` | social_withdrawals | 笔数/金额、按日趋势、状态分布 |

仪表盘扩展同源：平台统计卡片（社交用户总数/今日新增、支付订单数/今日充值金额、提现笔数/今日金额）。

## 3. 接口设计

### 3.1 报表接口（ReportController，`/admin/report/*`，鉴权 + 操作日志）

- `GET /admin/report/users?start&end` — 用户报表：`{ stats: {total, new_in_range, active_today}, daily: [{date, new, active}], status_distribution }`
- `GET /admin/report/payments?start&end` — 支付报表：`{ stats: {orders, succeeded_amount_cents}, daily: [{date, orders, amount_cents}], platform_distribution, status_distribution }`
- `GET /admin/report/withdrawals?start&end` — 提现报表：`{ stats: {count, amount_cents}, daily: [{date, count, amount_cents}], status_distribution }`
- `POST /admin/report/export` — 按报表类型导出 Excel（复用 phpspreadsheet；用户/支付/提现三选一，date 范围）

默认区间：近 30 天（与仪表盘一致）。金额字段均为分（amount_cents）。

### 3.2 仪表盘扩展

`DashboardController::index` 响应新增 `platform_stats`（5 分钟缓存内一并聚合）：

```json
[
  {"label":"社交用户总数","value":"…","icon":"people","color":"#1677FF"},
  {"label":"今日新增用户","value":"…","icon":"person_add","color":"#52C41A"},
  {"label":"支付订单数","value":"…","icon":"payments","color":"#FA8C16"},
  {"label":"今日充值(元)","value":"…","icon":"savings","color":"#722ED1"},
  {"label":"提现笔数","value":"…","icon":"account_balance","color":"#13C2C2"},
  {"label":"今日提现(元)","value":"…","icon":"money_off","color":"#EB2F96"}
]
```

Flutter 起始页在现有卡片区下方追加平台统计行。

### 3.3 权限

- 新增权限种子：`report:view`（报表查看）、`report:export`（报表导出），路由注册进 RBAC
- Flutter 侧菜单新增「报表」入口（admin_layout 抽屉），按权限显隐

## 4. 一键安装（install.sh）

根目录 `install.sh`，bash 脚本，流程：

1. 前置检查：PHP ≥ 8.3 / composer / MySQL / Redis（缺则提示 + 退出码提示）
2. `composer install`（service/、admin/ 各一次，`--no-dev` 可选）
3. 数据库：要求用户提供 `DB_HOST/DB_PORT/DB_ROOT_PASSWORD`，执行 `mysql < database/install.sql`（幂等，CREATE IF NOT EXISTS）
4. 生成 `.env`：service/、admin/ 各一份（DB、Redis、JWT 密钥随机、APP 密钥；不覆盖已存在文件）
5. 媒体层：`docker compose up -d`（media/sfu，可选 --skip-media）
6. 输出启动命令（php start.php start -d 等）与访问地址

要点：幂等（重复执行不破坏数据）、提示代替静默覆盖、失败即停（`set -euo pipefail`）。

## 5. 文档收尾

- 根 README.md：新增「一键安装」段（install.sh）+「安装说明」段（手工步骤）；里程碑行追加 M6d；功能总览文字补报表/统计
- docs/README.*.md 12 语言：同步新增段与里程碑行（与既有 13 语言里程碑行模式一致）
- service/README.md / service/README.en.md：重写 webman 模板为项目实际（介绍 + 安装 + 一键安装 + 使用 + 测试）
- admin/README.md / admin/README_EN.md：检查并补安装/一键安装/使用
- docs/diagrams/features.svg：功能总览图补「报表」与「起始页统计」节点
- 本文档 + 实现计划文档

## 6. 非目标

- 不做 service 端新接口（报表数据全部来自 admin → social 库直查）
- 不做移动端三端改动
- 不做图表库替换（沿用 fl_chart）
