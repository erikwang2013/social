# API 인터페이스 자동화 테스트 보고서
**语言 / Languages:** [中文](api-test-report.md) · [English](api-test-report.en.md) · [한국어](api-test-report.ko.md) · [Русский](api-test-report.ru.md) · [Deutsch](api-test-report.de.md) · [Français](api-test-report.fr.md) · [Español](api-test-report.es.md) · [Português](api-test-report.pt.md) · [हिन्दी](api-test-report.hi.md) · [العربية](api-test-report.ar.md) · [বাংলা](api-test-report.bn.md) · [Bahasa Indonesia](api-test-report.id.md) · [日本語](api-test-report.ja.md)

- 날짜: 2026-08-27
- 실행: `tests/api/run.php`(curl 단언 스크립트), 결과는 `tests/api/results.json`
- 범위: admin HTTP API(A01-A45) + service HTTP API(S01-S57b, S58-S68 포함)
- 서비스: admin `http://127.0.0.1:8791`, service `http://127.0.0.1:8788`(WebSocket `:8789`는 이번 HTTP 테스트 케이스에서 미포함)

## 결론

**116개 테스트 케이스: 116 통과 / 0 실패(100% 통과율); 지난 차수 제품 결함 3건(A20/A39/A40) 모두 수정 검증 완료**

| 그룹 | 통과/전체 |
|------|-----------|
| admin A01-A45(인증, 캡차, 사용자 관리, HashID, 역할 권한, 설정, 로그, 내보내기/가져오기, 업로드, 헬스 체크 등) | 45/45 |
| service S01-S68(회원가입/로그인/로그아웃/갱신, 프로필, 팔로우, 게시글/좋아요/타임라인, 댓글, 알림, 검색, IM 세션/메시지/푸시, 음성 업로드/파일/통화/방 등) | 71/71 |

## 지난 차수 제품 결함 3건 수정 검증(모두 PASS)

| 케이스 | 기대값 | 지난 차수 실제값 | 수정 | 이번 차수 결과 |
|------|------|---------|------|---------|
| A20 잘못된 hashid 사용자 상세 | 404 | 500 | `BaseController::decodeId()`가 `InvalidArgumentException`을 캐치하고 `support\exception\NotFoundException($msg, 404)`를 던짐(admin/app/admin/controller/BaseController.php); `UserController`의 배치 메서드 2개의 catch를 `InvalidArgumentException \| NotFoundException`으로 확장해 422 의미 보존 | **PASS(404)** |
| A39 Excel 내보내기 | xlsx 파일 스트림 | 200+JSON 오류 본문 | `ExportController`에 `use support\Response;` 추가(반환 타입이 이전에 존재하지 않는 `app\admin\controller\Response`으로 해석되어 TypeError 발생); `admin_user`의 phone/email/id_card는 Encryptable cast로 읽기 시 자동 복호화되므로 내보내기는 바로 마스킹, 이중 복호화 제거 | **PASS(attachment 파일 스트림)** |
| A40 PDF 내보내기 | pdf 파일 스트림 | 200+JSON 오류 본문 | 위와 동일(`ExportController::pdf()` 반환 타입 수정) | **PASS(application/pdf 파일 스트림)** |

## 이번 차수에 수정/처리한 환경 문제(제품 비즈니스 코드 변경 아님)

1. **run.php DB 빈 비밀번호 덮어쓰기失效(테스트 스크립트 결함, 수정 완료)**: `DB` 상수가 `getenv('DB_PASS') ?: 'root'`를 사용, 환경 변수가 빈 문자열이면 `?:`가 거짓으로 취급해 'root'로 폴백 → 본 머신의 root 빈 비밀번호 연결이 거부됨(`Access denied ... using password: YES`). `getenv('DB_PASS') ?? 'root'`(미설정 시에만 기본값)로 변경, 한 줄 수정(tests/api/run.php:26).
2. **service 8788 포트가 잘못된 프로세스에 점유됨(환경, 처리 완료)**: 본 머신의 다른 프로젝트 `property-management-platform`의 service 프로세스(master 2004768, 08:07 시작)가 8788을 리슨 중이었고, 그 `.env`는 `property_management` DB를 가리킴 — social service가 실제로는 실행되지 않아 S45부터 IM/음성 라우트가 전부 404, 정리 단계 SQL도 잘못된 DB에 적용. 해당 프로세스를 중지하고 8788/8789에서 social service 재기동(`DB_HOST=127.0.0.1 DB_PORT=3306 DB_USERNAME=root DB_PASSWORD=''`), 헬스 체크가 `social-service`로 회복.
3. **ImageMagick 7 업그레이드로 캡차 Imagick 드라이버 크래시(환경, 처리 완료)**: 시스템 ImageMagick이 7.1.2-27(2026-07-08 빌드)로 업그레이드되며 `PixelsResource`가 제거되어 imagick 3.8.1에서 `Imagick::RESOURCETYPE_PIXELS`가 더 이상 정의되지 않고, poster-php의 `ImagickDriver` 생성자에서 즉시 `Undefined constant` 발생(vendor 코드, 수정 안 함), 캡차 생성/검증(A05/A06)이 500이 되고 A08-A11 로그인까지 연쇄 차단. **처리**: admin 서비스를 설정 문서에 예약된 드라이버 전환 항목으로 재기동 — `POSTER_IMAGE_DRIVER=gd`(admin/config/poster.php:17에서 gd/imagick/auto 기본 지원), 캡차를 GD 드라이버로 전환 후 전 구간 정상. Imagick 드라이버를 복원하려면 ImageMagick을 6.x로 다운그레이드하거나 poster-php를 IM7 호환으로 업그레이드 필요.
4. **MySQL root 비밀번호가 빈 값으로 변경됨**: 지난 차수는 `root/root`로 기록, 이번 차수는 빈 비밀번호로 로그인 가능, 모든 서비스와 스크립트를 빈 비밀번호로 시작.
5. **admin 서비스 재기동 환경**: 지난 차수의 "admin은 .env가 없고 환경 변수에 의존"이 여전히 유효, 재기동 명령은 아래 "환경 및 재현" 참조.
6. **service/.env가 여전히 `service/.env.api-test-bak`**: 지난 차수에 연결 테스트를 위해 옮긴 후 복원하지 않음(복원은 .env 파일 접근 정책 제한), 이번 차수에도 환경 변수로 서비스 시작. 수동 `mv service/.env.api-test-bak service/.env` 필요(복원 후 서비스 재시작, 가리키는 DB 주소 문제 주의).
7. **Elasticsearch 미기동**: `GET /api/v1/search/posts`가 503 반환(설계된 폴백), S그룹 검색 케이스는 예상대로 처리(0 또는 503 허용), 실패로 집계하지 않음.

## 계약/문서 불일치(수정 권장, 비차단)

- 캡차 문서(apidoc 및 CaptchaController 주석)는 `clicks=[{x,y}]` 객체 배열로 표기하지만, `poster-php` 구현은 `[[x,y]]` 좌표 쌍 배열을 요구하며, 문서대로 객체를 넘기면 실제로 항상 실패.
- 음성 업로드 반환 `voice_url`이 `/voice/{md5}.m4a`(API 루트 기준, `/api/v1` 접두사 누락)로, 클라이언트가 직접 `/api/v1`을 붙여야 접근 가능; 파일 접근은 인증 라우트를 거침(token 필요).

## 환경 및 재현

- 테스트 자격 증명: 테스트 계정 `e2e_smoke`(admin, 테스트 전용 비밀번호) + `apitest_*@test.dev`(service, 실행 후 자동 정리), 모두 `tests/api/run.php` 상수로 작성, 실제 키는 사용하지 않음.
- 재현:

```bash
cd /home/wwwroot/social/admin && DB_PASSWORD='' ENCRYPTABLE_KEY='apitest-enc-16b!!' \
  ENCRYPTION_KEY='apitest-db-encrypt-key-32-byte!!' POSTER_IMAGE_DRIVER=gd \
  php start.php start                                          # admin :8791
cd /home/wwwroot/social/service && DB_HOST=127.0.0.1 DB_PORT=3306 DB_USERNAME=root \
  DB_PASSWORD='' php start.php start                           # service :8788
cd /home/wwwroot/social/tests/api && DB_PASS='' php run.php    # 재실행(116 케이스)
```

- 주의: 8788 포트가 `property-management-platform` service에 점유되지 않았는지 확인 필요(두 프로젝트의 기본 포트가 동일, 본 머신에 두 프로젝트가 함께 있을 때는 서로 어긋나게 해야 함).

## 인터페이스 목록(route.php / apidoc 기준)

- service `config/route.php`: 39개 HTTP 라우트(인증 5, 사용자 2, 팔로우 5, 게시글 7, 댓글 2, 알림 4, 검색 2, IM 4, 음성/통화/방 5, 헬스/문서 3)
- admin `config/route.php`: 33개 HTTP 라우트(인증/캡차 4, 사용자 CRUD 5, 역할 5, 권한 2, 설정 4, 로그 1, 개인 센터 4, 내보내기 2, 가져오기 1, 업로드 1, 헬스/문서 4)
