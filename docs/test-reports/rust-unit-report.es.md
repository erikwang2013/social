# Informe de pruebas unitarias de Rust Workspace
**语言 / Languages:** [中文](rust-unit-report.md) · [English](rust-unit-report.en.md) · [한국어](rust-unit-report.ko.md) · [Русский](rust-unit-report.ru.md) · [Deutsch](rust-unit-report.de.md) · [Français](rust-unit-report.fr.md) · [Español](rust-unit-report.es.md) · [Português](rust-unit-report.pt.md) · [हिन्दी](rust-unit-report.hi.md) · [العربية](rust-unit-report.ar.md) · [বাংলা](rust-unit-report.bn.md) · [Bahasa Indonesia](rust-unit-report.id.md) · [日本語](rust-unit-report.ja.md)

- Fecha: 2026-08-27
- Ubicación: `/home/wwwroot/social/infrastructure`
- Comando: `cargo test --workspace` (features por defecto), más verificación de backends con feature-gate (tsdb/graph/search/kv)
- Resultado: **180 aprobados / 0 fallidos** (179 pruebas unitarias+de integración + 1 doctest)

## Resumen

| crate | Casos de prueba | Aprobados | Fallidos | Módulos cubiertos |
|-------|-------|------|------|----------|
| bee_cache | 8 | 8 | 0 | set/get/delete/expire, incr, ttl, MemoryCache |
| bee_cli | 23 | 23 | 0 | mapeo rust_type, find_bin_name, validate_name, análisis de argumentos bin |
| bee_config | 14 | 14 | 0 | IniParser (comentarios/espacios/cambio de sección), Config, ConfigSource, 6 integración |
| bee_config_macro | 0 | — | — | cubierto indirectamente mediante pruebas de integración |
| bee_graph | 15 | 15 | 0 | StubGraphDB: dirección/profundidad/etiquetas de recorrido, add/update/delete, rutas de error, serde (backend feature +29) |
| bee_kv | 10 | 10 | 0 | get/incr/expire/mset, claves expiradas, rutas de error |
| bee_logs | 4 | 4 | 0 | level_str todos los niveles, salida a archivo, salida stdout/stderr |
| bee_orm | 19 | 19 | 0 | SelectBuilder (integración): order/limit/offset/vincular parámetros/reutilización/table_name/mostrar errores (0 en lib) |
| bee_orm_macro | 0 | — | — | cubierto indirectamente mediante pruebas de integración |
| bee_router | 36 | 36 | 0 | context (params/text/html/abort), router (method/404/namespace), pipeline de dispatch, restauración/persistencia/cookie expirada de sesión |
| bee_rust | 2 | 2 | 0 | exportaciones de prelude, alias de Result |
| bee_search | 18 | 18 | 0 | MemoryEngine: index/delete/sobrescritura/paginación/get/consulta vacía/serde (backend feature +20) |
| bee_session | 8 | 8 | 0 | set/get/delete, save/load/refresh, TTL floor, rutas de error, unicidad de UUID |
| bee_template | 6+1 | 7 | 0 | macro context!, render, errores de plantilla/variable faltante, engine vacío, flotantes no finitos (incl. 1 doctest) |
| bee_tsdb | 10 | 10 | 0 | filtrado de Query (Neq/Regex/rango/AND), Point serde, enum debug (backend feature +22) |
| social_grpc | 5 | 5 | 0 | SearchService: ida y vuelta index/search/delete, respaldo por JSON inválido, índice vacío |
| hello_bee | 0 | — | — | programa de ejemplo, sin pruebas |

## Lista de no cubierto

- **Feature `redis` de bee_kv (RedisStore)**: requiere un servidor Redis activo, no cubierto
- **hello_bee**: programa de ejemplo, 0 pruebas
- **Backends con feature-gate** (no compilados con features por defecto): verificados como compilables y con pruebas aprobadas bajo sus respectivas combinaciones de features (tsdb 22, graph 29, search 20, kv 10), pero los backends reales es/opensearch/clickhouse, neo4j/nebulagraph/arangodb, influxdb/iotdb/questdb, redis requieren servicios externos — solo verificación a nivel de compilación
- **bee_config_macro / bee_orm_macro**: proc-macros, cubiertos indirectamente mediante sus pruebas de integración, sin pruebas unitarias independientes

## Bugs reales documentados (código fuente de las bibliotecas no modificado)

1. `bee_tsdb/src/influxdb.rs:160-169` — `line_protocol` itera `&point.fields` (HashMap) sin ordenar, mientras que tags sí están ordenadas → la salida de line protocol es no determinista con múltiples campos
2. `bee_tsdb/src/iotdb.rs:37-42` — `write_batch` no es atómico (ejecución punto a punto con cortocircuito `?`), inconsistente con el «atomically» declarado en la doc del trait
3. `bee_graph/src/neo4j.rs:106-109` — `add_edge` siempre devuelve `VertexNotFound(edge.from)`, incluso cuando el extremo faltante es `to`
4. `bee_search` MemoryEngine `search` — ignora el from/size recibido desde la capa gRPC (sin paginación)
5. `social_grpc/src/search.rs:54` — `h.id.parse().unwrap_or(0)`: un id no numérico se convierte silenciosamente en 0
6. Documentación de la macro `context!` en `bee_template/src/lib.rs` — afirma que NaN genera panic, pero en realidad serde_json ≥1.0.128 lo serializa como null (doc desactualizada)
7. `bee_search/src/elasticsearch.rs:64` — el NDJSON bulk interpola index/id sin procesar en el JSON; ids que contienen `"` producen NDJSON inválido

## Notas de entorno

- cargo está en `~/.cargo/bin` (no está en PATH), requiere `export PATH="$HOME/.cargo/bin:$PATH"`
- social_grpc requiere `protoc`: obtenido vía `apt-get download protobuf-compiler` + `dpkg-deb -x` extraído a `/tmp/protoc-local`, `PROTOC=/tmp/protoc-local/usr/bin/protoc` (sin necesidad de sudo)
