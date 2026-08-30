# M6d 实现计划 — 报表 + 起始页统计 + 一键安装 + 文档收尾

**日期**: 2026-08-31
**设计**: [2026-08-31-m6d-reports-dashboard-design.md](../specs/2026-08-31-m6d-reports-dashboard-design.md)

## 任务清单

| # | 任务 | 说明 | 文件 |
|---|------|------|------|
| 1 | 报表后端 | `ReportController`：users/payments/withdrawals 聚合 + export Excel | `admin/app/admin/controller/ReportController.php` |
| 2 | 起始页统计后端 | `DashboardController` 增加 `platform_stats`（社交用户/支付/提现 6 卡片） | `admin/app/admin/controller/DashboardController.php` |
| 3 | 权限种子 | `report:view` / `report:export` 权限 + 报表路由注册 | 权限种子 SQL/路由配置 |
| 4 | Flutter 报表页 | 报表页（3 tab + 日期范围 + 汇总 + 图表 + 表格 + 导出）+ 路由 + 菜单 | `admin/apps/flutter/lib/app/pages/report/*`、`app_pages.dart`、`admin_layout.dart` |
| 5 | Flutter 起始页统计 | dashboard 页追加平台统计卡片行 | `dashboard_page.dart`、`dashboard_controller.dart` |
| 6 | 单测 | ReportControllerTest（聚合正确性）+ Dashboard platform_stats 断言 | `admin/tests/*` |
| 7 | 一键安装 | 根 `install.sh`（幂等：依赖检查→composer→install.sql→.env→SFU compose→启动指引） | `install.sh` |
| 8 | 文档收尾 | 13 语言 README 一键安装/安装/使用 + M6d 里程碑行 + features.svg 补节点 + service/admin README 重写 | 见设计 §5 |

## 协作

- coder: Task 1–7
- docs-updater: Task 8（功能范围已冻结在本计划，与 coder 并行）
- tester: 验证 coder 产出（phpunit / flutter analyze / bash -n install.sh）
- reviewer: 最终代码审查
- lead: 合流、提交、推送（push main 自动触发 v1.0.23 发布）

## 验收

- `cd admin && vendor/bin/phpunit` 全绿（含新增 ReportControllerTest）
- `cd admin/apps/flutter && flutter analyze` 无新增错误
- `bash -n install.sh` 通过
- 13 语言 README 均含一键安装 + 安装说明 + 使用说明段，里程碑行含 M6d
- features.svg 含报表/统计节点
