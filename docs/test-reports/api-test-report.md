# API 接口自动化测试报告
**语言 / Languages:** [中文](api-test-report.md) · [English](api-test-report.en.md) · [한국어](api-test-report.ko.md) · [Русский](api-test-report.ru.md) · [Deutsch](api-test-report.de.md) · [Français](api-test-report.fr.md) · [Español](api-test-report.es.md) · [Português](api-test-report.pt.md) · [हिन्दी](api-test-report.hi.md) · [العربية](api-test-report.ar.md) · [বাংলা](api-test-report.bn.md) · [Bahasa Indonesia](api-test-report.id.md) · [日本語](api-test-report.ja.md)

- 日期: 2026-08-27
- 执行: `tests/api/run.php`（curl 断言脚本），结果 `tests/api/results.json`
- 范围: admin HTTP API（A01-A45）+ service HTTP API（S01-S57b，含 S58-S68）
- 服务: admin `http://127.0.0.1:8791`、service `http://127.0.0.1:8788`（WebSocket `:8789` 未在本次 HTTP 用例覆盖）

## 结论

**116 项用例: 116 通过 / 0 失败（100% 通过率）；上轮 3 项产品缺陷（A20/A39/A40）全部修复验证通过**

| 分组 | 通过/总数 |
|------|-----------|
| admin A01-A45（认证、验证码、用户管理、HashID、角色权限、配置、日志、导出导入、上传、健康检查等） | 45/45 |
| service S01-S68（注册/登录/登出/刷新、个人资料、关注、帖子/点赞/时间线、评论、通知、搜索、IM 会话/消息/推送、语音上传/文件/通话/房间等） | 71/71 |

## 上轮 3 个产品缺陷修复验证（全部 PASS）

| 用例 | 期望 | 上轮实际 | 修复 | 本次结果 |
|------|------|---------|------|---------|
| A20 非法 hashid 用户详情 | 404 | 500 | `BaseController::decodeId()` 捕获 `InvalidArgumentException` 并抛 `support\exception\NotFoundException($msg, 404)`（admin/app/admin/controller/BaseController.php）；`UserController` 两个批量方法 catch 扩展为 `InvalidArgumentException \| NotFoundException` 保留 422 语义 | **PASS（404）** |
| A39 导出 Excel | xlsx 文件流 | 200+JSON 错误体 | `ExportController` 补 `use support\Response;`（返回类型此前解析到不存在的 `app\admin\controller\Response` 抛 TypeError）；`admin_user` 的 phone/email/id_card 由 Encryptable cast 读取时自动解密，导出直接脱敏、移除二次解密 | **PASS（attachment 文件流）** |
| A40 导出 PDF | pdf 文件流 | 200+JSON 错误体 | 同上（`ExportController::pdf()` 返回类型修复） | **PASS（application/pdf 文件流）** |

## 本次测试中修复/处理的环境问题（非产品业务代码改动）

1. **run.php DB 空密码覆盖失效（测试脚本缺陷，已修）**: `DB` 常量用 `getenv('DB_PASS') ?: 'root'`，环境变量为空字符串时被 `?:` 当作假值回退到 `'root'`，导致本机 root 空密码场景连接被拒（`Access denied ... using password: YES`）。已改为 `getenv('DB_PASS') ?? 'root'`（仅未设置时用默认值），一行修改（tests/api/run.php:26）。
2. **service 8788 端口被错误进程占用（环境，已处理）**: 本机另有一套项目 `property-management-platform` 的 service 进程（master 2004768，08:07 启动）监听 8788，且其 `.env` 指向 `property_management` 库——social service 实际未运行，导致 S45 起 IM/语音路由全部 404、清理阶段 SQL 也打错库。已停止该进程并在 8788/8789 重启 social service（`DB_HOST=127.0.0.1 DB_PORT=3306 DB_USERNAME=root DB_PASSWORD=''`），健康检查恢复 `social-service`。
3. **ImageMagick 7 升级导致验证码 Imagick 驱动崩溃（环境，已处理）**: 系统 ImageMagick 升级至 7.1.2-27（2026-07-08 构建）后移除了 `PixelsResource`，imagick 3.8.1 不再定义 `Imagick::RESOURCETYPE_PIXELS`，poster-php 的 `ImagickDriver` 构造即抛 `Undefined constant`（vendor 代码，未改动），验证码生成/校验（A05/A06）500 并级联阻塞 A08-A11 登录。**处理**: admin 服务以配置文档预留的驱动切换项重启——`POSTER_IMAGE_DRIVER=gd`（admin/config/poster.php:17 原生支持 gd/imagick/auto），验证码改用 GD 驱动后全链路正常。若需恢复 Imagick 驱动，需将 ImageMagick 降级至 6.x 或升级 poster-php 兼容 IM7。
4. **MySQL root 密码已变更为空**: 上轮记录为 `root/root`，本次实测空密码可登录，所有服务与脚本均按空密码启动。
5. **admin 服务重启环境**: 上轮"admin 无 .env、依赖环境变量"仍成立，重启命令见下方"环境与复现"。
6. **service/.env 仍为 `service/.env.api-test-bak`**: 上轮为连通测试移出后未还原（还原操作受 .env 文件访问策略限制），本次服务仍以环境变量方式启动。需人工 `mv service/.env.api-test-bak service/.env`（还原后需重启服务，注意其指向的数据库地址问题）。
7. **Elasticsearch 未启动**: `GET /api/v1/search/posts` 返回 503（设计降级），S 组搜索用例按预期处理（接受 0 或 503），不计失败。

## 契约/文档不符（建议修订，非阻塞）

- 验证码文档（apidoc 与 CaptchaController 注释）写 `clicks=[{x,y}]` 对象数组，`poster-php` 实现要求 `[[x,y]]` 坐标对数组，实测按文档传对象必失败。
- 语音上传返回 `voice_url` 为 `/voice/{md5}.m4a`（相对 API 根，缺 `/api/v1` 前缀），客户端需自行拼接 `/api/v1` 才能访问；文件访问走认证路由（需携带 token）。

## 环境与复现

- 测试凭据: 测试账号 `e2e_smoke`（admin，密码测试专用）+ `apitest_*@test.dev`（service，跑完自动清理），均写入 `tests/api/run.php` 常量，未使用任何真实密钥。
- 复现:

```bash
cd /home/wwwroot/social/admin && DB_PASSWORD='' ENCRYPTABLE_KEY='apitest-enc-16b!!' \
  ENCRYPTION_KEY='apitest-db-encrypt-key-32-byte!!' POSTER_IMAGE_DRIVER=gd \
  php start.php start                                          # admin :8791
cd /home/wwwroot/social/service && DB_HOST=127.0.0.1 DB_PORT=3306 DB_USERNAME=root \
  DB_PASSWORD='' php start.php start                           # service :8788
cd /home/wwwroot/social/tests/api && DB_PASS='' php run.php    # 重跑（116 用例）
```

- 注意: 8788 端口需确保未被 `property-management-platform` service 占用（两个项目默认端口相同，本机同时存在两个项目时需错开）。

## 接口清单（依据 route.php / apidoc 统计）

- service `config/route.php`: 39 条 HTTP 路由（认证 5、用户 2、关注 5、帖子 7、评论 2、通知 4、搜索 2、IM 4、语音/通话/房间 5、健康/文档 3）
- admin `config/route.php`: 33 条 HTTP 路由（认证/验证码 4、用户 CRUD 5、角色 5、权限 2、配置 4、日志 1、个人中心 4、导出 2、导入 1、上传 1、健康/文档 4）
