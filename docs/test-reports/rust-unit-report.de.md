# Rust-Workspace-Unit-Testbericht
**语言 / Languages:** [中文](rust-unit-report.md) · [English](rust-unit-report.en.md) · [한국어](rust-unit-report.ko.md) · [Русский](rust-unit-report.ru.md) · [Deutsch](rust-unit-report.de.md) · [Français](rust-unit-report.fr.md) · [Español](rust-unit-report.es.md) · [Português](rust-unit-report.pt.md) · [हिन्दी](rust-unit-report.hi.md) · [العربية](rust-unit-report.ar.md) · [বাংলা](rust-unit-report.bn.md) · [Bahasa Indonesia](rust-unit-report.id.md) · [日本語](rust-unit-report.ja.md)

- Datum: 2026-08-27
- Ort: `/home/wwwroot/social/infrastructure`
- Befehl: `cargo test --workspace` (Standard-Features), plus Verifikation der feature-gated Backends (tsdb/graph/search/kv)
- Ergebnis: **180 bestanden / 0 fehlgeschlagen** (179 Unit-+Integrationstests + 1 Doctest)

## Zusammenfassung

| crate | Testfälle | Bestanden | Fehlgeschlagen | Abgedeckte Module |
|-------|-------|------|------|----------|
| bee_cache | 8 | 8 | 0 | set/get/delete/expire, incr, ttl, MemoryCache |
| bee_cli | 23 | 23 | 0 | rust_type-Mapping, find_bin_name, validate_name, bin-Argumentparsing |
| bee_config | 14 | 14 | 0 | IniParser (Kommentare/Leerzeichen/Sektionswechsel), Config, ConfigSource, 6 Integration |
| bee_config_macro | 0 | — | — | indirekt über Integrationstests abgedeckt |
| bee_graph | 15 | 15 | 0 | StubGraphDB: Traversierungsrichtung/-tiefe/-labels, add/update/delete, Fehlerpfade, serde (Feature-Backend weitere 29) |
| bee_kv | 10 | 10 | 0 | get/incr/expire/mset, abgelaufene Schlüssel, Fehlerpfade |
| bee_logs | 4 | 4 | 0 | level_str alle Stufen, Dateiausgabe, stdout/stderr-Ausgabe |
| bee_orm | 19 | 19 | 0 | SelectBuilder (Integration): order/limit/offset/Parameterbindung/Wiederverwendung/table_name/Fehlerdisplay (0 in lib) |
| bee_orm_macro | 0 | — | — | indirekt über Integrationstests abgedeckt |
| bee_router | 36 | 36 | 0 | context (params/text/html/abort), router (method/404/namespace), Dispatch-Pipeline, Session-Wiederherstellung/Persistenz/abgelaufene Cookies |
| bee_rust | 2 | 2 | 0 | prelude-Exporte, Result-Alias |
| bee_search | 18 | 18 | 0 | MemoryEngine: index/delete/Überschreiben/Paginierung/get/leere Abfrage/serde (Feature-Backend weitere 20) |
| bee_session | 8 | 8 | 0 | set/get/delete, save/load/refresh, TTL-Floor, Fehlerpfade, UUID-Eindeutigkeit |
| bee_template | 6+1 | 7 | 0 | context!-Makro, render, Fehler bei fehlendem Template/Variable, leerer Engine, nicht-endliche Floats (inkl. 1 Doctest) |
| bee_tsdb | 10 | 10 | 0 | Query-Filterung (Neq/Regex/Bereich/AND), Point-serde, enum debug (Feature-Backend weitere 22) |
| social_grpc | 5 | 5 | 0 | SearchService: index/search/delete Roundtrips, Invalid-JSON-Fallback, leerer Index |
| hello_bee | 0 | — | — | Beispielprogramm, keine Tests |

## Abdeckungslücken

- **bee_kv `redis`-Feature (RedisStore)**: benötigt einen laufenden Redis-Server, nicht abgedeckt
- **hello_bee**: Beispielprogramm, 0 Tests
- **feature-gated Backends** (mit Standard-Features nicht kompiliert): unter ihren jeweiligen Feature-Kombinationen kompilier- und testbar verifiziert (tsdb 22, graph 29, search 20, kv 10), aber echte Backends wie es/opensearch/clickhouse, neo4j/nebulagraph/arangodb, influxdb/iotdb/questdb, redis benötigen externe Dienste — nur kompilierungsseitige Verifikation
- **bee_config_macro / bee_orm_macro**: proc-macros, indirekt über ihre Integrationstests abgedeckt, keine eigenständigen Unit-Tests

## Dokumentierte echte Bugs (Bibliotheksquellcode unverändert)

1. `bee_tsdb/src/influxdb.rs:160-169` — `line_protocol` iteriert `&point.fields` (HashMap) ohne Sortierung, während tags sortiert sind → bei mehreren Feldern ist die Line-Protocol-Ausgabe nicht deterministisch
2. `bee_tsdb/src/iotdb.rs:37-42` — `write_batch` ist nicht atomar (punktweise Ausführung mit `?`-Kurzschluss), widerspricht dem im Trait-Doc behaupteten "atomically"
3. `bee_graph/src/neo4j.rs:106-109` — `add_edge` gibt immer `VertexNotFound(edge.from)` zurück, auch wenn der fehlende Endpunkt `to` ist
4. `bee_search` MemoryEngine `search` — ignoriert das aus der gRPC-Schicht übergebene from/size (keine Paginierung)
5. `social_grpc/src/search.rs:54` — `h.id.parse().unwrap_or(0)`: nicht-numerische ids werden still zu 0
6. `context!`-Makro-Doku in `bee_template/src/lib.rs` — behauptet, NaN würde panicken, tatsächlich serialisiert serde_json ≥1.0.128 es als null (Doku veraltet)
7. `bee_search/src/elasticsearch.rs:64` — bulk NDJSON interpoliert index/id roh in JSON; ids mit `"` erzeugen fehlerhaftes NDJSON

## Umgebungsnotizen

- cargo liegt unter `~/.cargo/bin` (nicht im PATH), benötigt `export PATH="$HOME/.cargo/bin:$PATH"`
- social_grpc benötigt `protoc`: per `apt-get download protobuf-compiler` + `dpkg-deb -x` nach `/tmp/protoc-local` entpackt, `PROTOC=/tmp/protoc-local/usr/bin/protoc` (kein sudo nötig)
