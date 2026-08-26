# PHP 单元测试报告
**语言 / Languages:** [中文](php-unit-report.md) · [English](php-unit-report.en.md) · [한국어](php-unit-report.ko.md) · [Русский](php-unit-report.ru.md) · [Deutsch](php-unit-report.de.md) · [Français](php-unit-report.fr.md) · [Español](php-unit-report.es.md) · [Português](php-unit-report.pt.md) · [हिन्दी](php-unit-report.hi.md) · [العربية](php-unit-report.ar.md) · [বাংলা](php-unit-report.bn.md) · [Bahasa Indonesia](php-unit-report.id.md) · [日本語](php-unit-report.ja.md)

- 日期: 2026-08-27
- 执行: `vendor/bin/phpunit`(PHPUnit 10.5.64, PHP 8.3.7)
- 范围: admin/(webman 后台) + service/(webman 主服务)

## 结论总览

| 项目 | 用例 | 断言 | 结果 |
|------|------|------|------|
| service | 136 | 348 | ✅ 全部通过(OK) |
| admin | 60 | 136 | ⚠️ 49 通过 / 4 错误 / 7 失败 |

## service(全绿)

- 新增测试文件(本批次): AuthMiddlewareTest、UserBriefTest、SearchSyncTest、ActionHandlerTest、JwtHelperTest、VoiceControllerTest、MonitorTest、ModelRelationTest 等,与既有 24 个测试文件合并后共 136 用例全部通过
- 覆盖模块: 认证/中间件/JWT、用户、帖子、评论、关注、通知、搜索同步、IM、房间、通话(CallCenter/CallState)、语音、模型关系、动作处理(WS)

### 修复:测试套件随机挂起(重要)

- 现象: 全量运行时进程随机卡死,单文件/子集跑则通过
- 根因: `ActionHandlerTest::setUp` 中 `new Worker()` 会把实例注册进 `Worker::$workers` **静态注册表**;之后任何 `CallCenter::start` 看到"有 Worker 存在",即调用 `Timer::add` → `pcntl_alarm(1)` 安装 SIGALRM 定时器,进程退出时挂起
- 修复: setUp 快照注册表、tearDown 恢复(`ReflectionProperty` 写回 `workers`/`pidMap`)
- 位置: `service/tests/ActionHandlerTest.php`

## admin(49/60,失败均为预置测试且属环境/配置问题)

| 用例 | 失败原因 | 归类 |
|------|----------|------|
| EnvConfigTest(4 失败+1 错误) | `admin/.env` 不存在,getenv/dotenv 断言失效 | 测试环境缺 .env |
| CaptchaTest(3 错误+1 失败+1 risky) | 验证码依赖运行中服务/Redis,单测环境返回 null | 环境依赖 |
| BackendEnhancementTest(2 失败) | 断言 `app/middleware/Cors` 存在、admin_user 含 searchable——当前配置与断言不符 | 配置断言过期 |

注: admin/tests 均为历史预置文件,本次未新增 admin 单测文件(精力集中在 service)。

## 未覆盖/待补

- admin 各模块(model/middleware/view)缺单测
- service 中依赖外部服务(ES/gRPC)的路径仅做了单元级 stub 验证,集成级建议由 API 测试覆盖
