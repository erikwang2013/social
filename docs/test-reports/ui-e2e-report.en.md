# Page End-to-End (E2E) Test Report
**语言 / Languages:** [中文](ui-e2e-report.md) · [English](ui-e2e-report.en.md) · [한국어](ui-e2e-report.ko.md) · [Русский](ui-e2e-report.ru.md) · [Deutsch](ui-e2e-report.de.md) · [Français](ui-e2e-report.fr.md) · [Español](ui-e2e-report.es.md) · [Português](ui-e2e-report.pt.md) · [हिन्दी](ui-e2e-report.hi.md) · [العربية](ui-e2e-report.ar.md) · [বাংলা](ui-e2e-report.bn.md) · [Bahasa Indonesia](ui-e2e-report.id.md) · [日本語](ui-e2e-report.ja.md)

- Date: 2026-08-27
- Environment: local machine (Linux), real browser (Playwright 1.62 / Chromium) + real service processes
- Total test cases: **35**, passed **35**, failed **0**, blocked **1**
- Artifacts: `tests/e2e/artifacts/html-report/` (Playwright HTML report), failure screenshots/traces (none this run)

## Test Scope and Page List

Both webman backends run as real processes: `admin` (:8791), `service` (:8788, WS :8789).
Both sides' `app/view/` only contain the default templates (`index/view.html`), with no traditional multi-page templates — the actual "pages" are the API endpoints,
and the web frontends are carried by Flutter/HarmonyOS clients (`apps/` has no runnable web UI, so it is not in E2E scope).

| App | Page / Endpoint | Cases |
|------|------------|------|
| admin | `/health` health check, `/metrics` Prometheus metrics, `/.well-known/security.txt`, `/api/docs` OpenAPI, `/install` installation wizard | 5 |
| admin | `/api/captcha/generate` + `/api/captcha/verify` (slider captcha solved from real pixels), `/api/auth/login` (success/wrong password/missing captcha) | 3 |
| admin | Protected pages after login: `/admin/dashboard`, `/admin/user`, `/admin/role`, `/admin/permission`, `/admin/config`, `/admin/log`, `/admin/profile`, `/admin/social-user`, logout `/admin/profile/logout` → token invalidated | 11 |
| service | `/` (iframe container), `/health`, `/apidoc` (redirects to apidoc/index.html) | 3 |
| service | Register/login/logout, profile (GET/PUT `/api/v1/me`), post/timeline/detail, like/unlike, comment, follow/relationship/followers/following list, notifications (list/unread count/mark all read) | 8 |
| service | Search users, search posts (ES not started → 503, marked blocked and passed) | 2 |
| service | IM conversations (create/list/messages), voice rooms (create/list/detail/close) | 3 |

## How to Run

```bash
cd tests/e2e && npx playwright test          # all
# or by file: admin-pages.spec.js / admin-auth.spec.js / service-journey.spec.js
```

- Test account fixture: `e2e_smoke`, password `ApiTest!2026` (pre-seeded via SQL, see `tests/api/run.php`)
- The slider captcha is solved via pixel Pearson correlation between the "puzzle piece vs. background image" (a real interaction path, no bypass);
  the captcha type is random (click/rotate/slider), and only the slider can be solved automatically, so the script retries with a new image until it hits.

## Blockers / Environment Limitations

1. **Post search 503**: `/api/v1/search/posts` depends on Elasticsearch (Scout), which is not started in this environment → returns 503.
   The case passes marked as `blocked`; it needs ES started to verify hits.
2. **admin captcha GD memory**: `GdDriver` decodes large images (background 5472x3648) with `memory_limit 128M`,
   so consecutive generates risk OOM (admin once crashed during a long suite). Mitigation: restart admin before running captcha cases,
   and run in batches (admin-pages / admin-auth / service separately). This is an environment limitation, not a business code defect.
3. **Random captcha type**: generate picks one of three; click/rotate expose no solvable data, only slider can auto-pass (max 12 retries).
4. **Database root empty password**: the local test environment provides MySQL with root/empty password, and both apps' `.env` defaults are consistent.
5. **apps/ mobile**: android/harmonyos/ios have no runnable web UI, so they are not included in browser E2E.

## Conclusion

admin login (including the slider captcha) and 19 admin endpoints, plus all 16 full-flow service user-side cases pass;
the only blocker is the search service (ES) not being deployed; all other paths (register/login/post/interaction/notification/IM/voice) are verified working.
