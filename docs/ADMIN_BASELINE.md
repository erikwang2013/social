# Admin 基线验收（M0，2026-08-17）

**语言 / Languages:** [中文](ADMIN_BASELINE.md) · [English](ADMIN_BASELINE.en.md) · [한국어](ADMIN_BASELINE.ko.md) · [Русский](ADMIN_BASELINE.ru.md) · [Deutsch](ADMIN_BASELINE.de.md) · [Français](ADMIN_BASELINE.fr.md) · [Español](ADMIN_BASELINE.es.md) · [Português](ADMIN_BASELINE.pt.md) · [हिन्दी](ADMIN_BASELINE.hi.md) · [العربية](ADMIN_BASELINE.ar.md) · [বাংলা](ADMIN_BASELINE.bn.md) · [Bahasa Indonesia](ADMIN_BASELINE.id.md) · [日本語](ADMIN_BASELINE.ja.md)

open-admin（webman v2 + Flutter 管理后台）基线状态与改造入口。

## 当前版本与运行状态

| 项 | 值 |
|---|---|
| 框架 | webman v2（workerman/webman-framework **v2.2.3**） |
| PHP | 8.3.7（CLI） |
| 依赖 | `composer install` 成功，69 个包 |
| .env | **不存在**（仓库无 `.env` 与 `.env.example`，需本地按 MySQL/Redis 自行创建） |
| 迁移入口 | 无 `think`/`artisan`（webman 不内置迁移，M0 无迁移任务） |
| 测试 | `vendor/bin/phpunit`：60 tests / 136 assertions，**4 errors / 7 failures / 6 warnings / 1 risky，非全绿** |

## 已启用模块（README 确认）

- **JWT 认证**：登录/刷新/登出、点击验证码、账号锁定（5 次失败锁 15 分钟）、并发会话限制（每用户 ≤3 Token）
- **RBAC**：角色/权限树，method.path 粒度鉴权
- **操作审计**：日志查询 + 8 平台来源端识别
- **文件管理**：上传 / Excel 导出 / PDF 导出（脱敏）
- **i18n**：中英文切换（Accept-Language / ?lang=）
- 其他：仪表盘（Redis 缓存）、系统配置、健康检查/metrics/OpenAPI 3.0、18 层安全防护

## 测试失败明细（均为既有工程缺口，非本次改动引入）

| 用例组 | 失败 | 原因 |
|---|---|---|
| `EnvConfigTest`（5 项） | 4 failure + 1 error | 测试断言 `.env`/`.env.example` 必须存在且 `APP_NAME`/`JWT_SECRET_KEY`/`DB_HOST` 等 getenv 有值；仓库未随附示例 env |
| `CaptchaTest`（4 项） | 3 error + 1 failure（另 1 risky 无断言） | 点击验证码依赖 Redis 存储，本地未提供 |
| `BackendEnhancementTest`（2 项） | 2 failure | 断言 `user` 数据源含 searchable、middleware 含 cors/rate_limit——配置与测试断言漂移 |

恢复全绿的本地步骤：按 `config/` 内配置键创建 `.env`（补齐 EnvConfigTest 依赖的键），提供 MySQL + Redis（CaptchaTest），再由负责人裁决 BackendEnhancementTest 的两项配置漂移。

## gRPC 就绪状态（T3）

- Composer 包已装：`grpc/grpc 1.82.0`、`google/protobuf 5.35`（`--no-plugins` 绕过 security-php 插件重复加载 bug）
- PHP 桩已生成：`admin/generated/`（`Social/Admin/V1/AdminServiceClient.php` 等，含 infra/user 三套契约）
- **grpc PHP 扩展未装**：pecl 无写权限且 sudo 需密码；需 `sudo pecl install grpc` 后方可跑 gRPC 客户端

## 改造入口（设计文档 §3.4 八项新增）

1. 内容审核工作台：动态/评论/图片双语对照审核、驳回原因多语言模板、用户处罚
2. 举报处理队列
3. GDPR 请求台（导出/删除工单）
4. 数据看板对接 bee_tsdb
5. i18n 词条管理（四端共用词条 CRUD）
6. 礼物库管理（SKU、价格、特效、多语言名称）
7. 直播 provider 配置（选路策略、切换顺序）
8. 提现申请审核

**gRPC 接入点**：admin 侧契约桩在 `admin/generated/`（复用 `Social/Admin/V1` 探活 + 后续业务消息），
对 service 的调用走 `Social\User\V1\UserServiceClient`、对 infrastructure 走 `Social\Infra\V1\InfraServiceClient`；
与 service/infrastructure 的探活链路见 `service/README.grpcs.md` 与 T10 集成探活。
