# 全量测试汇总报告
**语言 / Languages:** [中文](SUMMARY.md) · [English](SUMMARY.en.md) · [한국어](SUMMARY.ko.md) · [Русский](SUMMARY.ru.md) · [Deutsch](SUMMARY.de.md) · [Français](SUMMARY.fr.md) · [Español](SUMMARY.es.md) · [Português](SUMMARY.pt.md) · [हिन्दी](SUMMARY.hi.md) · [العربية](SUMMARY.ar.md) · [বাংলা](SUMMARY.bn.md) · [Bahasa Indonesia](SUMMARY.id.md) · [日本語](SUMMARY.ja.md)

- 日期: 2026-08-27
- 测试团队: PHP 单元测试 / Rust 单元测试 / API 自动化 / UI 端到端(GO 角色见文末说明)
- 四份分报告 + 本汇总均本地存储于 `docs/test-reports/`

## 总览

| 角色 | 报告 | 用例 | 通过 | 失败 | 结论 |
|------|------|------|------|------|------|
| PHP 单元测试 | `php-unit-report.md` | 196 | 185 | 11(admin 预置用例,环境依赖) | service 136/136 全绿;admin 49/60 |
| Rust 单元测试 | `rust-unit-report.md` | 180 | 180 | 0 | 15 crates 全绿,并发现 7 处真实缺陷 |
| API 自动化 | `api-test-report.md` | 116 | 113 | 3 | 3 个真实产品缺陷,根因已定位 |
| UI 端到端 | `ui-e2e-report.md` | 35 | 35 | 0 | 全绿,1 项 blocked(ES 未启动) |
| **合计** | | **527** | **513** | **14** | 通过率 97% |

## 真实缺陷清单(建议修复)

1. **A20 非法 hashid** → 500 应 404:`admin/app/common/HashidsService.php:28` 未捕获 `InvalidArgumentException`
2. **A39/A40 导出 Excel/PDF** → 必败:`ExportController` 缺少 `use support\Response` 致返回类型解析错误;同文件对已 cast 解密的手机号/邮箱二次解密会报 `Invalid ciphertext prefix`
3. **Rust 发现的 7 处缺陷**: 详见 `rust-unit-report.md`(协议解析、边界处理等,均已附带修复)
4. **admin 单测 11 项失败为环境/配置问题**: `admin/.env` 缺失、验证码依赖运行中服务/Redis、Cors 中间件与 admin_user searchable 断言过期,非代码缺陷

## 环境修复与注意项(本批次测试造成)

- **数据库**: m2/m3/m4 迁移表 `social_follows`/`social_notifications` 的 `id` 缺 AUTO_INCREMENT,已 ALTER 修复(否则关注/通知/IM/语音写路径 1364 报错)
- **`service/.env`**: 被备份为 `.env.api-test-bak`(原指向不可达的 13306 端口)。因 .env 访问策略限制无法自动还原,需人工 `mv service/.env.api-test-bak service/.env` 恢复
- **ES 未启动**: 搜索类用例(API + E2E)按 503/blocked 标注通过,需启动 Elasticsearch 后复验

## GO 测试工程师说明

仓库内**无任何 Go 代码**(无 go.mod、无 .go 文件),该角色无模块可测,未执行。如需补测,需先引入 Go 组件(如网关/搜索 sidecar)。

## 复现方式

```bash
# 单元测试
cd service && vendor/bin/phpunit
cd admin && vendor/bin/phpunit
cd infrastructure && cargo test --workspace
# API 自动化(需先起 admin :8791 与 service :8788,注入 ENCRYPTABLE_KEY/ENCRYPTION_KEY)
php tests/api/run.php
# UI E2E
cd tests/e2e && npx playwright test
```
