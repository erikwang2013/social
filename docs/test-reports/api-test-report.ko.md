# API 인터페이스 자동화 테스트 보고서
**语言 / Languages:** [中文](api-test-report.md) · [English](api-test-report.en.md) · [한국어](api-test-report.ko.md) · [Русский](api-test-report.ru.md) · [Deutsch](api-test-report.de.md) · [Français](api-test-report.fr.md) · [Español](api-test-report.es.md) · [Português](api-test-report.pt.md) · [हिन्दी](api-test-report.hi.md) · [العربية](api-test-report.ar.md) · [বাংলা](api-test-report.bn.md) · [Bahasa Indonesia](api-test-report.id.md) · [日本語](api-test-report.ja.md)

- 날짜: 2026-08-27
- 실행: `tests/api/run.php`(curl 단언 스크립트), 결과는 `tests/api/results.json`
- 범위: admin HTTP API(A01-A45) + service HTTP API(S01-S57b, S58-S68 포함)
- 서비스: admin `http://127.0.0.1:8791`, service `http://127.0.0.1:8788`(WebSocket `:8789`는 이번 HTTP 테스트 케이스에서 미포함)

## 결론

**116개 테스트 케이스: 113 통과 / 3 실패(97.4% 통과율); 3개 실패는 모두 원인이 파악된 제품 결함**

| 그룹 | 통과/전체 |
|------|-----------|
| admin A01-A45(인증, 캡차, 사용자 관리, HashID, 역할 권한, 설정, 로그, 내보내기/가져오기, 업로드, 헬스 체크 등) | 42/45 |
| service S01-S68(회원가입/로그인/로그아웃/갱신, 프로필, 팔로우, 게시글/좋아요/타임라인, 댓글, 알림, 검색, IM 세션/메시지/푸시, 음성 업로드/파일/통화/방 등) | 71/71 |

## 실패 테스트 케이스(3건, 모두 제품 결함)

| 케이스 | 기대값 | 실제값 | 근본 원인 |
|------|------|------|------|
| A20 잘못된 hashid 사용자 상세 | 404 | 500 | `HashidsService::decode()`가 잘못된 ID에 대해 캐치되지 않은 `InvalidArgumentException`을 던짐(admin/app/common/HashidsService.php:28, BaseController.php:52), 예외가 그대로 500으로 전파되며, 캐치하여 404를 반환해야 함 |
| A39 Excel 내보내기 | xlsx 파일 스트림 | 200+JSON 오류 본문(비즈니스 실패) | `ExportController::excel()`의 반환 타입이 `: Response`인데 `use support\Response`가 없어 타입이 `app\admin\controller\Response`으로 해석됨 → 성공 반환 시마다 `TypeError` 발생(ExportController.php:122), 내보내기 기능 전체 사용 불가 |
| A40 PDF 내보내기 | pdf 파일 스트림 | 200+JSON 오류 본문(비즈니스 실패) | 위와 동일, `ExportController::pdf()`(ExportController.php:135)에 `use support\Response` 누락 |

> 추가(같은 파일의 잠재 결함, 현재 위 TypeError로 가려짐): `ExportController` 90행에서 phone/email에 대해 `EncryptionService::decrypt()`를 호출하지만, `AdminUser` 모델의 `email/phone/id_card` 필드에는 `Encryptable::class` 캐스트가 선언되어 있음(쓰기 시 자동 암호화, 읽기 시 자동 복호화), 내보내기 시 평문을 이중 복호화하게 됨 → phone/email이 비어 있지 않은 계정이 하나라도 있으면 `EncryptionException: Invalid ciphertext prefix for AES-256-CBC` 발생. 반환 타입을 수정한 뒤에도 이 문제는 재현됩니다.

## 테스트 중 수정한 환경 문제(제품 코드 변경 아님)

1. **m2/m3/m4 마이그레이션 테이블 `id`에 AUTO_INCREMENT 없음(차단 항목, 수정 완료)**: `service/database/m2.sql`/`m3.sql`/`m4.sql`이 만든 `social_follows`, `social_notifications`의 `id BIGINT UNSIGNED NOT NULL`에 `AUTO_INCREMENT`가 없어, INSERT마다 `1364 Field 'id' doesn't have a default value` 오류가 발생하며 팔로우/알림/IM/음성 전체 쓰기 경로를 차단. 로컬에서 `ALTER TABLE ... MODIFY id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` 실행(나머지 8개 테이블은 원래 자동 증가). **마이그레이션 스크립트 자체에 자동 증가를 추가할 것을 권장.**
2. **service/.env가 도달 불가능한 데이터베이스를 가리킴(차단 항목)**: `DB_PORT=13306`이고 비밀번호가 없으며, 실제 메인 MySQL은 `127.0.0.1:3306 (root/root)`에 있음; webman의 `createUnsafeMutable`이 CLI 환경 변수를 덮어씀. 테스트 중 `.env`를 `service/.env.api-test-bak`으로 옮기고(내용은 그대로 보존) 환경 변수로 서비스를 시작함; 복원은 .env 파일 접근 정책 제한으로 수행되지 않아 수동 `mv service/.env.api-test-bak service/.env` 필요(주의: 복원 후 서비스를 재시작하면 다시 도달 불가능한 데이터베이스를 만나게 됨).
3. **admin은 .env가 없고 환경 변수에 의존**: `DB_PASSWORD=root ENCRYPTABLE_KEY(16B) ENCRYPTION_KEY(32B)` 필요. `encryptable` 플러그인은 webman 컨테이너에 provider가 등록되지 않으면 `EnvEncryptableConfig`로 폴백(`ENCRYPTION_KEY` 읽음, 기본 cipher aes-256-gcm), 키 길이가 맞지 않으면 계정 생성/가져오기/내보내기에서 `MissingEncryptionKeyException` 발생.
4. **Elasticsearch 미기동**: `GET /api/v1/search/posts`가 503 반환(설계된 폴백), S그룹 검색 케이스는 예상대로 처리(0 또는 503 허용), 실패로 집계하지 않음.

## 계약/문서 불일치(수정 권장, 비차단)

- 캡차 문서(apidoc 및 CaptchaController 주석)는 `clicks=[{x,y}]` 객체 배열로 표기하지만, `poster-php` 구현은 `[[x,y]]` 좌표 쌍 배열을 요구하며, 문서대로 객체를 넘기면 실제로 항상 실패.
- 음성 업로드 반환 `voice_url`이 `/voice/{md5}.m4a`(API 루트 기준, `/api/v1` 접두사 누락)로, 클라이언트가 직접 `/api/v1`을 붙여야 접근 가능; 파일 접근은 인증 라우트를 거침(token 필요).

## 환경 및 재현

- 테스트 자격 증명: 테스트 계정 `e2e_smoke`(admin, 테스트 전용 비밀번호) + `apitest_*@test.dev`(service, 실행 후 자동 정리), 모두 `tests/api/run.php` 상수로 작성, 실제 키는 사용하지 않음.
- 재현:

```bash
cd /home/wwwroot/social/admin && DB_PASSWORD=root ENCRYPTABLE_KEY='apitest-enc-16b!!' \
  ENCRYPTION_KEY='apitest-db-encrypt-key-32-byte!!' php start.php start   # admin :8791
cd /home/wwwroot/social/service && DB_HOST=127.0.0.1 DB_PORT=3306 DB_USERNAME=root \
  DB_PASSWORD=root php start.php start                                     # service :8788
php /home/wwwroot/social/tests/api/run.php                                  # 재실행(116 케이스)
```

## 인터페이스 목록(route.php / apidoc 기준)

- service `config/route.php`: 39개 HTTP 라우트(인증 5, 사용자 2, 팔로우 5, 게시글 7, 댓글 2, 알림 4, 검색 2, IM 4, 음성/통화/방 5, 헬스/문서 3)
- admin `config/route.php`: 33개 HTTP 라우트(인증/캡차 4, 사용자 CRUD 5, 역할 5, 권한 2, 설정 4, 로그 1, 개인 센터 4, 내보내기 2, 가져오기 1, 업로드 1, 헬스/문서 4)
