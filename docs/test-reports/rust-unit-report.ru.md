# Отчёт о модульных тестах Rust Workspace
**语言 / Languages:** [中文](rust-unit-report.md) · [English](rust-unit-report.en.md) · [한국어](rust-unit-report.ko.md) · [Русский](rust-unit-report.ru.md) · [Deutsch](rust-unit-report.de.md) · [Français](rust-unit-report.fr.md) · [Español](rust-unit-report.es.md) · [Português](rust-unit-report.pt.md) · [हिन्दी](rust-unit-report.hi.md) · [العربية](rust-unit-report.ar.md) · [বাংলা](rust-unit-report.bn.md) · [Bahasa Indonesia](rust-unit-report.id.md) · [日本語](rust-unit-report.ja.md)

- Дата: 2026-08-27
- Расположение: `/home/wwwroot/social/infrastructure`
- Команда: `cargo test --workspace` (features по умолчанию), плюс проверка бэкендов под feature-флагами (tsdb/graph/search/kv)
- Результат: **183 пройдено / 0 не пройдено** (178 юнит+интеграционных + 5 inline под feature + 1 doctest и т.д.; в workspace по умолчанию включены 6 случаев bee_search, потому что social_grpc зависит от его feature `elasticsearch`)

## Сводка

| crate | Тестов | Пройдено | Не пройдено | Покрываемые модули |
|-------|-------|------|------|----------|
| bee_cache | 8 | 8 | 0 | set/get/delete/expire, incr, ttl, MemoryCache |
| bee_cli | 14 | 14 | 0 | маппинг rust_type, find_bin_name, validate_name, разбор аргументов bin |
| bee_config | 8 + 6 интеграция | 14 | 0 | IniParser (комментарии/пробелы/переключение секций), Config, ConfigSource, ошибки перезагрузки |
| bee_config_macro | 0 | — | — | покрыт косвенно через integration tests |
| bee_graph | 15 | 15 | 0 | StubGraphDB: направление/глубина/метки обхода, add/update/delete, пути ошибок, serde (feature neo4j ещё 5) |
| bee_kv | 10 | 10 | 0 | get/incr/expire/mset, просроченные ключи, пути ошибок (feature redis ещё 3 случая с реальным Redis) |
| bee_logs | 4 | 4 | 0 | level_str все уровни, file output, stdout/stderr output |
| bee_orm | 19 интеграция | 19 | 0 | SelectBuilder: order/limit/offset/связывание параметров/переиспользование/table_name/Display ошибок (в lib 0) |
| bee_orm_macro | 0 | — | — | покрыт косвенно через integration tests |
| bee_router | 36 | 36 | 0 | context (params/text/html/abort), router (method/404/namespace), конвейер dispatch, восстановление/персистентность/просроченные cookie сессий |
| bee_rust | 2 (bin ещё 9) | 11 | 0 | экспорт prelude, алиас Result, разбор аргументов CLI |
| bee_search | 20 (вкл. 6 inline под feature) | 20 | 0 | MemoryEngine: index/delete/перезапись/пагинация/get/пустой запрос/serde; драйвер Elasticsearch: get/search/bulk/aggregate, экранирование NDJSON |
| bee_session | 8 | 8 | 0 | set/get/delete, save/load/refresh, TTL floor, пути ошибок, уникальность UUID |
| bee_template | 6 + 1 doctest | 7 | 0 | макрос context!, render, ошибки отсутствия шаблона/переменной, пустой engine, неконечные числа с плавающей точкой (1 doctest) |
| bee_tsdb | 11 | 11 | 0 | записи/пакетные записи, границы запросов диапазона, фильтрация Eq/Neq/Regex/AND, Point serde, CQ (feature influxdb ещё 5, вкл. детерминизм line protocol) |
| social_grpc | 6 | 6 | 0 | SearchService: round-trip index/search/delete, откат на невалидный JSON, пустой индекс, ошибка нечислового id |
| hello_bee | 0 | — | — | пример программы, без тестов |

## Реальные дефекты, исправленные в этом раунде (минимальный фикс + регрессионные тесты)

1. **bee_search MemoryEngine `search` игнорирует пагинацию** (`crates/bee_search/src/lib.rs`) — `from`/`size`, переданные из gRPC-слоя, отбрасывались, всегда возвращались все результаты. Фикс: читает `from`/`size` из JSON запроса и применяет skip/truncate к hits, `total` по-прежнему считает все совпадения. Новый: `test_search_honors_from_size_pagination` (устойчив к неупорядоченной итерации HashMap: сравнение со срезом полного результата самого движка).
2. **social_grpc `search` молча превращает нечисловые id в 0** (`crates/social_grpc/src/search.rs:53-60`) — `h.id.parse().unwrap_or(0)` молча возвращал нечисловые id документов как 0. Фикс: ошибка парсинга возвращает `Status::invalid_argument`. Новый: `non_numeric_hit_id_becomes_invalid_argument`.
3. **bee_tsdb InfluxDB поля line protocol не упорядочены** (`crates/bee_tsdb/src/influxdb.rs:160-170`) — tags отсортированы, а fields нет; при нескольких полях вывод недетерминирован. Фикс: сортировка fields по ключу. Новый: `line_protocol_is_deterministic_across_field_insertion_order` (разные порядки вставки дают идентичные строки, отсортированные по a,b).
4. **bee_search Elasticsearch bulk NDJSON не экранирует id** (`crates/bee_search/src/elasticsearch.rs`) — index/id вставлялись в строку напрямую; id с `"` давали некорректный NDJSON. Фикс: выделен `bulk_ndjson()`, строки действий сериализуются через serde_json. Новый: `bulk_ndjson_escapes_ids_and_stays_parseable`.
5. **bee_graph Neo4j `add_edge` всегда сообщает конечную точку `from`** (`crates/bee_graph/src/neo4j.rs:107-116`) — когда отсутствующая конечная точка — `to`, сообщение об ошибке вводит в заблуждение. Фикс: при `nodes-matched < 2` использовать `get_vertex`, чтобы определить реально отсутствующую конечную точку перед сообщением. Новый: `add_edge_reports_the_missing_endpoint` (мок-HTTP-сервис проверяет, что сообщается `to1`, а не `from1`).
6. **bee_template документация `context!` не соответствует поведению** (`crates/bee_template/src/lib.rs`) — в доках утверждается, что неконечные float паникуют, но serde_json на деле сериализует их в `null` (доказано существующим тестом). Фикс: документация обновлена.

## Новое покрытие

- **Интеграционные тесты bee_kv RedisStore с реальным Redis** (`crates/bee_kv/src/redis_store.rs`, feature `redis`) — закрывает пробел покрытия, явно названный в предыдущем отчёте. Локальный Redis доступен (127.0.0.1:6379); 3 случая: round-trip set/get/del, incr/expire, mset/mget; ключи с префиксом pid+наносекунды, случаи убирают за собой. Если Redis недоступен, случаи элегантно пропускаются (печатают SKIP и проходят).

## Пробелы покрытия (без изменений)

- **bee_tsdb IoTDB `write_batch` неатомарен** (`crates/bee_tsdb/src/iotdb.rs`) — поточечные записи с `?`-коротким замыканием, несовместимо с «atomically» из доков trait. Фикс требует транзакционной поддержки бэкенда; локального экземпляра IoTDB нет, поэтому в этом раунде изменений вслепую не делается; указано как известное ограничение.
- **Внешние бэкенды** (es/opensearch/clickhouse, neo4j/nebulagraph/arangodb, influxdb/iotdb/questdb, memcached) — основные драйверы (elasticsearch, neo4j, influxdb: пути записи/запроса/CQ) покрыты локальными мок-HTTP-сервисами; остальные без локального сервиса проверены только на уровне компиляции.
- **MySQL**: локально доступен 127.0.0.1:3306 (root, пустой пароль), но ни один crate в workspace не вводит драйвер MySQL — bee_orm это драйверонезависимый SQL-билдер, случаи QuerySet не зависят от реальной БД; зависимость от драйвера не нужна и добавлять её не следует.
- **bee_config_macro / bee_orm_macro**: proc-macro, покрыты косвенно через свои integration tests, отдельных юнит-тестов нет.

## Проверка качества

- `cargo fmt --check`: пройдено (в этом раунде `cargo fmt` запущен по всему workspace, исправлено 20+ отклонений форматирования, оставленных прошлыми сессиями).
- `cargo clippy --workspace --all-targets`: ноль предупреждений в новом коде; оставшиеся 3 — уже существовавшие предупреждения (bee_config `get("default").is_none()`, bee_rust `unwrap()` по Ok, у bee_search MemoryEngine нет реализации Default), вне рамок этого раунда.

## Заметки по окружению

- cargo находится в `~/.cargo/bin` (по умолчанию не в PATH), нужен `export PATH="$HOME/.cargo/bin:$PATH"`.
- `protoc` теперь доступен (`/home/erik/.local/bin/protoc`).
- social_grpc работает в фоне (порт 50051); этот отчёт выполнял только `cargo test`, без `cargo run` на нём.
- Redis (6379) и MySQL (3306) доступны локально; список feature-случаев:
  - `cargo test -p bee_tsdb --features influxdb` → 16 пройдено
  - `cargo test -p bee_search --features elasticsearch` → 20 пройдено
  - `cargo test -p bee_graph --features neo4j` → 20 пройдено
  - `cargo test -p bee_kv --features redis` → 13 пройдено (вкл. 3 случая с реальным Redis)
