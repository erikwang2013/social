# PHP Unit Test Report
**语言 / Languages:** [中文](php-unit-report.md) · [English](php-unit-report.en.md) · [한국어](php-unit-report.ko.md) · [Русский](php-unit-report.ru.md) · [Deutsch](php-unit-report.de.md) · [Français](php-unit-report.fr.md) · [Español](php-unit-report.es.md) · [Português](php-unit-report.pt.md) · [हिन्दी](php-unit-report.hi.md) · [العربية](php-unit-report.ar.md) · [বাংলা](php-unit-report.bn.md) · [Bahasa Indonesia](php-unit-report.id.md) · [日本語](php-unit-report.ja.md)

- Date: 2026-08-27
- Execution: `vendor/bin/phpunit` (PHPUnit 10.5.64, PHP 8.3.7)
- Scope: admin/ (webman admin panel) + service/ (webman main service)

## Overview

| Item | Test cases | Assertions | Result |
|------|------|------|------|
| service | 136 | 348 | ✅ All passed (OK) |
| admin | 60 | 136 | ⚠️ 49 passed / 4 errors / 7 failed |

## service (all green)

- New test files (this batch): AuthMiddlewareTest, UserBriefTest, SearchSyncTest, ActionHandlerTest, JwtHelperTest, VoiceControllerTest, MonitorTest, ModelRelationTest, etc., merged with the existing 24 test files for a total of 136 cases, all passing
- Modules covered: auth/middleware/JWT, users, posts, comments, follows, notifications, search sync, IM, rooms, calls (CallCenter/CallState), voice, model relations, action handling (WS)

### Fix: random test suite hang (important)

- Symptom: the process randomly freezes on full runs; single-file/subset runs pass
- Root cause: `new Worker()` in `ActionHandlerTest::setUp` registers the instance into the `Worker::$workers` **static registry**; afterwards any `CallCenter::start` sees "a Worker exists" and calls `Timer::add` → `pcntl_alarm(1)` installs a SIGALRM timer, and the process hangs on exit
- Fix: setUp snapshots the registry, tearDown restores it (`ReflectionProperty` writes back `workers`/`pidMap`)
- Location: `service/tests/ActionHandlerTest.php`

## admin (49/60, failures are all pre-existing tests and are environment/config issues)

| Case | Failure reason | Category |
|------|----------|------|
| EnvConfigTest (4 failed + 1 error) | `admin/.env` does not exist; getenv/dotenv assertions fail | Test environment missing .env |
| CaptchaTest (3 errors + 1 failed + 1 risky) | Captcha depends on a running service/Redis; unit test environment returns null | Environment dependency |
| BackendEnhancementTest (2 failed) | Asserts `app/middleware/Cors` exists and admin_user contains searchable — current config does not match assertions | Outdated config assertions |

Note: admin/tests are all pre-existing files from earlier; no new admin unit test files were added in this batch (focus was on service).

## Not covered / to be added

- admin modules (model/middleware/view) lack unit tests
- service paths that depend on external services (ES/gRPC) only received unit-level stub validation; integration-level coverage is recommended via API tests
