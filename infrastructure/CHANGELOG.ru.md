# Журнал изменений

**语言 / Languages:** [中文](CHANGELOG.md) · [English](CHANGELOG.en.md) · [한국어](CHANGELOG.ko.md) · [Русский](CHANGELOG.ru.md) · [Deutsch](CHANGELOG.de.md) · [Français](CHANGELOG.fr.md) · [Español](CHANGELOG.es.md) · [Português](CHANGELOG.pt.md) · [हिन्दी](CHANGELOG.hi.md) · [العربية](CHANGELOG.ar.md) · [বাংলা](CHANGELOG.bn.md) · [Bahasa Indonesia](CHANGELOG.id.md) · [日本語](CHANGELOG.ja.md)

## [1.0.6] — 2026-08-07

### Добавлено
- Реальные реализации `bee_cli`: `new` (создание проекта), `generate controller/model`, `run` с горячей перезагрузкой `--watch`, `pack` (сборка релиза + копирование в `dist/`)
- Модульные тесты CLI для создания проектов и генерации кода (7 новых тестов)

### Исправлено
- `bee_rust::init()` теперь скрыт за фичей `logs` — сокращённые сборки фич (например, `--no-default-features --features kv`) снова компилируются
- Линт Clippy `unnecessary_map_or` в `bee_kv::InMemoryKvStore::exists`
- Из `rustfmt.toml` удалены опции только для nightly, которые молча игнорировались на stable; workspace теперь проходит `cargo fmt --all --check`
- Бинарю `bee_cli` задан `doc = false` — устранено конфликтующее имя файла вывода rustdoc с `bee_rust`
- Порт примера `hello` теперь настраивается через переменную окружения `PORT`

### Изменено
- `bee-rust migrate` сообщает «not implemented» и завершается с ненулевым кодом (планируется)
- README / README.en обновлены с описанием реального поведения CLI

## [1.0.4] — 2026-07-29

### Добавлено
- Фильтр обнаружения атак через `security-rust` (27 детекторов)
- `SecurityFilter` с покрытием XSS, SQL-инъекций, инъекций команд и обхода пути
- Флаг фичи `security` в `bee_rust` и `bee_router`

### Изменено
- README обновлён документацией по функциям безопасности
- README обновлён разделом поддержки платежей (WeChat Pay / Alipay)

### Исправлено
- Синтаксис raw-идентификаторов Tera в `bee_template` для Rust 2024 edition

## [1.0.3] — 2026-07-29

### Добавлено
- Начальная структура workspace из 13 крейтов
- MVC-роутинг с трейтом `Controller` и `Router`
- ORM с билдером `QuerySet` и derive-макросом `Model`
- Абстракция KV/Cache с бэкендами Redis и Memory
- Управление сессиями с бэкендами Memory/Redis
- Управление конфигурацией с поддержкой INI/YAML/ENV и горячей перезагрузкой
- Рендеринг шаблонов через Tera
- Логирование с интеграцией tracing
- Создание проектов и генерация кода через CLI
- Заглушки трейтов движков поиска, графов и временных рядов (драйверы планируются)

[1.0.4]: https://github.com/erikwang2013/bee-rust/compare/v1.0.3...v1.0.4
[1.0.3]: https://github.com/erikwang2013/bee-rust/releases/tag/v1.0.3
