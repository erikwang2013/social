# Rust Workspace 유닛 테스트 보고서
**语言 / Languages:** [中文](rust-unit-report.md) · [English](rust-unit-report.en.md) · [한국어](rust-unit-report.ko.md) · [Русский](rust-unit-report.ru.md) · [Deutsch](rust-unit-report.de.md) · [Français](rust-unit-report.fr.md) · [Español](rust-unit-report.es.md) · [Português](rust-unit-report.pt.md) · [हिन्दी](rust-unit-report.hi.md) · [العربية](rust-unit-report.ar.md) · [বাংলা](rust-unit-report.bn.md) · [Bahasa Indonesia](rust-unit-report.id.md) · [日本語](rust-unit-report.ja.md)

- 날짜: 2026-08-27
- 위치: `/home/wwwroot/social/infrastructure`
- 명령: `cargo test --workspace`(기본 features), 추가로 feature-gated 백엔드 검증(tsdb/graph/search/kv)
- 결과: **183 통과 / 0 실패**(유닛+통합 178 + feature 인라인 5 + doctest 1 등; 기본 workspace에는 bee_search의 6개 케이스가 포함됨. social_grpc가 해당 `elasticsearch` feature에 의존하기 때문)

## 요약

| crate | 테스트 수 | 통과 | 실패 | 커버 모듈 |
|-------|-------|------|------|----------|
| bee_cache | 8 | 8 | 0 | set/get/delete/expire, incr, ttl, MemoryCache |
| bee_cli | 14 | 14 | 0 | rust_type 매핑, find_bin_name, validate_name, bin 인자 파싱 |
| bee_config | 8 + 6 통합 | 14 | 0 | IniParser(주석/공백/섹션 전환), Config, ConfigSource, 리로드 오류 |
| bee_config_macro | 0 | — | — | integration tests를 통해 간접 커버 |
| bee_graph | 15 | 15 | 0 | StubGraphDB: 순회 방향/깊이/라벨, add/update/delete, 오류 경로, serde(feature neo4j 추가 5) |
| bee_kv | 10 | 10 | 0 | get/incr/expire/mset, 만료 키, 오류 경로(feature redis 추가 실 Redis 3케이스) |
| bee_logs | 4 | 4 | 0 | level_str 전체 레벨, file output, stdout/stderr output |
| bee_orm | 19 통합 | 19 | 0 | SelectBuilder: order/limit/offset/파라미터 바인딩/재사용/table_name/오류 Display(lib 내 0개) |
| bee_orm_macro | 0 | — | — | integration tests를 통해 간접 커버 |
| bee_router | 36 | 36 | 0 | context(params/text/html/abort), router(method/404/namespace), dispatch 파이프라인, 세션 복원/영속화/만료 쿠키 |
| bee_rust | 2(bin 추가 9) | 11 | 0 | prelude 내보내기, Result 별칭, CLI 인자 파싱 |
| bee_search | 20(인라인 6 포함) | 20 | 0 | MemoryEngine: index/delete/덮어쓰기/페이지네이션/get/빈 쿼리/serde; Elasticsearch 드라이버: get/search/bulk/aggregate, NDJSON 이스케이프 |
| bee_session | 8 | 8 | 0 | set/get/delete, save/load/refresh, TTL floor, 오류 경로, UUID 고유성 |
| bee_template | 6 + 1 doctest | 7 | 0 | context! 매크로, render, 템플릿/변수 누락 오류, 빈 engine, 비유한 부동소수점(1 doctest) |
| bee_tsdb | 11 | 11 | 0 | 쓰기/배치 쓰기, 범위 쿼리 경계, Eq/Neq/Regex/AND 필터링, Point serde, CQ(feature influxdb 추가 5, line protocol 결정성 포함) |
| social_grpc | 6 | 6 | 0 | SearchService: index/search/delete 왕복, 잘못된 JSON 폴백, 빈 인덱스, 비숫자 id 오류 |
| hello_bee | 0 | — | — | 예제 프로그램, 테스트 없음 |

## 이번 라운드에서 수정된 실제 결함(최소 수정 + 회귀 테스트)

1. **bee_search MemoryEngine `search`가 페이지네이션 무시** (`crates/bee_search/src/lib.rs`) — gRPC 계층에서 전달된 `from`/`size`가 폐기되어 항상 모든 히트를 반환. 수정: 쿼리 JSON에서 `from`/`size`를 읽어 hits에 skip/truncate 적용, `total`은 전체 매치 수를 계속 집계. 신규: `test_search_honors_from_size_pagination`(순서가 정해지지 않은 HashMap 이터레이션에 강건: 엔진 자체 전체 결과 슬라이스와 비교).
2. **social_grpc `search`가 비숫자 id를 조용히 0으로 변환** (`crates/social_grpc/src/search.rs:53-60`) — `h.id.parse().unwrap_or(0)`이 비숫자 문서 id를 조용히 0으로 반환. 수정: 파싱 실패 시 `Status::invalid_argument` 반환. 신규: `non_numeric_hit_id_becomes_invalid_argument`.
3. **bee_tsdb InfluxDB line protocol 필드 순서 불일치** (`crates/bee_tsdb/src/influxdb.rs:160-170`) — tags는 정렬되지만 fields는 정렬되지 않아 다중 필드 시 출력이 비결정적. 수정: fields를 키로 정렬. 신규: `line_protocol_is_deterministic_across_field_insertion_order`(삽입 순서가 달라도 동일한 줄, a,b 순서).
4. **bee_search Elasticsearch bulk NDJSON이 id를 이스케이프하지 않음** (`crates/bee_search/src/elasticsearch.rs`) — index/id를 문자열에 직접 보간; `"`가 포함된 id는 잘못된 NDJSON 생성. 수정: `bulk_ndjson()` 추출, 액션 줄은 serde_json으로 직렬화. 신규: `bulk_ndjson_escapes_ids_and_stays_parseable`.
5. **bee_graph Neo4j `add_edge` 오류 엔드포인트가 항상 `from`** (`crates/bee_graph/src/neo4j.rs:107-116`) — 누락 엔드포인트가 `to`일 때 오해를 부르는 오류 메시지. 수정: `nodes-matched < 2`일 때 `get_vertex`로 실제 누락 엔드포인트를 판별 후 보고. 신규: `add_edge_reports_the_missing_endpoint`(목 HTTP 서비스가 `from1`이 아닌 `to1`을 보고함을 검증).
6. **bee_template `context!` 문서가 동작과 불일치** (`crates/bee_template/src/lib.rs`) — 비유한 부동소수점이 panic한다고 문서에 있으나 실제 serde_json은 `null`로 직렬화(기존 테스트로 입증). 수정: 문서 업데이트.

## 신규 커버리지

- **실제 Redis를 사용한 bee_kv RedisStore 통합 테스트** (`crates/bee_kv/src/redis_store.rs`, feature `redis`) — 이전 보고서에서 명시적으로 언급한 커버리지 공백 해소. 로컬 Redis 사용 가능(127.0.0.1:6379); 3케이스: set/get/del 왕복, incr/expire, mset/mget; 키는 pid+나노초 접두사, 케이스가 스스로 정리. Redis가 없으면 SKIP 출력 후 통과(우아하게 건너뜀).

## 커버리지 공백(현상 유지)

- **bee_tsdb IoTDB `write_batch` 비원자적** (`crates/bee_tsdb/src/iotdb.rs`) — 포인트 단위 `?` 단락 쓰기, trait 문서의 "atomically"와 불일치. 수정에는 백엔드 트랜잭션 지원 필요; 로컬 IoTDB 인스턴스가 없어 이번 라운드에서 맹목적 변경 없음; 알려진 제한으로 기록.
- **외부 백엔드**(es/opensearch/clickhouse, neo4j/nebulagraph/arangodb, influxdb/iotdb/questdb, memcached) — 주요 드라이버(elasticsearch, neo4j, influxdb 쓰기/쿼리/CQ 경로)는 로컬 목 HTTP 서비스로 커버; 로컬 서비스가 없는 나머지는 컴파일 수준 검증만.
- **MySQL**: 로컬 127.0.0.1:3306 사용 가능(root, 빈 비밀번호)하지만 workspace의 어떤 crate도 MySQL 드라이버를 도입하지 않음 — bee_orm은 드라이버 독립 SQL 빌더이며 QuerySet 케이스는 실제 DB에 의존하지 않음; 드라이버 의존성은 필요 없고 추가해서도 안 됨.
- **bee_config_macro / bee_orm_macro**: proc-macro, 각 integration tests로 간접 커버되며 독립 유닛 테스트 없음.

## 품질 검사

- `cargo fmt --check`: 통과(이번 라운드에서 workspace 전체에 `cargo fmt` 실행, 이전 세션들이 남긴 20+ 포맷 편차 수정).
- `cargo clippy --workspace --all-targets`: 신규 코드 경고 0; 남은 3개는 기존 경고(bee_config `get("default").is_none()`, bee_rust의 Ok에 대한 `unwrap()`, bee_search MemoryEngine Default impl 부재), 이번 라운드 범위 밖.

## 환경 메모

- cargo는 `~/.cargo/bin`에 있음(기본 PATH에 없음), `export PATH="$HOME/.cargo/bin:$PATH"` 필요.
- `protoc` 사용 가능(`/home/erik/.local/bin/protoc`).
- social_grpc가 백그라운드로 실행 중(포트 50051); 이 보고서는 `cargo test`만 실행, `cargo run`은 하지 않음.
- Redis (6379)와 MySQL (3306) 로컬 사용 가능; feature 케이스 목록:
  - `cargo test -p bee_tsdb --features influxdb` → 16 통과
  - `cargo test -p bee_search --features elasticsearch` → 20 통과
  - `cargo test -p bee_graph --features neo4j` → 20 통과
  - `cargo test -p bee_kv --features redis` → 13 통과(실 Redis 3케이스 포함)
