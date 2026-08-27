# PHP Unit Test Report
**语言 / Languages:** [中文](php-unit-report.md) · [English](php-unit-report.en.md) · [한국어](php-unit-report.ko.md) · [Русский](php-unit-report.ru.md) · [Deutsch](php-unit-report.de.md) · [Français](php-unit-report.fr.md) · [Español](php-unit-report.es.md) · [Português](php-unit-report.pt.md) · [हिन्दी](php-unit-report.hi.md) · [العربية](php-unit-report.ar.md) · [বাংলা](php-unit-report.bn.md) · [Bahasa Indonesia](php-unit-report.id.md) · [日本語](php-unit-report.ja.md)

- Date: 2026-08-27
- Execution: `vendor/bin/phpunit` (PHPUnit 10.5.64, PHP 8.3.7)
- Scope: admin/ (webman admin panel) + service/ (webman main service)

## Overview

| Item | Test cases | Assertions | Result |
|------|------|------|------|
| service | 136 | 348 | ✅ All passed (OK) |
| admin | 67 | 180 | ✅ All passed (OK) |

## Environment notes

- MySQL 127.0.0.1:3306 (root, empty password); databases `social` (social_*) and `open_admin` (erik_*) created and seeded (super_admin role, 39 permissions)
- Redis 127.0.0.1:6379 running (captcha storage `poster:captcha:*`); Elasticsearch not started (health check degrades to unavailable, not counted as a failure)
- service running on 8788, admin on 8791
- Neither service nor admin has a `.env` (the repo removed the mistakenly committed env, commit e5379fc); the apps run on the `getenv('X') ?: default` fallbacks in `config/*.php`
- **The Imagick extension is loaded but lacks the `RESOURCETYPE_PIXELS` constant** (this build only has the new RESOURCETYPE_* constant set); poster-php's ImagickDriver constructor references that constant and crashes

## service (136/136 all green)

- Consistent with the last batch's baseline; covers: auth/middleware/JWT, users, posts, comments, follows, notifications, search sync, IM, rooms, calls (CallCenter/CallState), voice, model relations, action handling (WS)
- No code changes, no failures in this batch

## admin (last batch 49/60 → this batch 67/67 all green)

### Fix: real code defect (1 place)

| Location | Root cause | Fix |
|------|------|------|
| `config/poster.php` | `image.driver` defaults to `auto`; DriverFactory picks ImagickDriver when the Imagick extension is detected, but this machine's Imagick lacks the `RESOURCETYPE_PIXELS` constant → captcha generation/poster directly 500s (the online service is equally affected) | Added a constant guard in driver detection: `getenv('POSTER_IMAGE_DRIVER') ?: (defined('Imagick::RESOURCETYPE_PIXELS') ? 'auto' : 'gd')`; automatically falls back to GD when the constant is missing |

### Fix: outdated assertions (updated after checking the current code)

| Test file | Case | Root cause | Correction |
|----------|------|------|------|
| EnvConfigTest | env_file_exists / env_example_file_exists / getenv_reads_env_variables / config_env_keys_exist_in_dotenv (4 failed + 1 error) | Asserts `.env`/`.env.example` exist and getenv has values; but the repo removed the env files and they cannot be rebuilt | Rewritten as a "run without .env" contract: every `getenv()` key must have a `?:` default fallback, default config points to local services (127.0.0.1:3306/open_admin), key config types are correct |
| BackendEnhancementTest | test_admin_user_source_contains_searchable | AdminUser no longer uses the Searchable trait (now uses `Erikwang2013\Encryptable\Encryptable` for transparent field encryption/decryption; `toSearchableArray()` kept) | Changed to assert the Encryptable trait; the toSearchableArray assertion already passed, kept |
| BackendEnhancementTest | test_middleware_config_contains_cors_and_rate_limit | `config/middleware.php` now uses the `'@'` global group key format; the top-level array no longer directly contains middleware classes | Assertion changed to check `$middlewares['@']` contains Cors and RateLimit |
| CaptchaTest | all 7 cases (originally 6 errors + 1 failed) | Doubly outdated: (a) missing Imagick constant (already fixed by poster.php); (b) assertions based on the old poster-php contract — `extra.targets` (with x/y) changed to `extra.texts` (text+order only), coordinates only live in the storage layer; click verification format changed from `['x'=>, 'y'=>]` to `[x, y]` number pairs | Rewritten per the current contract: structure/difficulty counts (2/3/4)/field validation, correct clicks read coordinates from Redis (`poster:captcha:{key}`'s `data.targets`) for verification, wrong clicks fail, key is consumed/deleted after exceeding max_attempts (3), key uniqueness |

### New tests (1 file, 12 cases)

`tests/AdminControllerTest.php` (with copyright header), covering:

- **BaseController::decodeId** (the just-fixed 404 behavior): encode/decode round-trips are consistent; invalid hashid throws `support\exception\NotFoundException` with code=404; encodeIds only rewrites ID fields
- **RoleController**: super_admin role update returns 403 (real DB data)
- **PermissionController::buildTree**: permission tree nesting (2 levels) + all node ids hashid-ized
- **ConfigController**: missing group/key/value returns 422 validation; invalid hashid throws 404
- **ExportController**: `admin_user` export sensitive-field list is phone/email/id_card (other tables empty); PDF HTML escapes title/cell values with htmlspecialchars (XSS protection) and includes the copyright statement

### Known notes

- The webman Request constructed in tests is passed as a raw HTTP message (buffer) — workerman's Request constructor parameter is a buffer, passing only method/uri cannot parse the POST body; see AdminControllerTest comments
- The captcha correct-click case reads stored targets from Redis; when Redis is unavailable the case is markTestSkipped and does not affect the suite result

## Not covered / to be added

- The Encryptable encryption/decryption of admin models, the OperationLog/AdminPermission middleware and the RBAC cache paths still lack unit tests; recommended to be covered by API tests or a later batch
- service paths that depend on external services (ES/gRPC) remain unit-level stub validation only; the integration level is covered by API tests
