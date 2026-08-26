# Rust Workspace Unit Test Report
**语言 / Languages:** [中文](rust-unit-report.md) · [English](rust-unit-report.en.md) · [한국어](rust-unit-report.ko.md) · [Русский](rust-unit-report.ru.md) · [Deutsch](rust-unit-report.de.md) · [Français](rust-unit-report.fr.md) · [Español](rust-unit-report.es.md) · [Português](rust-unit-report.pt.md) · [हिन्दी](rust-unit-report.hi.md) · [العربية](rust-unit-report.ar.md) · [বাংলা](rust-unit-report.bn.md) · [Bahasa Indonesia](rust-unit-report.id.md) · [日本語](rust-unit-report.ja.md)

- Date: 2026-08-27
- Location: `/home/wwwroot/social/infrastructure`
- Command: `cargo test --workspace` (default features), plus feature-gated backend verification (tsdb/graph/search/kv)
- Result: **180 passed / 0 failed** (179 unit+integration tests + 1 doctest)

## Summary

| crate | Test cases | Passed | Failed | Modules covered |
|-------|-------|------|------|----------|
| bee_cache | 8 | 8 | 0 | set/get/delete/expire, incr, ttl, MemoryCache |
| bee_cli | 23 | 23 | 0 | rust_type mapping, find_bin_name, validate_name, bin argument parsing |
| bee_config | 14 | 14 | 0 | IniParser (comments/whitespace/section switching), Config, ConfigSource, 6 integration |
| bee_config_macro | 0 | — | — | covered indirectly through integration tests |
| bee_graph | 15 | 15 | 0 | StubGraphDB: traversal direction/depth/labels, add/update/delete, error paths, serde (feature backend another 29) |
| bee_kv | 10 | 10 | 0 | get/incr/expire/mset, expired keys, error paths |
| bee_logs | 4 | 4 | 0 | level_str all levels, file output, stdout/stderr output |
| bee_orm | 19 | 19 | 0 | SelectBuilder (integration): order/limit/offset/parameter binding/reuse/table_name/error display (0 in lib) |
| bee_orm_macro | 0 | — | — | covered indirectly through integration tests |
| bee_router | 36 | 36 | 0 | context (params/text/html/abort), router (method/404/namespace), dispatch pipeline, session restore/persist/expired cookie |
| bee_rust | 2 | 2 | 0 | prelude exports, Result alias |
| bee_search | 18 | 18 | 0 | MemoryEngine: index/delete/overwrite/pagination/get/empty query/serde (feature backend another 20) |
| bee_session | 8 | 8 | 0 | set/get/delete, save/load/refresh, TTL floor, error paths, UUID uniqueness |
| bee_template | 6+1 | 7 | 0 | context! macro, render, missing template/variable errors, empty engine, non-finite floats (incl. 1 doctest) |
| bee_tsdb | 10 | 10 | 0 | Query filtering (Neq/Regex/range/AND), Point serde, enum debug (feature backend another 22) |
| social_grpc | 5 | 5 | 0 | SearchService: index/search/delete round trips, invalid JSON fallback, empty index |
| hello_bee | 0 | — | — | example program, no tests |

## Coverage gaps

- **bee_kv `redis` feature (RedisStore)**: requires a live Redis server, not covered
- **hello_bee**: example program, 0 tests
- **feature-gated backends** (not compiled with default features): verified to compile and pass tests under their respective feature combinations (tsdb 22, graph 29, search 20, kv 10), but real backends such as es/opensearch/clickhouse, neo4j/nebulagraph/arangodb, influxdb/iotdb/questdb, redis require external services — compile-level verification only
- **bee_config_macro / bee_orm_macro**: proc macros, covered indirectly through their integration tests, no standalone unit tests

## Real bugs documented (library source not modified)

1. `bee_tsdb/src/influxdb.rs:160-169` — `line_protocol` iterates `&point.fields` (HashMap) without sorting, while tags are sorted → line protocol output is non-deterministic with multiple fields
2. `bee_tsdb/src/iotdb.rs:37-42` — `write_batch` is non-atomic (executes point by point with `?` short-circuit), inconsistent with the "atomically" claimed in the trait docs
3. `bee_graph/src/neo4j.rs:106-109` — `add_edge` always returns `VertexNotFound(edge.from)`, even when the missing endpoint is `to`
4. `bee_search` MemoryEngine `search` — ignores the from/size passed in from the gRPC layer (no pagination)
5. `social_grpc/src/search.rs:54` — `h.id.parse().unwrap_or(0)`: non-numeric ids silently become 0
6. `bee_template/src/lib.rs` `context!` macro docs — claim NaN panics, but serde_json ≥1.0.128 actually serializes it as null (docs outdated)
7. `bee_search/src/elasticsearch.rs:64` — bulk NDJSON interpolates index/id raw into JSON; ids containing `"` produce malformed NDJSON

## Environment notes

- cargo is located at `~/.cargo/bin` (not in PATH), requires `export PATH="$HOME/.cargo/bin:$PATH"`
- social_grpc requires `protoc`: obtained via `apt-get download protobuf-compiler` + `dpkg-deb -x` extracted to `/tmp/protoc-local`, `PROTOC=/tmp/protoc-local/usr/bin/protoc` (no sudo needed)
