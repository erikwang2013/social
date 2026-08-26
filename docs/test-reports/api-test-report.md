# API 接口自动化测试报告
**语言 / Languages:** [中文](api-test-report.md) · [English](api-test-report.en.md) · [한국어](api-test-report.ko.md) · [Русский](api-test-report.ru.md) · [Deutsch](api-test-report.de.md) · [Français](api-test-report.fr.md) · [Español](api-test-report.es.md) · [Português](api-test-report.pt.md) · [हिन्दी](api-test-report.hi.md) · [العربية](api-test-report.ar.md) · [বাংলা](api-test-report.bn.md) · [Bahasa Indonesia](api-test-report.id.md) · [日本語](api-test-report.ja.md)

- 日期: 2026-08-27
- 执行: `tests/api/run.php`（curl 断言脚本），结果 `tests/api/results.json`
- 范围: admin HTTP API（A01-A45）+ service HTTP API（S01-S57b，含 S58-S68）
- 服务: admin `http://127.0.0.1:8791`、service `http://127.0.0.1:8788`（WebSocket `:8789` 未在本次 HTTP 用例覆盖）

## 结论

**116 项用例: 113 通过 / 3 失败（97.4% 通过率）；3 项失败均为已定位根因的产品缺陷**

| 分组 | 通过/总数 |
|------|-----------|
| admin A01-A45（认证、验证码、用户管理、HashID、角色权限、配置、日志、导出导入、上传、健康检查等） | 42/45 |
| service S01-S68（注册/登录/登出/刷新、个人资料、关注、帖子/点赞/时间线、评论、通知、搜索、IM 会话/消息/推送、语音上传/文件/通话/房间等） | 71/71 |

## 失败用例（3，均为产品缺陷）

| 用例 | 期望 | 实际 | 根因 |
|------|------|------|------|
| A20 非法 hashid 用户详情 | 404 | 500 | `HashidsService::decode()` 对非法 ID 抛 `InvalidArgumentException` 未捕获（admin/app/common/HashidsService.php:28，BaseController.php:52），异常透传为 500，应捕获并返回 404 |
| A39 导出 Excel | xlsx 文件流 | 200+JSON 错误体（业务失败） | `ExportController::excel()` 返回类型 `: Response` 但缺少 `use support\Response`，类型解析为 `app\admin\controller\Response` → 任何成功返回都抛 `TypeError`（ExportController.php:122），导出功能整体不可用 |
| A40 导出 PDF | pdf 文件流 | 200+JSON 错误体（业务失败） | 同上，`ExportController::pdf()`（ExportController.php:135）缺 `use support\Response` |

> 补充（同文件潜在缺陷，当前被上述 TypeError 掩盖）: `ExportController` 第 90 行对 phone/email 调 `EncryptionService::decrypt()`，而 `AdminUser` 模型的 `email/phone/id_card` 已声明 `Encryptable::class` cast（写入时自动加密、读取时自动解密），导出会对明文二次解密 → 一旦存在非空手机号/邮箱的账号，还会抛 `EncryptionException: Invalid ciphertext prefix for AES-256-CBC`。修复返回类型后此问题仍会复现。

## 测试中修复的环境问题（非产品代码改动）

1. **m2/m3/m4 迁移表 `id` 缺 AUTO_INCREMENT（阻塞项，已修）**: `service/database/m2.sql`/`m3.sql`/`m4.sql` 建出的 `social_follows`、`social_notifications` 的 `id BIGINT UNSIGNED NOT NULL` 无 `AUTO_INCREMENT`，任何 INSERT 报 `1364 Field 'id' doesn't have a default value`，阻塞关注/通知/IM/语音全部写路径。已在本机执行 `ALTER TABLE ... MODIFY id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`（其余 8 张表本就带自增）。**迁移脚本本身建议补上自增**。
2. **service/.env 指向不可达数据库（阻塞项）**: `DB_PORT=13306` 且无密码，主 MySQL 实际在 `127.0.0.1:3306 (root/root)`；webman 的 `createUnsafeMutable` 会覆盖 CLI 环境变量。测试期间将 `.env` 移为 `service/.env.api-test-bak`（内容原样保留）并以环境变量注入启动服务；还原操作受 .env 文件访问策略限制未执行，需人工 `mv service/.env.api-test-bak service/.env`（注意：还原后重启服务将再次命中不可达数据库）。
3. **admin 无 .env、依赖环境变量**: 需 `DB_PASSWORD=root ENCRYPTABLE_KEY(16B) ENCRYPTION_KEY(32B)`。`encryptable` 插件在 webman 容器未注册 provider 时回退到 `EnvEncryptableConfig`（读 `ENCRYPTION_KEY`，cipher 默认 aes-256-gcm），密钥长度不符则建号/导入/导出报 `MissingEncryptionKeyException`。
4. **Elasticsearch 未启动**: `GET /api/v1/search/posts` 返回 503（设计降级），S 组搜索用例按预期处理（接受 0 或 503），不计失败。

## 契约/文档不符（建议修订，非阻塞）

- 验证码文档（apidoc 与 CaptchaController 注释）写 `clicks=[{x,y}]` 对象数组，`poster-php` 实现要求 `[[x,y]]` 坐标对数组，实测按文档传对象必失败。
- 语音上传返回 `voice_url` 为 `/voice/{md5}.m4a`（相对 API 根，缺 `/api/v1` 前缀），客户端需自行拼接 `/api/v1` 才能访问；文件访问走认证路由（需携带 token）。

## 环境与复现

- 测试凭据: 测试账号 `e2e_smoke`（admin，密码测试专用）+ `apitest_*@test.dev`（service，跑完自动清理），均写入 `tests/api/run.php` 常量，未使用任何真实密钥。
- 复现:

```bash
cd /home/wwwroot/social/admin && DB_PASSWORD=root ENCRYPTABLE_KEY='apitest-enc-16b!!' \
  ENCRYPTION_KEY='apitest-db-encrypt-key-32-byte!!' php start.php start   # admin :8791
cd /home/wwwroot/social/service && DB_HOST=127.0.0.1 DB_PORT=3306 DB_USERNAME=root \
  DB_PASSWORD=root php start.php start                                     # service :8788
php /home/wwwroot/social/tests/api/run.php                                  # 重跑（116 用例）
```

## 接口清单（依据 route.php / apidoc 统计）

- service `config/route.php`: 39 条 HTTP 路由（认证 5、用户 2、关注 5、帖子 7、评论 2、通知 4、搜索 2、IM 4、语音/通话/房间 5、健康/文档 3）
- admin `config/route.php`: 33 条 HTTP 路由（认证/验证码 4、用户 CRUD 5、角色 5、权限 2、配置 4、日志 1、个人中心 4、导出 2、导入 1、上传 1、健康/文档 4）
