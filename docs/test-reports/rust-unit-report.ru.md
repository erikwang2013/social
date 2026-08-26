# Отчёт о модульных тестах Rust Workspace
**语言 / Languages:** [中文](rust-unit-report.md) · [English](rust-unit-report.en.md) · [한국어](rust-unit-report.ko.md) · [Русский](rust-unit-report.ru.md) · [Deutsch](rust-unit-report.de.md) · [Français](rust-unit-report.fr.md) · [Español](rust-unit-report.es.md) · [Português](rust-unit-report.pt.md) · [हिन्दी](rust-unit-report.hi.md) · [العربية](rust-unit-report.ar.md) · [বাংলা](rust-unit-report.bn.md) · [Bahasa Indonesia](rust-unit-report.id.md) · [日本語](rust-unit-report.ja.md)

- Дата: 2026-08-27
- Расположение: `/home/wwwroot/social/infrastructure`
- Команда: `cargo test --workspace` (features по умолчанию), плюс проверка бэкендов под feature-флагами (tsdb/graph/search/kv)
- Результат: **180 пройдено / 0 не пройдено** (179 юнит+интеграционных тестов + 1 doctest)

## Сводка

| crate | Тестов | Пройдено | Не пройдено | Покрываемые модули |
|-------|-------|------|------|----------|
| bee_cache | 8 | 8 | 0 | set/get/delete/expire, incr, ttl, MemoryCache |
| bee_cli | 23 | 23 | 0 | маппинг rust_type, find_bin_name, validate_name, разбор аргументов bin |
| bee_config | 14 | 14 | 0 | IniParser (комментарии/пробелы/переключение секций), Config, ConfigSource, 6 integration |
| bee_config_macro | 0 | — | — | покрыт косвенно через integration tests |
| bee_graph | 15 | 15 | 0 | StubGraphDB: направление/глубина/метки обхода, add/update/delete, пути ошибок, serde (feature-бэкенд ещё 29) |
| bee_kv | 10 | 10 | 0 | get/incr/expire/mset, просроченные ключи, пути ошибок |
| bee_logs | 4 | 4 | 0 | level_str все уровни, file output, stdout/stderr output |
| bee_orm | 19 | 19 | 0 | SelectBuilder (integration): order/limit/offset/связывание параметров/переиспользование/table_name/display ошибок (в lib 0) |
| bee_orm_macro | 0 | — | — | покрыт косвенно через integration tests |
| bee_router | 36 | 36 | 0 | context (params/text/html/abort), router (method/404/namespace), конвейер dispatch, восстановление/персистентность/просроченные cookie сессий |
| bee_rust | 2 | 2 | 0 | экспорт prelude, алиас Result |
| bee_search | 18 | 18 | 0 | MemoryEngine: index/delete/перезапись/пагинация/get/пустой запрос/serde (feature-бэкенд ещё 20) |
| bee_session | 8 | 8 | 0 | set/get/delete, save/load/refresh, TTL floor, пути ошибок, уникальность UUID |
| bee_template | 6+1 | 7 | 0 | макрос context!, render, ошибки отсутствия шаблона/переменной, пустой engine, неконечные числа с плавающей точкой (вкл. 1 doctest) |
| bee_tsdb | 10 | 10 | 0 | фильтрация Query (Neq/Regex/диапазон/AND), Point serde, enum debug (feature-бэкенд ещё 22) |
| social_grpc | 5 | 5 | 0 | SearchService: round-trip index/search/delete, откат на невалидный JSON, пустой индекс |
| hello_bee | 0 | — | — | пример программы, без тестов |

## Список не покрытого

- **feature `redis` у bee_kv (RedisStore)**: требуется живой Redis-сервер, не покрыто
- **hello_bee**: пример программы, 0 тестов
- **Бэкенды под feature-флагами** (не компилируются с features по умолчанию): проверено, что компилируются и проходят тесты со своими комбинациями feature (tsdb 22, graph 29, search 20, kv 10), но реальные бэкенды es/opensearch/clickhouse, neo4j/nebulagraph/arangodb, influxdb/iotdb/questdb, redis требуют внешних сервисов — только компиляционная проверка
- **bee_config_macro / bee_orm_macro**: proc-macro, покрыты косвенно через свои integration tests, отдельных юнит-тестов нет

## Зафиксированные реальные баги (исходники библиотек не менялись)

1. `bee_tsdb/src/influxdb.rs:160-169` — `line_protocol` итерирует `&point.fields` (HashMap) без сортировки, тогда как tags отсортированы → при нескольких полях вывод line protocol недетерминирован
2. `bee_tsdb/src/iotdb.rs:37-42` — `write_batch` неатомарен (выполняется поточечно с `?`-коротким замыканием), противоречит заявленному в доках trait «atomically»
3. `bee_graph/src/neo4j.rs:106-109` — `add_edge` всегда возвращает `VertexNotFound(edge.from)`, даже если отсутствует конечная точка `to`
4. `bee_search` MemoryEngine `search` — игнорирует from/size, переданные из gRPC-слоя (нет пагинации)
5. `social_grpc/src/search.rs:54` — `h.id.parse().unwrap_or(0)`: нечисловой id молча превращается в 0
6. Документация макроса `context!` в `bee_template/src/lib.rs` — утверждает, что NaN паникует, но на деле serde_json ≥1.0.128 сериализует его в null (доки устарели)
7. `bee_search/src/elasticsearch.rs:64` — bulk NDJSON вставляет index/id в JSON как есть; id с `"` даёт некорректный NDJSON

## Заметки по окружению

- cargo находится в `~/.cargo/bin` (не в PATH), нужен `export PATH="$HOME/.cargo/bin:$PATH"`
- social_grpc требует `protoc`: получен через `apt-get download protobuf-compiler` + `dpkg-deb -x` распакован в `/tmp/protoc-local`, `PROTOC=/tmp/protoc-local/usr/bin/protoc` (sudo не требуется)
