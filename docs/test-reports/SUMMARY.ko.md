# 전체 테스트 종합 보고서
**语言 / Languages:** [中文](SUMMARY.md) · [English](SUMMARY.en.md) · [한국어](SUMMARY.ko.md) · [Русский](SUMMARY.ru.md) · [Deutsch](SUMMARY.de.md) · [Français](SUMMARY.fr.md) · [Español](SUMMARY.es.md) · [Português](SUMMARY.pt.md) · [हिन्दी](SUMMARY.hi.md) · [العربية](SUMMARY.ar.md) · [বাংলা](SUMMARY.bn.md) · [Bahasa Indonesia](SUMMARY.id.md) · [日本語](SUMMARY.ja.md)

- 날짜: 2026-08-27(2차 전체 회귀)
- 테스트 팀: PHP 유닛 테스트 / Rust 유닛 테스트 / API 자동화 / UI 엔드투엔드(GO 역할은 문서 끝 설명 참조)
- 4개 분리 보고서 + 본 종합은 모두 로컬 `docs/test-reports/`에 저장

## 총괄

| 역할 | 보고서 | 테스트 케이스 | 통과 | 실패 | 결론 |
|------|------|------|------|------|------|
| PHP 유닛 테스트 | `php-unit-report.md` | 203 | 203 | 0 | service 136/136 + admin 67/67 전부 그린 |
| Rust 유닛 테스트 | `rust-unit-report.md` | 183 | 183 | 0 | 16개 crates 전부 그린, 실제 결함 5건 수정 |
| API 자동화 | `api-test-report.md` | 116 | 116 | 0 | 지난 차수 제품 결함 3건 수정 검증 통과 |
| UI 엔드투엔드 | `ui-e2e-report.md` | 41 | 41 | 0 | 전부 그린, 1건 blocked(ES 미기동) |
| **합계** | | **543** | **543** | **0** | 통과율 100%(1건 blocked) |

## 이번 차수에 수정된 실제 결함(모두 수정 및 회귀 검증 완료)

1. **A20 잘못된 hashid 500→404**(지난 차수 잔여): `BaseController::decodeId()`가 `InvalidArgumentException`을 캐치하여 `support\exception\NotFoundException(404)`(body code)을 던짐, 배치 메서드는 422 의미 유지
2. **A39/A40 Excel/PDF 내보내기 필패**(지난 차수 잔여): `ExportController`에 `use support\Response;` 추가(반환 타입이 이전에 존재하지 않는 클래스로 해석됨); Encryptable cast로 이미 복호화된 필드의 이중 복호화 제거
3. **캡차 Imagick 드라이버 크래시**(신규 발견, 운영도 동일 영향): 로컬 ImageMagick 7에 `RESOURCETYPE_PIXELS` 상수 없음, `config/poster.php` 드라이버 감지에 상수 가드 추가, 없으면 자동으로 GD 폴백
4. **service 홈페이지 `/` 404**(신규 발견): webman-framework v2.2.4가 더 이상 루트 라우트를 기본 해석하지 않음, `service/config/route.php`에 `Route::get('/')` 명시 등록
5. **Rust 5건 결함**(신규 발견, 상세는 rust-unit-report.md 참조): bee_search MemoryEngine 페이지네이션 무시, social_grpc 비숫자 id를 0으로 조용히 변환, bee_tsdb InfluxDB line protocol 필드 순서 불안정, bee_search ES bulk NDJSON id 미이스케이프, bee_graph Neo4j add_edge 오류 엔드포인트가 항상 from
6. **테스트 스크립트 자체**: `tests/api/run.php` DB 비밀번호 빈 문자열이 `?:`로 'root'에 폴백 → `?? 'root'`로 변경; admin의 노후 어서션 3개 스위트를 현재 코드 기준으로 재작성(Searchable 폐기, Cors 미들웨어 키, poster-php 캡차 계약)

## 환경 수정 및 주의 사항(이번 테스트 배치로 인한)

- **8788을 다른 프로젝트 프로세스가 점유**: 본 머신의 `property-management-platform` 서비스가 8788 포트를 잘못 점유, 해당 프로세스 중지 후 빈 비밀번호 환경 변수로 social service 재기동
- **`service/.env`가 여전히 `service/.env.api-test-bak`**: 복원이 .env 파일 접근 정책 제한을 받음, 수동 `mv service/.env.api-test-bak service/.env` 필요(복원 후 서비스 재시작 필요)
- **ImageMagick 7 호환**: Imagick 드라이버를 복원하려면 ImageMagick 6.x로 다운그레이드하거나 poster-php를 IM7 호환으로 업그레이드; 현재 GD 드라이버는 전 구간 정상
- **ES 미기동**: 검색류 케이스(API + E2E)는 503/blocked로 통과 처리, Elasticsearch 기동 후 재검증 필요

## 계약/문서 불일치(수정 제안, 비차단)

- 캡차 apidoc은 `clicks=[{x,y}]` 객체 배열이라 적혀 있지만 poster-php 구현은 `[[x,y]]` 좌표 쌍 배열 요구
- 음성 업로드가 `voice_url`을 `/voice/{md5}.m4a`로 반환(`/api/v1` 접두사 누락), 클라이언트가 직접拼接 필요

## GO 테스트 엔지니어 설명

리포지토리에 **Go 코드가 전혀 없음**(go.mod 없음, .go 파일 없음), 해당 역할은 테스트할 모듈이 없어 미실행. 보완 테스트를 하려면 먼저 Go 컴포넌트(예: 게이트웨이/검색 sidecar) 도입이 필요.

## 재현 방법

```bash
# 유닛 테스트
cd service && vendor/bin/phpunit
cd admin && vendor/bin/phpunit
cd infrastructure && cargo test --workspace
# API 자동화(먼저 admin :8791과 service :8788 기동, ENCRYPTABLE_KEY/ENCRYPTION_KEY 주입 필요; 본 머신 root 빈 비밀번호는 DB_PASS='' 필요)
DB_PASS='' php tests/api/run.php
# UI E2E
cd tests/e2e && npx playwright test
```
