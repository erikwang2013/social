# Rust Workspace 유닛 테스트 보고서
**语言 / Languages:** [中文](rust-unit-report.md) · [English](rust-unit-report.en.md) · [한국어](rust-unit-report.ko.md) · [Русский](rust-unit-report.ru.md) · [Deutsch](rust-unit-report.de.md) · [Français](rust-unit-report.fr.md) · [Español](rust-unit-report.es.md) · [Português](rust-unit-report.pt.md) · [हिन्दी](rust-unit-report.hi.md) · [العربية](rust-unit-report.ar.md) · [বাংলা](rust-unit-report.bn.md) · [Bahasa Indonesia](rust-unit-report.id.md) · [日本語](rust-unit-report.ja.md)

- 날짜: 2026-08-27
- 위치: `/home/wwwroot/social/infrastructure`
- 명령: `cargo test --workspace`(기본 features), 추가로 feature-gated 백엔드 검증(tsdb/graph/search/kv)
- 결과: **180 통과 / 0 실패**(179 유닛+통합 테스트 + 1 doctest)

## 요약

| crate | 테스트 수 | 통과 | 실패 | 커버 모듈 |
|-------|-------|------|------|----------|
| bee_cache | 8 | 8 | 0 | set/get/delete/expire, incr, ttl, MemoryCache |
| bee_cli | 23 | 23 | 0 | rust_type 매핑, find_bin_name, validate_name, bin 인자 파싱 |
| bee_config | 14 | 14 | 0 | IniParser(주석/공백/섹션 전환), Config, ConfigSource, integration 6 |
| bee_config_macro | 0 | — | — | integration tests를 통해 간접 커버 |
| bee_graph | 15 | 15 | 0 | StubGraphDB: 순회 방향/깊이/라벨, add/update/delete, 오류 경로, serde(feature 백엔드 추가 29) |
| bee_kv | 10 | 10 | 0 | get/incr/expire/mset, 만료 키, 오류 경로 |
| bee_logs | 4 | 4 | 0 | level_str 전체 레벨, file output, stdout/stderr output |
| bee_orm | 19 | 19 | 0 | SelectBuilder(통합): order/limit/offset/파라미터 바인딩/재사용/table_name/오류 display(lib 내 0개) |
| bee_orm_macro | 0 | — | — | integration tests를 통해 간접 커버 |
| bee_router | 36 | 36 | 0 | context(params/text/html/abort), router(method/404/namespace), dispatch 파이프라인, 세션 복원/영속화/만료 쿠키 |
| bee_rust | 2 | 2 | 0 | prelude 내보내기, Result 별칭 |
| bee_search | 18 | 18 | 0 | MemoryEngine: index/delete/덮어쓰기/페이지네이션/get/빈 쿼리/serde(feature 백엔드 추가 20) |
| bee_session | 8 | 8 | 0 | set/get/delete, save/load/refresh, TTL floor, 오류 경로, UUID 고유성 |
| bee_template | 6+1 | 7 | 0 | context! 매크로, render, 템플릿/변수 누락 오류, 빈 engine, 비유한 부동소수점(doctest 1 포함) |
| bee_tsdb | 10 | 10 | 0 | Query 필터(Neq/Regex/범위/AND), Point serde, enum debug(feature 백엔드 추가 22) |
| social_grpc | 5 | 5 | 0 | SearchService: index/search/delete 왕복, 잘못된 JSON 폴백, 빈 인덱스 |
| hello_bee | 0 | — | — | 예제 프로그램, 테스트 없음 |

## 미커버 목록

- **bee_kv `redis` feature(RedisStore)**: 라이브 Redis 서버 필요, 미커버
- **hello_bee**: 예제 프로그램, 0 테스트
- **feature-gated 백엔드**(기본 features에서 미컴파일): 각 feature 조합으로 컴파일 및 테스트 통과 검증 완료(tsdb 22, graph 29, search 20, kv 10), 다만 es/opensearch/clickhouse, neo4j/nebulagraph/arangodb, influxdb/iotdb/questdb, redis 등 실제 백엔드는 외부 서비스가 필요해 컴파일 수준 검증만 수행
- **bee_config_macro / bee_orm_macro**: proc-macro, 각 integration tests로 간접 커버되며 독립 유닛 테스트는 없음

## 기록된 실제 버그(라이브러리 소스 미수정)

1. `bee_tsdb/src/influxdb.rs:160-169` — `line_protocol`이 `&point.fields`(HashMap)를 정렬 없이 순회하는데 tags는 정렬됨 → 필드가 여러 개일 때 line protocol 출력이 비결정적
2. `bee_tsdb/src/iotdb.rs:37-42` — `write_batch`가 비원자적(포인트 단위 실행 + `?` 단락), trait 문서의 "atomically" 주장과 불일치
3. `bee_graph/src/neo4j.rs:106-109` — `add_edge`가 누락된 엔드포인트가 `to`인 경우에도 항상 `VertexNotFound(edge.from)` 반환
4. `bee_search` MemoryEngine `search` — gRPC 계층에서 전달된 from/size 무시(페이지네이션 없음)
5. `social_grpc/src/search.rs:54` — `h.id.parse().unwrap_or(0)`: 숫자가 아닌 id가 조용히 0으로 변환
6. `bee_template/src/lib.rs` `context!` 매크로 문서 — NaN이 panic한다고 주장하지만, 실제 serde_json ≥1.0.128은 null로 직렬화(문서 노후화)
7. `bee_search/src/elasticsearch.rs:64` — bulk NDJSON이 index/id를 JSON에 원시 삽입; id에 `"`가 있으면 잘못된 NDJSON 생성

## 환경 메모

- cargo는 `~/.cargo/bin`에 있음(PATH에 없음), `export PATH="$HOME/.cargo/bin:$PATH"` 필요
- social_grpc는 `protoc` 필요: `apt-get download protobuf-compiler` + `dpkg-deb -x`로 `/tmp/protoc-local`에 압축 해제, `PROTOC=/tmp/protoc-local/usr/bin/protoc`(sudo 불필요)
