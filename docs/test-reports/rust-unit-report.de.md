# Rust-Workspace-Unit-Testbericht
**语言 / Languages:** [中文](rust-unit-report.md) · [English](rust-unit-report.en.md) · [한국어](rust-unit-report.ko.md) · [Русский](rust-unit-report.ru.md) · [Deutsch](rust-unit-report.de.md) · [Français](rust-unit-report.fr.md) · [Español](rust-unit-report.es.md) · [Português](rust-unit-report.pt.md) · [हिन्दी](rust-unit-report.hi.md) · [العربية](rust-unit-report.ar.md) · [বাংলা](rust-unit-report.bn.md) · [Bahasa Indonesia](rust-unit-report.id.md) · [日本語](rust-unit-report.ja.md)

- Datum: 2026-08-27
- Ort: `/home/wwwroot/social/infrastructure`
- Befehl: `cargo test --workspace` (Standard-Feature), plus separate Verifikation der Feature-Backends (tsdb/graph/search/kv)
- Ergebnis: **183 bestanden / 0 fehlgeschlagen** (178 Unit+Integration + 5 Feature-inline + 1 Doctest usw.; der Standard-Workspace enthält die 6 Fälle von bee_search, weil social_grpc von dessen `elasticsearch`-Feature abhängt)

## Zusammenfassung

| crate | Testfälle | Bestanden | Fehlgeschlagen | Abgedeckte Module |
|-------|-------|------|------|----------|
| bee_cache | 8 | 8 | 0 | set/get/delete/expire, incr, ttl, MemoryCache |
| bee_cli | 14 | 14 | 0 | rust_type-Mapping, find_bin_name, validate_name, bin-Argumentparsing |
| bee_config | 8 + 6 Integration | 14 | 0 | IniParser (Kommentare/Leerzeichen/Sektionswechsel), Config, ConfigSource, Reload-Fehler |
| bee_config_macro | 0 | — | — | indirekt über Integrationstests abgedeckt |
| bee_graph | 15 | 15 | 0 | StubGraphDB: Traversierungsrichtung/-tiefe/-labels, add/update/delete, Fehlerpfade, serde (Feature neo4j weitere 5) |
| bee_kv | 10 | 10 | 0 | get/incr/expire/mset, abgelaufene Schlüssel, Fehlerpfade (Feature redis weitere 3 echte Redis-Fälle) |
| bee_logs | 4 | 4 | 0 | level_str alle Stufen, Dateiausgabe, stdout/stderr-Ausgabe |
| bee_orm | 19 Integration | 19 | 0 | SelectBuilder: order/limit/offset/Parameterbindung/Wiederverwendung/table_name/Fehler-Display (0 in lib) |
| bee_orm_macro | 0 | — | — | indirekt über Integrationstests abgedeckt |
| bee_router | 36 | 36 | 0 | context (params/text/html/abort), router (method/404/namespace), Dispatch-Pipeline, Session-Wiederherstellung/Persistenz/abgelaufene Cookies |
| bee_rust | 2 (bin weitere 9) | 11 | 0 | prelude-Exporte, Result-Alias, CLI-Argumentparsing |
| bee_search | 20 (inkl. 6 Feature-inline) | 20 | 0 | MemoryEngine: index/delete/Überschreiben/Paginierung/get/leere Abfrage/serde; Elasticsearch-Treiber: get/search/bulk/aggregate, NDJSON-Escaping |
| bee_session | 8 | 8 | 0 | set/get/delete, save/load/refresh, TTL-Floor, Fehlerpfade, UUID-Eindeutigkeit |
| bee_template | 6 + 1 Doctest | 7 | 0 | context!-Makro, render, Fehler bei fehlendem Template/Variable, leerer Engine, nicht-endliche Floats (1 Doctest) |
| bee_tsdb | 11 | 11 | 0 | Schreib-/Batch-Schreibvorgänge, Bereichsabfrage-Grenzen, Eq/Neq/Regex/AND-Filterung, Point-serde, CQ (Feature influxdb weitere 5, inkl. Line-Protocol-Determinismus) |
| social_grpc | 6 | 6 | 0 | SearchService: index/search/delete Roundtrips, Invalid-JSON-Fallback, leerer Index, Fehler bei nicht-numerischer id |
| hello_bee | 0 | — | — | Beispielprogramm, keine Tests |

## In dieser Runde behobene echte Defekte (Minimal-Fix + Regressionstests)

1. **bee_search MemoryEngine `search` ignoriert Paginierung** (`crates/bee_search/src/lib.rs`) — das aus der gRPC-Schicht übergebene `from`/`size` wurde verworfen, es wurden immer alle Treffer zurückgegeben. Fix: liest `from`/`size` aus dem Query-JSON und wendet skip/truncate auf hits an, `total` bleibt die Gesamtzahl der Treffer. Neu: `test_search_honors_from_size_pagination` (robust gegen ungeordnete HashMap-Iteration: Vergleich mit einem Slice des eigenen Gesamtergebnisses der Engine).
2. **social_grpc `search` macht nicht-numerische ids still zu 0** (`crates/social_grpc/src/search.rs:53-60`) — `h.id.parse().unwrap_or(0)` gab nicht-numerische Dokument-ids still als 0 zurück. Fix: parse-Fehler liefert `Status::invalid_argument`. Neu: `non_numeric_hit_id_becomes_invalid_argument`.
3. **bee_tsdb InfluxDB Line-Protocol-Felder in falscher Reihenfolge** (`crates/bee_tsdb/src/influxdb.rs:160-170`) — tags sind sortiert, fields aber nicht; bei mehreren Feldern ist die Ausgabe nicht deterministisch. Fix: fields nach Schlüssel sortieren. Neu: `line_protocol_is_deterministic_across_field_insertion_order` (unterschiedliche Einfüge-Reihenfolgen erzeugen identische Zeilen, sortiert nach a,b).
4. **bee_search Elasticsearch bulk NDJSON hat ids nicht escaped** (`crates/bee_search/src/elasticsearch.rs`) — index/id wurden roh in Strings interpoliert; ids mit `"` erzeugten fehlerhaftes NDJSON. Fix: `bulk_ndjson()` extrahiert, Action-Zeilen über serde_json serialisiert. Neu: `bulk_ndjson_escapes_ids_and_stays_parseable`.
5. **bee_graph Neo4j `add_edge`-Fehlerendpunkt ist immer `from`** (`crates/bee_graph/src/neo4j.rs:107-116`) — wenn der fehlende Endpunkt `to` ist, ist die Fehlermeldung irreführend. Fix: bei `nodes-matched < 2` mit `get_vertex` den tatsächlich fehlenden Endpunkt bestimmen, dann melden. Neu: `add_edge_reports_the_missing_endpoint` (Mock-HTTP-Dienst verifiziert, dass `to1` statt `from1` gemeldet wird).
6. **bee_template `context!`-Doku weicht vom Verhalten ab** (`crates/bee_template/src/lib.rs`) — die Doku behauptet, nicht-endliche Floats würden panicken, tatsächlich serialisiert serde_json sie als `null` (durch bestehenden Test belegt). Fix: Doku aktualisiert.

## Neue Abdeckung

- **bee_kv RedisStore-Integrationstests mit echtem Redis** (`crates/bee_kv/src/redis_store.rs`, Feature `redis`) — schließt die im vorherigen Bericht ausdrücklich genannte Abdeckungslücke. Lokaler Redis verfügbar (127.0.0.1:6379); 3 Fälle: set/get/del-Roundtrip, incr/expire, mset/mget; Schlüssel mit pid+Nanosekunden-Präfix, die Fälle räumen selbst auf. Ist Redis nicht verfügbar, werden die Fälle elegant übersprungen (SKIP ausgegeben und durchgelassen).

## Abdeckungslücken (unverändert)

- **bee_tsdb IoTDB `write_batch` nicht atomar** (`crates/bee_tsdb/src/iotdb.rs`) — punktweise `?`-Kurzschluss-Schreibvorgänge, widerspricht dem „atomically" im Trait-Doc. Der Fix benötigt Transaktionsunterstützung des Backends; keine lokale IoTDB-Instanz vorhanden, daher diese Runde keine Blindänderung; als bekannte Einschränkung gelistet.
- **Externe Backends** (es/opensearch/clickhouse, neo4j/nebulagraph/arangodb, influxdb/iotdb/questdb, memcached) — die Haupttreiber (elasticsearch, neo4j, influxdb Schreib-/Abfrage-/CQ-Pfade) sind über lokale Mock-HTTP-Dienste abgedeckt; der Rest ohne lokalen Dienst nur kompilierungsseitig verifiziert.
- **MySQL**: lokal 127.0.0.1:3306 verfügbar (root, leeres Passwort), aber kein crate im Workspace bringt einen MySQL-Treiber mit — bee_orm ist ein treiberunabhängiger SQL-Builder, QuerySet-Fälle hängen nicht von einer echten Datenbank ab; keine Treiberabhängigkeit nötig und sollte auch nicht ergänzt werden.
- **bee_config_macro / bee_orm_macro**: proc-macros, indirekt über ihre Integrationstests abgedeckt, keine eigenständigen Unit-Tests.

## Qualitätsprüfung

- `cargo fmt --check`: bestanden (diese Runde wurde `cargo fmt` über den gesamten Workspace ausgeführt, 20+ Formatabweichungen aus früheren Sitzungen behoben).
- `cargo clippy --workspace --all-targets`: neuer Code null Warnungen; die verbliebenen 3 sind vorbestehende Warnungen (bee_config `get("default").is_none()`, bee_rust `unwrap()` auf Ok, bee_search MemoryEngine ohne Default-impl), außerhalb des Umfangs dieser Runde.

## Umgebungsnotizen

- cargo liegt unter `~/.cargo/bin` (standardmäßig nicht im PATH), benötigt `export PATH="$HOME/.cargo/bin:$PATH"`.
- `protoc` ist jetzt verfügbar (`/home/erik/.local/bin/protoc`).
- social_grpc läuft im Hintergrund (Port 50051); dieser Bericht hat nur `cargo test` ausgeführt, kein `cargo run` darauf.
- Redis (6379) und MySQL (3306) lokal verfügbar; Liste der Feature-Fälle:
  - `cargo test -p bee_tsdb --features influxdb` → 16 bestanden
  - `cargo test -p bee_search --features elasticsearch` → 20 bestanden
  - `cargo test -p bee_graph --features neo4j` → 20 bestanden
  - `cargo test -p bee_kv --features redis` → 13 bestanden (inkl. 3 echter Redis-Fälle)
