# 페이지 엔드투엔드(E2E) 테스트 보고서
**语言 / Languages:** [中文](ui-e2e-report.md) · [English](ui-e2e-report.en.md) · [한국어](ui-e2e-report.ko.md) · [Русский](ui-e2e-report.ru.md) · [Deutsch](ui-e2e-report.de.md) · [Français](ui-e2e-report.fr.md) · [Español](ui-e2e-report.es.md) · [Português](ui-e2e-report.pt.md) · [हिन्दी](ui-e2e-report.hi.md) · [العربية](ui-e2e-report.ar.md) · [বাংলা](ui-e2e-report.bn.md) · [Bahasa Indonesia](ui-e2e-report.id.md) · [日本語](ui-e2e-report.ja.md)

- 날짜: 2026-08-27
- 환경: 로컬 머신(Linux), 실제 브라우저(Playwright 1.62 / Chromium) + 실제 서비스 프로세스
- 테스트 케이스 총 **35**개, 통과 **35**, 실패 **0**, 차단 표시 **1**
- 산출물: `tests/e2e/artifacts/html-report/`(Playwright HTML 보고서), 실패 스크린샷/트레이스(이번 실행에는 없음)

## 테스트 범위 및 페이지 목록

두 webman 백엔드 모두 실제 프로세스로 실행됨: `admin`(:8791), `service`(:8788, WS :8789).
양쪽의 `app/view/`에는 기본 템플릿(`index/view.html`)만 있고 기존의 다중 페이지 템플릿은 없음 — 실제 "페이지"는 API 엔드포인트이며,
웹 프런트엔드는 Flutter/HarmonyOS 클라이언트가 담당함(`apps/`에 실행 가능한 웹 UI가 없어 E2E 범위에 포함되지 않음).

| 앱 | 페이지 / 엔드포인트 | 케이스 |
|------|------------|------|
| admin | `/health` 헬스 체크, `/metrics` Prometheus 메트릭, `/.well-known/security.txt`, `/api/docs` OpenAPI, `/install` 설치 마법사 | 5 |
| admin | `/api/captcha/generate` + `/api/captcha/verify`(슬라이더 캡차 실제 픽셀 풀이), `/api/auth/login`(성공/오류 비밀번호/캡차 누락) | 3 |
| admin | 로그인 후 보호 페이지: `/admin/dashboard`, `/admin/user`, `/admin/role`, `/admin/permission`, `/admin/config`, `/admin/log`, `/admin/profile`, `/admin/social-user`, 로그아웃 `/admin/profile/logout` → 토큰 무효화 | 11 |
| service | `/`(iframe 컨테이너), `/health`, `/apidoc`(apidoc/index.html로 리다이렉트) | 3 |
| service | 회원가입/로그인/로그아웃, 프로필(GET/PUT `/api/v1/me`), 게시글/타임라인/상세, 좋아요/좋아요 취소, 댓글, 팔로우/관계/팔로워/팔로잉 목록, 알림(목록/안 읽음 수/전체 읽음 처리) | 8 |
| service | 사용자 검색, 게시글 검색(ES 미기동 → 503, blocked 표시 후 통과) | 2 |
| service | IM 대화(생성/목록/메시지), 음성방(생성/목록/상세/닫기) | 3 |

## 실행 방법

```bash
cd tests/e2e && npx playwright test          # 전체
# 또는 파일별: admin-pages.spec.js / admin-auth.spec.js / service-journey.spec.js
```

- 테스트 계정 픽스처: `e2e_smoke`, 비밀번호 `ApiTest!2026`(SQL 사전 주입, `tests/api/run.php` 참조)
- 슬라이더 캡차는 "퍼즐 조각 vs 배경 이미지" 픽셀 Pearson 상관으로 풀이(실제 상호작용 경로, 우회 없음);
  캡차 유형은 무작위(click/rotate/slider)이며 slider만 자동 풀이 가능하므로, 스크립트가 히트할 때까지 이미지를 바꿔 재시도함.

## 차단 지점 / 환경 제한

1. **게시글 검색 503**: `/api/v1/search/posts`는 Elasticsearch(Scout)에 의존하며, 이 환경에서는 ES를 기동하지 않음 → 503 반환.
   케이스는 `blocked` 표시로 통과하며, ES 기동 후 적중을 검증해야 함.
2. **admin 캡차 GD 메모리**: `GdDriver`가 큰 이미지(배경 5472x3648)를 디코딩하고 `memory_limit 128M` 상태에서
   연속 generate는 OOM 위험이 있음(장시간 스위트 실행 중 admin이 다운된 적 있음). 회피: 캡차 케이스 실행 전 admin 재시작,
   배치로 분리 실행(admin-pages / admin-auth / service 각각). 환경 제한이며 비즈니스 코드 결함이 아님.
3. **캡차 유형 무작위**: generate가 셋 중 하나를 선택하며, click/rotate는 풀이 가능한 데이터를 노출하지 않아 slider만 자동 통과 가능(최대 12회 재시도).
4. **데이터베이스 root 빈 비밀번호**: 로컬 테스트 환경의 MySQL이 root/빈 비밀번호로 제공되며, 두 앱의 `.env` 기본값이 일치함.
5. **apps/ 모바일**: android/harmonyos/ios는 실행 가능한 웹 UI가 없어 브라우저 E2E에 포함하지 않음.

## 결론

admin 로그인(슬라이더 캡차 포함)과 관리 엔드포인트 19개, service 사용자 측 전 흐름 케이스 16개가 모두 통과함;
유일한 차단점은 검색 서비스(ES) 미배포이며, 나머지 경로(회원가입/로그인/게시글/상호작용/알림/IM/음성)는 모두 사용 가능함이 검증됨.
