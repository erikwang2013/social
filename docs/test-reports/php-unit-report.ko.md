# PHP 유닛 테스트 보고서
**语言 / Languages:** [中文](php-unit-report.md) · [English](php-unit-report.en.md) · [한국어](php-unit-report.ko.md) · [Русский](php-unit-report.ru.md) · [Deutsch](php-unit-report.de.md) · [Français](php-unit-report.fr.md) · [Español](php-unit-report.es.md) · [Português](php-unit-report.pt.md) · [हिन्दी](php-unit-report.hi.md) · [العربية](php-unit-report.ar.md) · [বাংলা](php-unit-report.bn.md) · [Bahasa Indonesia](php-unit-report.id.md) · [日本語](php-unit-report.ja.md)

- 날짜: 2026-08-27
- 실행: `vendor/bin/phpunit`(PHPUnit 10.5.64, PHP 8.3.7)
- 범위: admin/(webman 관리자 백엔드) + service/(webman 메인 서비스)

## 결과 총괄

| 항목 | 테스트 케이스 | 어서션 | 결과 |
|------|------|------|------|
| service | 136 | 348 | ✅ 전체 통과(OK) |
| admin | 60 | 136 | ⚠️ 49 통과 / 4 오류 / 7 실패 |

## service(전체 그린)

- 신규 테스트 파일(이번 배치): AuthMiddlewareTest, UserBriefTest, SearchSyncTest, ActionHandlerTest, JwtHelperTest, VoiceControllerTest, MonitorTest, ModelRelationTest 등, 기존 24개 테스트 파일과 합쳐 총 136개 케이스 전체 통과
- 커버 모듈: 인증/미들웨어/JWT, 사용자, 게시글, 댓글, 팔로우, 알림, 검색 동기화, IM, 방, 통화(CallCenter/CallState), 음성, 모델 관계, 액션 처리(WS)

### 수정: 테스트 스위트 랜덤 행(hang)(중요)

- 현상: 전체 실행 시 프로세스가 무작위로 멈춤, 단일 파일/부분 실행은 통과
- 근본 원인: `ActionHandlerTest::setUp`의 `new Worker()`가 인스턴스를 `Worker::$workers` **정적 레지스트리**에 등록; 이후 어떤 `CallCenter::start`든 "Worker가 존재"를 보고 `Timer::add` 호출 → `pcntl_alarm(1)`이 SIGALRM 타이머를 설치하고, 프로세스 종료 시 행
- 수정: setUp에서 레지스트리 스냅샷, tearDown에서 복원(`ReflectionProperty`로 `workers`/`pidMap` 되돌림)
- 위치: `service/tests/ActionHandlerTest.php`

## admin(49/60, 실패는 모두 기존 테스트이며 환경/설정 문제)

| 테스트 케이스 | 실패 원인 | 분류 |
|------|----------|------|
| EnvConfigTest(4 실패+1 오류) | `admin/.env`가 없어 getenv/dotenv 어서션 실패 | 테스트 환경에 .env 부재 |
| CaptchaTest(3 오류+1 실패+1 risky) | 캡차가 실행 중인 서비스/Redis에 의존, 단위 테스트 환경은 null 반환 | 환경 의존 |
| BackendEnhancementTest(2 실패) | `app/middleware/Cors` 존재와 admin_user에 searchable 포함을 어서션하나 현재 설정과 불일치 | 설정 어서션 노후화 |

참고: admin/tests는 모두 과거 기존 파일이며, 이번 배치에서는 admin 단위 테스트 파일을 추가하지 않음(집중은 service에).

## 미커버/추후 보완

- admin 각 모듈(model/middleware/view)에 단위 테스트 부재
- 외부 서비스(ES/gRPC)에 의존하는 service 경로는 유닛 레벨 stub 검증만 수행, 통합 레벨은 API 테스트로 커버 권장
