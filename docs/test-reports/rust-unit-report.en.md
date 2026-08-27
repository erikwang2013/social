# Rust Workspace Unit Test Report
**语言 / Languages:** [中文](rust-unit-report.md) · [English](rust-unit-report.en.md) · [한국어](rust-unit-report.ko.md) · [Русский](rust-unit-report.ru.md) · [Deutsch](rust-unit-report.de.md) · [Français](rust-unit-report.fr.md) · [Español](rust-unit-report.es.md) · [Português](rust-unit-report.pt.md) · [हिन्दी](rust-unit-report.hi.md) · [العربية](rust-unit-report.ar.md) · [বাংলা](rust-unit-report.bn.md) · [Bahasa Indonesia](rust-unit-report.id.md) · [日本語](rust-unit-report.ja.md)

- Date: 2026-08-27
- Location: `/home/wwwroot/social/infrastructure`
- Command: `cargo test --workspace` (default feature), plus separate verification of feature backends (tsdb/graph/search/kv)
- Result: **183 passed / 0 failed** (178 unit+integration + 5 feature-inline + 1 doctest, etc.; the default workspace includes bee_search's 6 cases because social_grpc depends on its `elasticsearch` feature)

## Summary

| crate | Test cases | Passed | Failed | Modules covered |
|-------|-------|------|------|----------|
| bee_cache | 8 | 8 | 0 | set/get/delete/expire, incr, ttl, MemoryCache |
| bee_cli | 14 | 14 | 0 | rust_type mapping, find_bin_name, validate_name, bin argument parsing |
| bee_config | 8 + 6 integration | 14 | 0 | IniParser (comments/whitespace/section switching), Config, ConfigSource, reload errors |
| bee_config_macro | 0 | — | — | covered indirectly through integration tests |
| bee_graph | 15 | 15 | 0 | StubGraphDB: traversal direction/depth/labels, add/update/delete, error paths, serde (feature neo4j another 5) |
| bee_kv | 10 | 10 | 0 | get/incr/expire/mset, expired keys, error paths (feature redis another 3 real-Redis cases) |
| bee_logs | 4 | 4 | 0 | level_str all levels, file output, stdout/stderr output |
| bee_orm | 19 integration | 19 | 0 | SelectBuilder: order/limit/offset/parameter binding/reuse/table_name/error Display (0 in lib) |
| bee_orm_macro | 0 | — | — | covered indirectly through integration tests |
| bee_router | 36 | 36 | 0 | context (params/text/html/abort), router (method/404/namespace), dispatch pipeline, session restore/persist/expired cookie |
| bee_rust | 2 (bin another 9) | 11 | 0 | prelude exports, Result alias, CLI argument parsing |
| bee_search | 20 (incl. 6 in-feature) | 20 | 0 | MemoryEngine: index/delete/overwrite/pagination/get/empty query/serde; Elasticsearch driver: get/search/bulk/aggregate, NDJSON escaping |
| bee_session | 8 | 8 | 0 | set/get/delete, save/load/refresh, TTL floor, error paths, UUID uniqueness |
| bee_template | 6 + 1 doctest | 7 | 0 | context! macro, render, missing template/variable errors, empty engine, non-finite floats (1 doctest) |
| bee_tsdb | 11 | 11 | 0 | writes/batch writes, range query boundaries, Eq/Neq/Regex/AND filtering, Point serde, CQ (feature influxdb another 5, incl. line protocol determinism) |
| social_grpc | 6 | 6 | 0 | SearchService: index/search/delete round trips, invalid JSON fallback, empty index, non-numeric id error |
| hello_bee | 0 | — | — | example program, no tests |

## Real defects fixed this round (minimal fixes + regression tests)

1. **bee_search MemoryEngine `search` ignores pagination** (`crates/bee_search/src/lib.rs`) — `from`/`size` passed from the gRPC layer were discarded, always returning all hits. Fix: reads `from`/`size` from the query JSON and applies skip/truncate on hits, `total` still counts all matches. Added `test_search_honors_from_size_pagination` (robust against unordered HashMap iteration: compares with a slice of the engine's own full result).
2. **social_grpc `search` silently turns non-numeric ids into 0** (`crates/social_grpc/src/search.rs:53-60`) — `h.id.parse().unwrap_or(0)` quietly returned non-numeric document ids as 0. Fix: parse failure returns `Status::invalid_argument`. Added `non_numeric_hit_id_becomes_invalid_argument`.
3. **bee_tsdb InfluxDB line protocol fields out of order** (`crates/bee_tsdb/src/influxdb.rs:160-170`) — tags are sorted but fields were not; output is non-deterministic with multiple fields. Fix: sort fields by key. Added `line_protocol_is_deterministic_across_field_insertion_order` (different insertion orders produce identical lines, sorted by a,b).
4. **bee_search Elasticsearch bulk NDJSON did not escape ids** (`crates/bee_search/src/elasticsearch.rs`) — index/id directly string-interpolated; ids containing `"` produced malformed NDJSON. Fix: extracted `bulk_ndjson()`, action lines serialized via serde_json. Added `bulk_ndjson_escapes_ids_and_stays_parseable`.
5. **bee_graph Neo4j `add_edge` error endpoint always `from`** (`crates/bee_graph/src/neo4j.rs:107-116`) — when the missing endpoint is `to`, the error message is misleading. Fix: when `nodes-matched < 2`, use `get_vertex` to determine the actually missing endpoint before reporting. Added `add_edge_reports_the_missing_endpoint` (mock HTTP service verifies it reports `to1` rather than `from1`).
6. **bee_template `context!` docs inconsistent with behavior** (`crates/bee_template/src/lib.rs`) — docs claim non-finite floats panic, but serde_json actually serializes them as `null` (proven by an existing test). Fix: updated the docs.

## New coverage

- **bee_kv RedisStore real-Redis integration tests** (`crates/bee_kv/src/redis_store.rs`, feature `redis`) — fills the coverage gap explicitly named in the previous report. Local Redis is available (127.0.0.1:6379); 3 cases: set/get/del round trip, incr/expire, mset/mget; keys carry a pid+nanosecond prefix, cases clean up after themselves. When Redis is unavailable the cases skip gracefully (print SKIP and pass through).

## Coverage gaps (unchanged)

- **bee_tsdb IoTDB `write_batch` non-atomic** (`crates/bee_tsdb/src/iotdb.rs`) — point-by-point `?` short-circuit writes, inconsistent with the trait docs' "atomically". Fixing requires backend transaction support; there is no local IoTDB instance, so no blind changes this round; listed as a known limitation.
- **External backends** (es/opensearch/clickhouse, neo4j/nebulagraph/arangodb, influxdb/iotdb/questdb, memcached) — the main drivers (elasticsearch, neo4j, influxdb write/query/CQ paths) are covered with local mock HTTP services; the rest without local services get compile-level verification only.
- **MySQL**: local 127.0.0.1:3306 is available (root, empty password), but no crate in the workspace introduces a MySQL driver — bee_orm is a driver-agnostic SQL builder, QuerySet cases do not depend on a real database; no driver dependency is needed nor should be added.
- **bee_config_macro / bee_orm_macro**: proc macros, covered indirectly through their integration tests, no standalone unit tests.

## Quality checks

- `cargo fmt --check`: passed (this round `cargo fmt` was run on the whole workspace, fixing 20+ formatting deviations left over from previous sessions).
- `cargo clippy --workspace --all-targets`: zero warnings in new code; the remaining 3 are pre-existing warnings (bee_config `get("default").is_none()`, bee_rust `unwrap()` on Ok, bee_search MemoryEngine missing a Default impl), out of scope this round.

## Environment notes

- cargo is located at `~/.cargo/bin` (not on PATH by default), requires `export PATH="$HOME/.cargo/bin:$PATH"`.
- `protoc` is now available (`/home/erik/.local/bin/protoc`).
- social_grpc runs in the background (port 50051); this report only executed `cargo test`, not `cargo run` on it.
- Redis (6379) and MySQL (3306) are available locally; feature case list:
  - `cargo test -p bee_tsdb --features influxdb` → 16 passed
  - `cargo test -p bee_search --features elasticsearch` → 20 passed
  - `cargo test -p bee_graph --features neo4j` → 20 passed
  - `cargo test -p bee_kv --features redis` → 13 passed (including 3 real-Redis cases)
