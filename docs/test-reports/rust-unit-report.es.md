# Informe de pruebas unitarias de Rust Workspace
**语言 / Languages:** [中文](rust-unit-report.md) · [English](rust-unit-report.en.md) · [한국어](rust-unit-report.ko.md) · [Русский](rust-unit-report.ru.md) · [Deutsch](rust-unit-report.de.md) · [Français](rust-unit-report.fr.md) · [Español](rust-unit-report.es.md) · [Português](rust-unit-report.pt.md) · [हिन्दी](rust-unit-report.hi.md) · [العربية](rust-unit-report.ar.md) · [বাংলা](rust-unit-report.bn.md) · [Bahasa Indonesia](rust-unit-report.id.md) · [日本語](rust-unit-report.ja.md)

- Fecha: 2026-08-27
- Ubicación: `/home/wwwroot/social/infrastructure`
- Comando: `cargo test --workspace` (feature por defecto), más verificación separada de los backends de feature (tsdb/graph/search/kv)
- Resultado: **183 aprobados / 0 fallidos** (178 unitarias+de integración + 5 inline de feature + 1 doctest, etc.; el workspace por defecto incluye los 6 casos de bee_search porque social_grpc depende de su feature `elasticsearch`)

## Resumen

| crate | Casos de prueba | Aprobados | Fallidos | Módulos cubiertos |
|-------|-------|------|------|----------|
| bee_cache | 8 | 8 | 0 | set/get/delete/expire, incr, ttl, MemoryCache |
| bee_cli | 14 | 14 | 0 | mapeo rust_type, find_bin_name, validate_name, análisis de argumentos bin |
| bee_config | 8 + 6 integración | 14 | 0 | IniParser (comentarios/espacios/cambio de sección), Config, ConfigSource, errores de recarga |
| bee_config_macro | 0 | — | — | cubierto indirectamente mediante pruebas de integración |
| bee_graph | 15 | 15 | 0 | StubGraphDB: dirección/profundidad/etiquetas de recorrido, add/update/delete, rutas de error, serde (feature neo4j +5) |
| bee_kv | 10 | 10 | 0 | get/incr/expire/mset, claves expiradas, rutas de error (feature redis +3 casos Redis reales) |
| bee_logs | 4 | 4 | 0 | level_str todos los niveles, salida a archivo, salida stdout/stderr |
| bee_orm | 19 integración | 19 | 0 | SelectBuilder: order/limit/offset/vincular parámetros/reutilización/table_name/Display de errores (0 en lib) |
| bee_orm_macro | 0 | — | — | cubierto indirectamente mediante pruebas de integración |
| bee_router | 36 | 36 | 0 | context (params/text/html/abort), router (method/404/namespace), pipeline de dispatch, restauración/persistencia/cookie expirada de sesión |
| bee_rust | 2 (bin +9) | 11 | 0 | exportaciones de prelude, alias de Result, análisis de argumentos CLI |
| bee_search | 20 (incl. 6 inline de feature) | 20 | 0 | MemoryEngine: index/delete/sobrescritura/paginación/get/consulta vacía/serde; driver Elasticsearch: get/search/bulk/aggregate, escape NDJSON |
| bee_session | 8 | 8 | 0 | set/get/delete, save/load/refresh, TTL floor, rutas de error, unicidad de UUID |
| bee_template | 6 + 1 doctest | 7 | 0 | macro context!, render, errores de plantilla/variable faltante, engine vacío, flotantes no finitos (1 doctest) |
| bee_tsdb | 11 | 11 | 0 | escrituras/escrituras por lotes, límites de consultas de rango, filtrado Eq/Neq/Regex/AND, Point serde, CQ (feature influxdb +5, incl. determinismo del line protocol) |
| social_grpc | 6 | 6 | 0 | SearchService: ida y vuelta index/search/delete, respaldo por JSON inválido, índice vacío, error de id no numérico |
| hello_bee | 0 | — | — | programa de ejemplo, sin pruebas |

## Defectos reales corregidos en esta ronda (fix mínimo + pruebas de regresión)

1. **bee_search MemoryEngine `search` ignora la paginación** (`crates/bee_search/src/lib.rs`) — el `from`/`size` enviado desde la capa gRPC se descartaba, devolviendo siempre todos los resultados. Fix: lee `from`/`size` del JSON de consulta y aplica skip/truncate sobre los hits, `total` sigue contando todas las coincidencias. Nuevo: `test_search_honors_from_size_pagination` (robusto frente a la iteración desordenada de HashMap: compara con una porción del resultado completo de la propia engine).
2. **social_grpc `search` convierte silenciosamente ids no numéricos en 0** (`crates/social_grpc/src/search.rs:53-60`) — `h.id.parse().unwrap_or(0)` devolvía silenciosamente los ids de documento no numéricos como 0. Fix: el fallo del parse devuelve `Status::invalid_argument`. Nuevo: `non_numeric_hit_id_becomes_invalid_argument`.
3. **bee_tsdb campos line protocol de InfluxDB desordenados** (`crates/bee_tsdb/src/influxdb.rs:160-170`) — tags están ordenadas pero fields no; salida no determinista con múltiples campos. Fix: ordenar fields por clave. Nuevo: `line_protocol_is_deterministic_across_field_insertion_order` (distintos órdenes de inserción producen líneas idénticas, ordenadas por a,b).
4. **bee_search Elasticsearch bulk NDJSON sin escapar ids** (`crates/bee_search/src/elasticsearch.rs`) — index/id interpolados en claro; ids con `"` producían NDJSON inválido. Fix: se extrajo `bulk_ndjson()`, las líneas de acción se serializan vía serde_json. Nuevo: `bulk_ndjson_escapes_ids_and_stays_parseable`.
5. **bee_graph Neo4j `add_edge` siempre reporta el extremo `from`** (`crates/bee_graph/src/neo4j.rs:107-116`) — cuando el extremo faltante es `to`, el mensaje de error induce a error. Fix: cuando `nodes-matched < 2`, usar `get_vertex` para determinar el extremo realmente faltante antes de reportar. Nuevo: `add_edge_reports_the_missing_endpoint` (servicio HTTP simulado verifica que reporta `to1` en vez de `from1`).
6. **bee_template doc `context!` inconsistente con el comportamiento** (`crates/bee_template/src/lib.rs`) — la doc afirma que los flotantes no finitos generan panic, pero serde_json los serializa como `null` (probado por un test existente). Fix: doc actualizada.

## Nueva cobertura

- **Pruebas de integración bee_kv RedisStore con Redis real** (`crates/bee_kv/src/redis_store.rs`, feature `redis`) — cubre la brecha nombrada explícitamente en el informe anterior. Redis local disponible (127.0.0.1:6379); 3 casos: ida y vuelta set/get/del, incr/expire, mset/mget; claves con prefijo pid+nanosegundos, los casos se limpian solos. Si Redis no está disponible, los casos se omiten elegantemente (imprimen SKIP y pasan).

## Brechas de cobertura (sin cambios)

- **bee_tsdb IoTDB `write_batch` no atómico** (`crates/bee_tsdb/src/iotdb.rs`) — escrituras punto a punto con cortocircuito `?`, inconsistente con el «atomically» de la doc del trait. El fix requiere soporte transaccional del backend; no hay instancia IoTDB local, por lo que esta ronda no se hacen cambios a ciegas; listado como limitación conocida.
- **Backends externos** (es/opensearch/clickhouse, neo4j/nebulagraph/arangodb, influxdb/iotdb/questdb, memcached) — los drivers principales (elasticsearch, neo4j, influxdb: rutas de escritura/consulta/CQ) están cubiertos con servicios HTTP simulados locales; el resto sin servicio local solo tiene verificación a nivel de compilación.
- **MySQL**: local 127.0.0.1:3306 disponible (root, sin contraseña), pero ningún crate del workspace introduce un driver MySQL — bee_orm es un constructor SQL independiente del driver, los casos QuerySet no dependen de una base real; no se necesita ni debe añadirse dependencia de driver.
- **bee_config_macro / bee_orm_macro**: proc-macros, cubiertos indirectamente mediante sus pruebas de integración, sin pruebas unitarias independientes.

## Control de calidad

- `cargo fmt --check`: aprobado (esta ronda se ejecutó `cargo fmt` en todo el workspace, corrigiendo 20+ desviaciones de formato dejadas por sesiones anteriores).
- `cargo clippy --workspace --all-targets`: cero advertencias en el código nuevo; las 3 restantes son advertencias preexistentes (bee_config `get("default").is_none()`, bee_rust `unwrap()` sobre Ok, bee_search MemoryEngine sin impl Default), fuera del alcance de esta ronda.

## Notas de entorno

- cargo está en `~/.cargo/bin` (no en PATH por defecto), requiere `export PATH="$HOME/.cargo/bin:$PATH"`.
- `protoc` ya está disponible (`/home/erik/.local/bin/protoc`).
- social_grpc corre en segundo plano (puerto 50051); este informe solo ejecutó `cargo test`, no `cargo run` sobre él.
- Redis (6379) y MySQL (3306) disponibles localmente; lista de casos de feature:
  - `cargo test -p bee_tsdb --features influxdb` → 16 aprobados
  - `cargo test -p bee_search --features elasticsearch` → 20 aprobados
  - `cargo test -p bee_graph --features neo4j` → 20 aprobados
  - `cargo test -p bee_kv --features redis` → 13 aprobados (incl. 3 casos Redis reales)
