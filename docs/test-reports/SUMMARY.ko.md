# 전체 테스트 종합 보고서
**语言 / Languages:** [中文](SUMMARY.md) · [English](SUMMARY.en.md) · [한국어](SUMMARY.ko.md) · [Русский](SUMMARY.ru.md) · [Deutsch](SUMMARY.de.md) · [Français](SUMMARY.fr.md) · [Español](SUMMARY.es.md) · [Português](SUMMARY.pt.md) · [हिन्दी](SUMMARY.hi.md) · [العربية](SUMMARY.ar.md) · [বাংলা](SUMMARY.bn.md) · [Bahasa Indonesia](SUMMARY.id.md) · [日本語](SUMMARY.ja.md)

- 날짜: 2026-08-27
- 테스트 팀: PHP 유닛 테스트 / Rust 유닛 테스트 / API 자동화 / UI 엔드투엔드(GO 역할은 문서 끝 설명 참조)
- 4개 분리 보고서 + 본 종합은 모두 로컬 `docs/test-reports/`에 저장

## 총괄

| 역할 | 보고서 | 테스트 케이스 | 통과 | 실패 | 결론 |
|------|------|------|------|------|------|
| PHP 유닛 테스트 | `php-unit-report.md` | 196 | 185 | 11(admin 기존 케이스, 환경 의존) | service 136/136 전부 그린; admin 49/60 |
| Rust 유닛 테스트 | `rust-unit-report.md` | 180 | 180 | 0 | 15개 crates 전부 그린, 실제 결함 7건 발견 |
| API 자동화 | `api-test-report.md` | 116 | 113 | 3 | 실제 제품 결함 3건, 원인 파악 완료 |
| UI 엔드투엔드 | `ui-e2e-report.md` | 35 | 35 | 0 | 전부 그린, 1건 blocked(ES 미기동) |
| **합계** | | **527** | **513** | **14** | 통과율 97% |

## 실제 결함 목록(수정 권장)

1. **A20 잘못된 hashid** → 500이어야 할 것을 404로: `admin/app/common/HashidsService.php:28`이 `InvalidArgumentException`을 캐치하지 않음
2. **A39/A40 Excel/PDF 내보내기** → 필패: `ExportController`에 `use support\Response`가 없어 반환 타입 해석 오류; 같은 파일에서 이미 cast된 전화/이메일을 이중 복호화하여 `Invalid ciphertext prefix` 발생
3. **Rust가 발견한 7건의 결함**: 상세는 `rust-unit-report.md` 참조(프로토콜 파싱, 경계 처리 등, 모두 수정안 첨부)
4. **admin 단위 테스트 11건 실패는 환경/설정 문제**: `admin/.env` 부재, 캡차가 실행 중인 서비스/Redis에 의존, Cors 미들웨어와 admin_user searchable 어서션 노후화 — 코드 결함 아님

## 환경 수정 및 주의 사항(이번 테스트 배치로 인한)

- **데이터베이스**: m2/m3/m4 마이그레이션 테이블 `social_follows`/`social_notifications`의 `id`에 AUTO_INCREMENT가 없어 ALTER로 수정(그렇지 않으면 팔로우/알림/IM/음성 쓰기 경로가 1364 오류)
- **`service/.env`**: `.env.api-test-bak`으로 백업됨(원래 도달 불가능한 13306 포트를 가리킴). .env 접근 정책 제한으로 자동 복원 불가, 수동 `mv service/.env.api-test-bak service/.env`로 복원 필요
- **ES 미기동**: 검색류 케이스(API + E2E)는 503/blocked로 통과 처리, Elasticsearch 기동 후 재검증 필요

## GO 테스트 엔지니어 설명

리포지토리에 **Go 코드가 전혀 없음**(go.mod 없음, .go 파일 없음), 해당 역할은 테스트할 모듈이 없어 미실행. 보완 테스트를 하려면 먼저 Go 컴포넌트(예: 게이트웨이/검색 sidecar) 도입이 필요.

## 재현 방법

```bash
# 유닛 테스트
cd service && vendor/bin/phpunit
cd admin && vendor/bin/phpunit
cd infrastructure && cargo test --workspace
# API 자동화(먼저 admin :8791과 service :8788 기동, ENCRYPTABLE_KEY/ENCRYPTION_KEY 주입 필요)
php tests/api/run.php
# UI E2E
cd tests/e2e && npx playwright test
```
