# PHP 유닛 테스트 보고서
**语言 / Languages:** [中文](php-unit-report.md) · [English](php-unit-report.en.md) · [한국어](php-unit-report.ko.md) · [Русский](php-unit-report.ru.md) · [Deutsch](php-unit-report.de.md) · [Français](php-unit-report.fr.md) · [Español](php-unit-report.es.md) · [Português](php-unit-report.pt.md) · [हिन्दी](php-unit-report.hi.md) · [العربية](php-unit-report.ar.md) · [বাংলা](php-unit-report.bn.md) · [Bahasa Indonesia](php-unit-report.id.md) · [日本語](php-unit-report.ja.md)

- 날짜: 2026-08-27
- 실행: `vendor/bin/phpunit`(PHPUnit 10.5.64, PHP 8.3.7)
- 범위: admin/(webman 관리자 백엔드) + service/(webman 메인 서비스)

## 결과 총괄

| 항목 | 테스트 케이스 | 어서션 | 결과 |
|------|------|------|------|
| service | 136 | 348 | ✅ 전체 통과(OK) |
| admin | 67 | 180 | ✅ 전체 통과(OK) |

## 환경 설명

- MySQL 127.0.0.1:3306(root, 빈 비밀번호), DB `social`(social_*)과 `open_admin`(erik_*) 생성·데이터 투입 완료(super_admin 역할, 권한 39개)
- Redis 127.0.0.1:6379 실행 중(캡차 저장 `poster:captcha:*`); Elasticsearch 미기동(헬스 체크는 unavailable로 강등, 실패로 간주하지 않음)
- service는 8788, admin은 8791에서 실행 중
- service와 admin 모두 `.env` 없음(리포지토리가 잘못 올라간 env를 제거함, commit e5379fc), 앱은 `config/*.php`의 `getenv('X') ?: 기본값` 폴백으로 실행
- **Imagick 확장은 로드됐지만 `RESOURCETYPE_PIXELS` 상수 부재**(본 머신 빌드에는 새 RESOURCETYPE_* 상수 집합만 있음), poster-php의 ImagickDriver는 생성자에서 이 상수를 참조해 즉시 크래시

## service(136/136 전체 그린)

- 이전 배치 베이스라인과 일치, 커버: 인증/미들웨어/JWT, 사용자, 게시글, 댓글, 팔로우, 알림, 검색 동기화, IM, 방, 통화(CallCenter/CallState), 음성, 모델 관계, 액션 처리(WS)
- 이번 배치에서는 코드 변경 없음, 실패 없음

## admin(이전 배치 49/60 → 이번 배치 67/67 전체 그린)

### 수정: 실제 코드 결함(1곳)

| 위치 | 근본 원인 | 수정 |
|------|------|------|
| `config/poster.php` | `image.driver` 기본값 `auto`, DriverFactory가 Imagick 확장을 감지하면 ImagickDriver를 선택하지만 본 머신 Imagick에는 `RESOURCETYPE_PIXELS` 상수가 없음 → 캡차 생성/포스터가 바로 500(운영 서비스도 동일하게 영향) | 드라이버 감지에 상수 가드 추가: `getenv('POSTER_IMAGE_DRIVER') ?: (defined('Imagick::RESOURCETYPE_PIXELS') ? 'auto' : 'gd')`, 상수 부재 시 자동으로 GD 폴백 |

### 수정: 노후화된 어서션(현재 코드와 대조 후 업데이트)

| 테스트 파일 | 케이스 | 근본 원인 | 수정 |
|----------|------|------|------|
| EnvConfigTest | env_file_exists / env_example_file_exists / getenv_reads_env_variables / config_env_keys_exist_in_dotenv(4 실패+1 오류) | `.env`/`.env.example` 존재와 getenv 값 유무를 어서션하지만 리포지토리가 env 파일을 제거했고 재구축 불가 | "`.env` 없이 실행" 계약으로 재작성: 각 `getenv()` 키는 `?:` 기본값 폴백이 있어야 하고, 기본 설정은 로컬 서비스(127.0.0.1:3306/open_admin)를 가리키며, 핵심 설정 타입이 정확해야 함 |
| BackendEnhancementTest | test_admin_user_source_contains_searchable | AdminUser가 Searchable 트레이트를 폐지(대신 `Erikwang2013\Encryptable\Encryptable`로 필드 투명 암복호화, `toSearchableArray()`는 유지) | Encryptable 트레이트를 어서션하도록 변경; toSearchableArray 어서션은 원래 통과하므로 유지 |
| BackendEnhancementTest | test_middleware_config_contains_cors_and_rate_limit | `config/middleware.php`가 `'@'` 글로벌 그룹 키 형식으로 변경, 최상위 배열에 미들웨어 클래스가 직접 포함되지 않음 | `$middlewares['@']`에 Cors와 RateLimit가 포함되는지 확인하도록 변경 |
| CaptchaTest | 전체 7개 케이스(원래 6 오류+1 실패) | 이중 노후화: (a) Imagick 상수 부재(이미 poster.php로 수정); (b) 구 poster-php 계약 기반 어서션 — `extra.targets`(x/y 포함)가 `extra.texts`(text+order만)로 변경, 좌표는 스토리지 계층에만 저장; 클릭 검증 형식이 `['x'=>, 'y'=>]`에서 `[x, y]` 숫자 쌍으로 변경 | 현재 계약에 맞게 재작성: 구조/난이도 수(2/3/4)/필드 검증, 올바른 클릭은 Redis(`poster:captcha:{key}`의 `data.targets`)에서 좌표를 읽어 검증, 잘못된 클릭은 실패, max_attempts(3) 초과 시 key 소비·삭제, key 고유성 |

### 신규 테스트(1개 파일, 12개 케이스)

`tests/AdminControllerTest.php`(저작권 헤더 포함), 커버:

- **BaseController::decodeId**(방금 수정된 404 동작): encode/decode 왕복 일치; 잘못된 hashid는 `support\exception\NotFoundException`을 code=404로 던짐; encodeIds는 ID 필드만 변경
- **RoleController**: super_admin 역할 update는 403 반환(실제 DB 데이터)
- **PermissionController::buildTree**: 권한 트리 중첩(2계층) + 노드 id 전부 hashid화
- **ConfigController**: group/key/value 누락 시 검증 422; 잘못된 hashid는 404
- **ExportController**: `admin_user` 내보내기 민감 필드 목록은 phone/email/id_card(나머지 테이블은 비어 있음); PDF HTML은 제목/셀 값을 htmlspecialchars로 이스케이프(XSS 방지)하고 저작권 문구 포함

### 알려진 설명

- 테스트에서 구성하는 webman Request는 원시 HTTP 메시지(buffer)로 전달(workerman Request 생성자 인자는 buffer라 method/uri만 전달하면 POST 본문을 파싱할 수 없음), AdminControllerTest 주석 참고
- 캡차 정답 클릭 케이스는 Redis에서 저장된 타깃을 읽어 검증; Redis를 사용할 수 없으면 해당 케이스는 markTestSkipped되어 스위트 결과에 영향 없음

## 미커버/추후 보완

- admin 각 model의 Encryptable 암복호화, OperationLog/AdminPermission 미들웨어와 RBAC 캐시 경로는 여전히 단위 테스트 부재, API 테스트 또는 이후 배치로 커버 권장
- 외부 서비스(ES/gRPC)에 의존하는 service 경로는 계속 유닛 레벨 stub 검증만 수행, 통합 레벨은 API 테스트로 커버
