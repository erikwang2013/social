# API Automated Testing Report
**语言 / Languages:** [中文](api-test-report.md) · [English](api-test-report.en.md) · [한국어](api-test-report.ko.md) · [Русский](api-test-report.ru.md) · [Deutsch](api-test-report.de.md) · [Français](api-test-report.fr.md) · [Español](api-test-report.es.md) · [Português](api-test-report.pt.md) · [हिन्दी](api-test-report.hi.md) · [العربية](api-test-report.ar.md) · [বাংলা](api-test-report.bn.md) · [Bahasa Indonesia](api-test-report.id.md) · [日本語](api-test-report.ja.md)

- Date: 2026-08-27
- Execution: `tests/api/run.php` (curl assertion script), results in `tests/api/results.json`
- Scope: admin HTTP API (A01-A45) + service HTTP API (S01-S57b, including S58-S68)
- Services: admin `http://127.0.0.1:8791`, service `http://127.0.0.1:8788` (WebSocket `:8789` not covered by this HTTP test round)

## Conclusion

**116 test cases: 113 passed / 3 failed (97.4% pass rate); all 3 failures are product defects with identified root causes**

| Group | Passed/Total |
|------|-----------|
| admin A01-A45 (auth, captcha, user management, HashID, roles & permissions, config, logs, export/import, upload, health checks, etc.) | 42/45 |
| service S01-S68 (register/login/logout/refresh, profile, follow, posts/likes/timeline, comments, notifications, search, IM sessions/messages/push, voice upload/files/calls/rooms, etc.) | 71/71 |

## Failed test cases (3, all product defects)

| Case | Expected | Actual | Root cause |
|------|------|------|------|
| A20 Invalid hashid user details | 404 | 500 | `HashidsService::decode()` throws an uncaught `InvalidArgumentException` for invalid IDs (admin/app/common/HashidsService.php:28, BaseController.php:52); the exception propagates as 500, should be caught and return 404 |
| A39 Export Excel | xlsx file stream | 200+JSON error body (business failure) | `ExportController::excel()` declares return type `: Response` but lacks `use support\Response`, so the type resolves to `app\admin\controller\Response` → any successful return throws `TypeError` (ExportController.php:122), making export entirely unusable |
| A40 Export PDF | pdf file stream | 200+JSON error body (business failure) | Same as above, `ExportController::pdf()` (ExportController.php:135) lacks `use support\Response` |

> Additional note (potential defect in the same file, currently masked by the TypeError above): `ExportController` line 90 calls `EncryptionService::decrypt()` on phone/email, while the `AdminUser` model's `email/phone/id_card` fields declare the `Encryptable::class` cast (auto-encrypt on write, auto-decrypt on read), so export would decrypt plaintext a second time → once an account with non-empty phone/email exists, it throws `EncryptionException: Invalid ciphertext prefix for AES-256-CBC`. This issue will still reproduce after fixing the return types.

## Environment issues fixed during testing (not product code changes)

1. **m2/m3/m4 migration tables `id` missing AUTO_INCREMENT (blocking, fixed)**: `social_follows` and `social_notifications` created by `service/database/m2.sql`/`m3.sql`/`m4.sql` have `id BIGINT UNSIGNED NOT NULL` without `AUTO_INCREMENT`; any INSERT fails with `1364 Field 'id' doesn't have a default value`, blocking all write paths for follows/notifications/IM/voice. `ALTER TABLE ... MODIFY id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` was executed locally (the other 8 tables already have auto-increment). **The migration scripts themselves should be updated to include auto-increment.**
2. **service/.env points to an unreachable database (blocking)**: `DB_PORT=13306` with no password, while the main MySQL actually runs at `127.0.0.1:3306 (root/root)`; webman's `createUnsafeMutable` overrides CLI environment variables. During testing, `.env` was moved to `service/.env.api-test-bak` (content preserved as-is) and the service was started with environment variables injected; the restore could not be performed due to .env file access policy restrictions, requiring manual `mv service/.env.api-test-bak service/.env` (note: after restoring, restarting the service will hit the unreachable database again).
3. **admin has no .env, relies on environment variables**: requires `DB_PASSWORD=root ENCRYPTABLE_KEY(16B) ENCRYPTION_KEY(32B)`. The `encryptable` plugin falls back to `EnvEncryptableConfig` (reads `ENCRYPTION_KEY`, default cipher aes-256-gcm) when the provider is not registered in the webman container; mismatched key lengths cause `MissingEncryptionKeyException` on account creation/import/export.
4. **Elasticsearch not started**: `GET /api/v1/search/posts` returns 503 (designed degradation); S-group search cases handled as expected (accepting 0 or 503), not counted as failures.

## Contract/documentation mismatches (suggested revision, non-blocking)

- Captcha documentation (apidoc and CaptchaController comments) writes `clicks=[{x,y}]` as an array of objects, while the `poster-php` implementation requires `[[x,y]]` coordinate-pair arrays; passing objects per the docs always fails in practice.
- Voice upload returns `voice_url` as `/voice/{md5}.m4a` (relative to API root, missing the `/api/v1` prefix); clients must prepend `/api/v1` themselves to access it; file access goes through authenticated routes (token required).

## Environment and reproduction

- Test credentials: test account `e2e_smoke` (admin, testing-only password) + `apitest_*@test.dev` (service, auto-cleaned after the run), all written into `tests/api/run.php` constants; no real keys were used.
- Reproduction:

```bash
cd /home/wwwroot/social/admin && DB_PASSWORD=root ENCRYPTABLE_KEY='apitest-enc-16b!!' \
  ENCRYPTION_KEY='apitest-db-encrypt-key-32-byte!!' php start.php start   # admin :8791
cd /home/wwwroot/social/service && DB_HOST=127.0.0.1 DB_PORT=3306 DB_USERNAME=root \
  DB_PASSWORD=root php start.php start                                     # service :8788
php /home/wwwroot/social/tests/api/run.php                                  # re-run (116 cases)
```

## Endpoint inventory (based on route.php / apidoc)

- service `config/route.php`: 39 HTTP routes (auth 5, user 2, follow 5, posts 7, comments 2, notifications 4, search 2, IM 4, voice/calls/rooms 5, health/docs 3)
- admin `config/route.php`: 33 HTTP routes (auth/captcha 4, user CRUD 5, roles 5, permissions 2, config 4, logs 1, profile 4, export 2, import 1, upload 1, health/docs 4)
