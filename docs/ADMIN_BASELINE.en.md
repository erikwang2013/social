# Admin Baseline Acceptance (M0, 2026-08-17)

**语言 / Languages:** [中文](ADMIN_BASELINE.md) · [English](ADMIN_BASELINE.en.md) · [한국어](ADMIN_BASELINE.ko.md) · [Русский](ADMIN_BASELINE.ru.md) · [Deutsch](ADMIN_BASELINE.de.md) · [Français](ADMIN_BASELINE.fr.md) · [Español](ADMIN_BASELINE.es.md) · [Português](ADMIN_BASELINE.pt.md) · [हिन्दी](ADMIN_BASELINE.hi.md) · [العربية](ADMIN_BASELINE.ar.md) · [বাংলা](ADMIN_BASELINE.bn.md) · [Bahasa Indonesia](ADMIN_BASELINE.id.md) · [日本語](ADMIN_BASELINE.ja.md)

Baseline status and transformation entry points for open-admin (webman v2 + Flutter admin console).

## Current version and runtime status

| Item | Value |
|---|---|
| Framework | webman v2 (workerman/webman-framework **v2.2.3**) |
| PHP | 8.3.7 (CLI) |
| Dependencies | `composer install` succeeded, 69 packages |
| .env | **Does not exist** (the repository has no `.env` or `.env.example`; create one locally per MySQL/Redis) |
| Migration entry | None (`think`/`artisan` not available; webman has no built-in migration, M0 has no migration tasks) |
| Tests | `vendor/bin/phpunit`: 60 tests / 136 assertions, **4 errors / 7 failures / 6 warnings / 1 risky — not all green** |

## Enabled modules (confirmed in README)

- **JWT authentication**: login/refresh/logout, click captcha, account lockout (locked for 15 minutes after 5 failed attempts), concurrent session limit (≤3 tokens per user)
- **RBAC**: role/permission tree, method.path-granularity authorization
- **Operation audit**: log query + identification of 8 platform client sources
- **File management**: upload / Excel export / PDF export (masked)
- **i18n**: Chinese/English switching (Accept-Language / ?lang=)
- Others: dashboard (Redis cache), system configuration, health check/metrics/OpenAPI 3.0, 18-layer security protection

## Test failure details (all pre-existing project gaps, not introduced by this change)

| Test group | Failure | Reason |
|---|---|---|
| `EnvConfigTest` (5 cases) | 4 failures + 1 error | Tests assert that `.env`/`.env.example` must exist and getenv values for `APP_NAME`/`JWT_SECRET_KEY`/`DB_HOST` etc. are set; the repository does not ship an example env |
| `CaptchaTest` (4 cases) | 3 errors + 1 failure (plus 1 risky without assertions) | Click captcha depends on Redis storage, not provided locally |
| `BackendEnhancementTest` (2 cases) | 2 failures | Asserts `user` datasource contains searchable and middleware contains cors/rate_limit — drift between config and test assertions |

Local steps to restore all-green: create `.env` according to the config keys in `config/` (add the keys EnvConfigTest depends on), provide MySQL + Redis (for CaptchaTest), and have the owner adjudicate the two config drift items in BackendEnhancementTest.

## gRPC readiness (T3)

- Composer packages installed: `grpc/grpc 1.82.0`, `google/protobuf 5.35` (`--no-plugins` works around the security-php plugin duplicate-loading bug)
- PHP stubs generated: `admin/generated/` (`Social/Admin/V1/AdminServiceClient.php` etc., including the three contract sets: infra/user)
- **grpc PHP extension not installed**: pecl has no write permission and sudo requires a password; `sudo pecl install grpc` is required before running the gRPC client

## Transformation entry points (design doc §3.4, eight new items)

1. Content moderation workbench: bilingual side-by-side review of posts/comments/images, multilingual rejection-reason templates, user penalties
2. Report handling queue
3. GDPR request desk (export/delete tickets)
4. Data dashboard integration with bee_tsdb
5. i18n entry management (shared CRUD across four clients)
6. Gift library management (SKU, price, effects, multilingual names)
7. Live-streaming provider configuration (routing strategy, switch order)
8. Withdrawal request review

**gRPC integration points**: admin-side contract stubs are in `admin/generated/` (reusing `Social/Admin/V1` for liveness probes + future business messages); calls to service go through `Social\User\V1\UserServiceClient` and to infrastructure through `Social\Infra\V1\InfraServiceClient`; the liveness-probe chain with service/infrastructure is described in `service/README.grpcs.md` and T10 integration probes.
