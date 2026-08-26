# Admin 기준 수락 검토 (M0, 2026-08-17)

**语言 / Languages:** [中文](ADMIN_BASELINE.md) · [English](ADMIN_BASELINE.en.md) · [한국어](ADMIN_BASELINE.ko.md) · [Русский](ADMIN_BASELINE.ru.md) · [Deutsch](ADMIN_BASELINE.de.md) · [Français](ADMIN_BASELINE.fr.md) · [Español](ADMIN_BASELINE.es.md) · [Português](ADMIN_BASELINE.pt.md) · [हिन्दी](ADMIN_BASELINE.hi.md) · [العربية](ADMIN_BASELINE.ar.md) · [বাংলা](ADMIN_BASELINE.bn.md) · [Bahasa Indonesia](ADMIN_BASELINE.id.md) · [日本語](ADMIN_BASELINE.ja.md)

open-admin(webman v2 + Flutter 관리 백엔드)의 기준 상태와 개조 진입점.

## 현재 버전 및 실행 상태

| 항목 | 값 |
|---|---|
| 프레임워크 | webman v2 (workerman/webman-framework **v2.2.3**) |
| PHP | 8.3.7 (CLI) |
| 의존성 | `composer install` 성공, 69개 패키지 |
| .env | **없음** (저장소에 `.env`와 `.env.example`이 없으며, MySQL/Redis에 맞춰 로컬에서 직접 생성 필요) |
| 마이그레이션 진입점 | 없음 (`think`/`artisan` 없음; webman은 마이그레이션을 내장하지 않으며 M0에 마이그레이션 작업 없음) |
| 테스트 | `vendor/bin/phpunit`: 60 tests / 136 assertions, **4 errors / 7 failures / 6 warnings / 1 risky, 전체 통과 아님** |

## 활성화된 모듈 (README 확인)

- **JWT 인증**: 로그인/갱신/로그아웃, 클릭 캡차, 계정 잠금(5회 실패 시 15분 잠금), 동시 세션 제한(사용자당 ≤3 Token)
- **RBAC**: 역할/권한 트리, method.path 단위 인가
- **작업 감사**: 로그 조회 + 8개 플랫폼 소스 식별
- **파일 관리**: 업로드 / Excel 내보내기 / PDF 내보내기(마스킹)
- **i18n**: 중영 전환 (Accept-Language / ?lang=)
- 기타: 대시보드(Redis 캐시), 시스템 설정, 헬스 체크/metrics/OpenAPI 3.0, 18계층 보안 방어

## 테스트 실패 상세 (모두 기존 프로젝트 공백이며, 이번 변경으로 인한 것이 아님)

| 테스트 그룹 | 실패 | 원인 |
|---|---|---|
| `EnvConfigTest` (5건) | 4 failure + 1 error | 테스트가 `.env`/`.env.example`이 반드시 존재하고 `APP_NAME`/`JWT_SECRET_KEY`/`DB_HOST` 등 getenv 값이 설정되어야 한다고 단언; 저장소에 예시 env 미포함 |
| `CaptchaTest` (4건) | 3 error + 1 failure (그 외 1 risky는 단언 없음) | 클릭 캡차가 Redis 저장소에 의존하나 로컬에 미제공 |
| `BackendEnhancementTest` (2건) | 2 failure | `user` 데이터 소스에 searchable, middleware에 cors/rate_limit 포함을 단언 — 설정과 테스트 단언 간 드리프트 |

전체 통과 복구 로컬 절차: `config/`의 설정 키에 따라 `.env` 생성(EnvConfigTest가 의존하는 키 보완), MySQL + Redis 제공(CaptchaTest용), 그리고 담당자가 BackendEnhancementTest의 두 가지 설정 드리프트를 판정.

## gRPC 준비 상태 (T3)

- Composer 패키지 설치됨: `grpc/grpc 1.82.0`, `google/protobuf 5.35`(`--no-plugins`로 security-php 플러그인 중복 로딩 버그 우회)
- PHP 스텁 생성됨: `admin/generated/`(`Social/Admin/V1/AdminServiceClient.php` 등, infra/user 세 가지 계약 포함)
- **grpc PHP 확장 미설치**: pecl에 쓰기 권한이 없고 sudo에 비밀번호 필요; gRPC 클라이언트 실행 전에 `sudo pecl install grpc` 필요

## 개조 진입점 (설계 문서 §3.4의 8가지 신규 항목)

1. 콘텐츠 심사 워크벤치: 게시물/댓글/이미지 이중 언어 대조 심사, 거부 사유 다국어 템플릿, 사용자 제재
2. 신고 처리 큐
3. GDPR 요청 데스크(내보내기/삭제 티켓)
4. 데이터 대시보드와 bee_tsdb 연동
5. i18n 용어 관리(4개 클라이언트 공용 용어 CRUD)
6. 선물 라이브러리 관리(SKU, 가격, 이펙트, 다국어 이름)
7. 라이브 provider 설정(라우팅 전략, 전환 순서)
8. 출금 신청 심사

**gRPC 연동 지점**: admin 측 계약 스텁은 `admin/generated/`에 있음(`Social/Admin/V1` 프로브 + 향후 비즈니스 메시지 재사용), service 호출은 `Social\User\V1\UserServiceClient`, infrastructure 호출은 `Social\Infra\V1\InfraServiceClient`를 사용; service/infrastructure와의 프로브 체인은 `service/README.grpcs.md`와 T10 통합 프로브 참조.
