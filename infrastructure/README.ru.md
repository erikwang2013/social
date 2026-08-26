# Beerust

**语言 / Languages:** [中文](README.md) · [English](README.en.md) · [한국어](README.ko.md) · [Русский](README.ru.md) · [Deutsch](README.de.md) · [Français](README.fr.md) · [Español](README.es.md) · [Português](README.pt.md) · [हिन्दी](README.hi.md) · [العربية](README.ar.md) · [বাংলা](README.bn.md) · [Bahasa Indonesia](README.id.md) · [日本語](README.ja.md)

<!-- Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz -->

Beerust — производственный веб-фреймворк на Rust. Его философия дизайна восходит к Go-фреймворку Beego и переосмыслена с помощью идиоматичных для Rust trait, macro и системы типов.

## Цели дизайна

| Цель | Показатель |
|------|------|
| **Опыт разработчика** | от `bee-rust new` до первого запроса < 30 секунд |
| **Производительность** | накладные расходы уровня контроллера < 5% (по сравнению с чистым axum), задержка маршрутизации P99 < 100µs |
| **Скорость компиляции** | полная сборка мета-крейта < 60 с (release), инкрементальная сборка < 5 с |
| **Размер бинарника** | минимальное приложение (только router) < 5MB (strip + LTO) |
| **Безопасность** | 0 unsafe в бизнес-коде; весь FFI инкапсулирован в отдельные крейты `*-sys` |
| **Совместимость** | MSRV Rust 1.80+, следуем stable |

## Принципы дизайна

1. **Философия Beego в выражении Rust** — MVC, пространства имён, цепочки фильтров реализованы через trait + macro
2. **Прогрессивное улучшение** — минимальное ядро зависит только от axum + tokio, всё остальное под feature flags
3. **Явное лучше неявного** — регистрация маршрутов, сопоставление моделей, порядок middleware — всё явно объявлено в коде
4. **Абстракции с нулевой стоимостью** — статическая диспетчеризация через trait, макросы раскрываются на этапе компиляции, без накладных расходов на виртуальные вызовы
5. **Независимость движков хранения** — каждый trait движка можно заменить отдельно, не затрагивая бизнес-логику верхнего уровня
6. **Встроенная наблюдаемость** — инструментирование tracing + metrics покрывает весь фреймворк, структурированное логирование включено по умолчанию

## Архитектура

### Топология крейтов

```
bee_rust/           # 元 crate，re-export + feature flags
bee_router/         # 路由 + 控制器 + Context + 过滤器链
bee_orm/            # ORM — Model trait + QuerySet + Migration + 关系映射
bee_kv/             # KV/Cache 统一抽象 — Redis + Memcached
bee_search/         # 搜索/分析引擎 — Elasticsearch + OpenSearch + ClickHouse
bee_graph/          # 图数据库 — Neo4j + NebulaGraph + ArangoDB
bee_tsdb/           # 时序数据库 — InfluxDB + Apache IoTDB + QuestDB
bee_config/         # 配置管理 — INI/YAML/ENV + 热更新
bee_cache/          # 缓存抽象 — Memory/Redis/Memcache
bee_session/        # Session — Memory/Redis/Cookie/Database 后端
bee_logs/           # 日志 — 多级日志 + tracing 集成
bee_template/       # 模板渲染 — 基于 tera
bee_cli/            # CLI — 脚手架/代码生成/开发运行/打包（迁移规划中）
```

### Схема архитектуры

```
                          ┌───────────────────────┐
                          │  bee_rust  (meta)      │
                          │  re-export + features  │
                          └───────────┬───────────┘
                                      │
              ┌───────────────────────┼───────────────────────┐
              │                       │                       │
     ┌────────▼────────┐    ┌────────▼────────┐    ┌────────▼────────┐
     │    Web Layer     │    │   Data Layer    │    │   Tool Layer    │
     └────────┬────────┘    └────────┬────────┘    └────────┬────────┘
              │                       │                       │
  ┌───────────┼───────────┐  ┌───────┼───────┐  ┌───────────┼───────────┐
  │ bee_router            │  │ bee_orm        │  │ bee_cli               │
  │  - route register     │  │  - Model/Query  │  │  - scaffolding        │
  │  - controller trait   │  │  - Migration   │  │  - hot reload          │
  │  - filter chain       │  │  - Connection   │  │  - code generation    │
  │  - param extract      │  │                 │  │                       │
  ├────────────────────────┤  ├────────────────┤  ├───────────────────────┤
  │ bee_template           │  │ bee_config     │  │ bee_logs              │
  │  - template render     │  │  - INI/YAML/ENV│  │  - multi-level log    │
  │  - HTML/JSON           │  │  - hot reload   │  │  - tracing integrate  │
  ├────────────────────────┤  ├────────────────┤  └───────────────────────┘
  │ bee_session            │  │ bee_cache      │
  │  - session management  │  │  - cache trait  │
  │  - multi-backend       │  │  - Mem/Redis    │
  └────────────────────────┘  └────────────────┘

  ┌─────────────────────────────────────────────────────────┐
  │                   Storage Engine Layer                   │
  ├──────────────────┬──────────────────────────────────────┤
  │ bee_kv           │  Redis + Memcached                   │
  │ bee_search       │  Elasticsearch + OpenSearch + ClickHouse │
  │ bee_graph        │  Neo4j + NebulaGraph + ArangoDB      │
  │ bee_tsdb         │  InfluxDB + Apache IoTDB + QuestDB   │
  └──────────────────┴──────────────────────────────────────┘
```

### Зависимости крейтов

```
bee_config (无依赖)
bee_logs   (无依赖)
bee_cache  → bee_config
bee_kv     → bee_config
bee_session → bee_cache, bee_config
bee_template (无依赖)
bee_orm    → bee_config, bee_cache
bee_search → bee_config
bee_graph  → bee_config
bee_tsdb   → bee_config
bee_router → bee_session, bee_template, bee_config, bee_logs
bee_cli    → bee_router, bee_orm
bee_rust   → 全部上述 crate (re-export)
```

### Поддерживаемые базы данных

| Категория | База данных | Крейт | Feature Flag |
|------|--------|-----------|-------------|
| **Реляционные** | SQLite | `bee_orm` | `sqlite` |
| | PostgreSQL | `bee_orm` | `postgres` |
| | MySQL | `bee_orm` | `mysql` |
| | TiDB | `bee_orm` | `mysql` |
| **KV / кэш** | Redis | `bee_kv` / `bee_cache` | `redis` |
| | Memcached | `bee_kv` / `bee_cache` | `memcache` |
| **Поиск / аналитика** | Elasticsearch | `bee_search` | `elasticsearch` |
| | OpenSearch | `bee_search` | `opensearch` |
| | ClickHouse | `bee_search` | `clickhouse` |
| **Графовые** | Neo4j | `bee_graph` | `neo4j` |
| | NebulaGraph | `bee_graph` | `nebulagraph` |
| | ArangoDB | `bee_graph` | `arangodb` |
| **Временные ряды** | InfluxDB | `bee_tsdb` | `influxdb` |
| | Apache IoTDB | `bee_tsdb` | `iotdb` |
| | QuestDB | `bee_tsdb` | `questdb` |

### Цепочка фильтров запросов

```
请求 → [SecurityFilter 攻击检测] → [Session 恢复] → [参数验证] → [prepare 钩子] → [handle 处理] → [finish 钩子] → 响应
                  ↓ 任何环节可中断（类似 Beego 的 Abort）
```

## Обзор возможностей

### Веб-ядро (bee_router)

```rust
use bee_rust::prelude::*;

// 定义控制器
struct UserController;

#[bee_router::async_trait]
impl Controller for UserController {
    async fn handle(&self, ctx: &mut Context) -> Result<(), RouterError> {
        ctx.json(&serde_json::json!({"users": []}))
    }
}

// 路由注册
let router = Router::new()
    .ns("/api/v1", |ns| {
        ns.get("/users")
          .post("/users");
    });
```

**Context предоставляет:**
- `ctx.json()` / `ctx.text()` / `ctx.html()` — вывод ответа
- `ctx.redirect()` — перенаправление
- `ctx.abort()` — прерывание запроса
- `ctx.session` — доступ к сессии
- `ctx.params` — параметры пути

### Защита от атак (feature `security`)

Фильтр обнаружения атак на основе [security-rust](https://crates.io/crates/security-rust), покрывающий 27 типов атак, включая XSS, SQL-инъекции, инъекции команд, SSRF и другие:

```rust
use bee_rust::prelude::*;

let security = SecurityFilter::new();  // 27 个检测器全开
```

Включение в `Cargo.toml`:
```toml
bee_rust = { features = ["security"] }
```

### ORM (bee_orm)

```rust
#[derive(Model)]
#[bee(table = "users")]
struct User {
    id:   i64,
    name: String,
    age:  i32,
}

// 链式查询
let users = User::query()
    .filter("age > 18")
    .order_by("created_at DESC")
    .limit(20)
    .to_sql();
// → SELECT * FROM users WHERE age > 18 ORDER BY created_at DESC LIMIT 20
```

### Управление конфигурацией (bee_config)

```rust
#[derive(Config)]
#[config(file = "conf/app.conf")]
struct AppConfig {
    app_name: String,
    http_port: u16,
    run_mode: String,
}

let cfg = AppConfig::load("conf/app.conf")?;
```

### Движки хранения

**KV/кэш:**
```rust
let kv = RedisStore::new("redis://localhost:6379").await?;
kv.set("key", b"value", Some(Duration::from_secs(60))).await?;
let val = kv.get("key").await?;
```

**Поисковый движок (планируется):**
```rust
// 驱动实现计划中，当前为 trait stub
let engine = ElasticsearchEngine::new("http://localhost:9200")?;
let result = engine.search("my_index", &SearchQuery {
    q: Some("keyword".into()), ..Default::default()
}).await?;
```

**Графовая БД (планируется):**
```rust
// 驱动实现计划中，当前为 trait stub
let db = Neo4jDB::new("bolt://localhost:7687").await?;
let vid = db.add_vertex("Person", &[("name", "Alice")]).await?;
```

**БД временных рядов (планируется):**
```rust
// 驱动实现计划中，当前为 trait stub
let tsdb = InfluxDB::new("http://localhost:8086").await?;
tsdb.write_point("cpu", &[("host", "srv1")], &[("value", 0.85)], Utc::now()).await?;
```

### Сессии

```rust
let cache = Arc::new(MemoryCache::new());
let mut session = Session::new(cache, Duration::from_secs(3600));
session.set("user_id", &"123")?;
let uid: String = session.get("user_id")?.unwrap();
```

### Логирование

```rust
Logger::new()
    .level(Level::INFO)
    .output(Output::MultiFile("logs/"))
    .async_()
    .init()?;
```

### Шаблоны

```rust
let engine = TemplateEngine::new("views/")?;
let result = engine.render("hello.html", &context! { name: &"World" })?;
// → "Hello, World!"
```

### CLI-инструменты

```bash
# 创建项目（生成可运行的脚手架：Cargo.toml + src/main.rs）
bee-rust new my-app

# 生成代码
bee-rust generate controller user
bee-rust generate model user --fields "name:string,age:int"

# 开发运行（--watch 监听 src/ 变化自动重启）
bee-rust run
bee-rust run --watch

# 打包部署（cargo build --release + 复制到 dist/）
bee-rust pack

# 数据库迁移（未实现，规划中）
bee-rust migrate up
```

> Примечание: параметр `pack --target` — зарезервированный интерфейс; текущий процесс сборки не различает целевые платформы.

## Использование

### Требования

- Rust 1.80+
- Cargo

### Установка

```bash
# 克隆项目
git clone https://github.com/erikwang2013/bee-rust.git
cd bee-rust

# 编译
cargo build --workspace

# 运行测试
cargo test --workspace
```

### Быстрый старт

```bash
# 使用 CLI 创建新项目
cargo run -p bee_cli -- new hello
cd hello

# 运行开发服务器
cargo run
```

### Использование в вашем проекте

```toml
[dependencies]
bee_rust = { git = "https://github.com/erikwang2013/bee-rust", features = ["full"] }
```

## Технические заметки

### Технологический стек

| Слой | Технология |
|----|------|
| HTTP-основа | axum 0.8 + tower 0.5 |
| Асинхронный рантайм | tokio 1.x |
| Сериализация | serde + serde_json |
| Шаблонизатор | tera 1.x |
| Слой логирования | tracing + tracing-subscriber |
| CLI | clap 4 |
| Разбор конфигов | toml / serde_yaml / собственный INI |
| Обработка ошибок | thiserror |
| Процедурные макросы | syn + quote + proc-macro2 |

### Паттерны проектирования

| Паттерн | Применение |
|------|------|
| **Builder** | Logger, Router, QuerySet |
| **Trait-абстракция** | Cache, KvStore, SearchEngine, GraphDB, TimeSeriesDB |
| **Производные макросы** | `#[derive(Model)]`, `#[derive(Config)]` |
| **Feature Gate** | реализации драйверов компилируются по требованию (redis, memcached, elasticsearch и др.) |
| **Filter Chain** | цепочка фильтров запросов, по образцу Beego Filter |

### Список крейтов

| Крейт | Назначение | Аналог в Beego |
|-------|------|-----------|
| `bee_rust` | мета-крейт, единая точка входа | — |
| `bee_router` | маршрутизация + контроллеры + Context + фильтры | `server/web`, `context` |
| `bee_orm` | ORM + QuerySet + Migration | `client/orm` |
| `bee_kv` | единая абстракция KV/кэша | `client/cache` (расширенный) |
| `bee_search` | поисковый/аналитический движок | — (новый) |
| `bee_graph` | графовая база данных | — (новый) |
| `bee_tsdb` | база временных рядов | — (новый) |
| `bee_config` | управление конфигурацией + горячая перезагрузка | `client/config` |
| `bee_cache` | абстракция кэша | `client/cache` |
| `bee_session` | управление сессиями | `server/web/session` |
| `bee_logs` | логирование | `logs` |
| `bee_template` | рендеринг шаблонов | — (улучшенный) |
| `bee_cli` | CLI-инструменты | инструмент `bee` |

### Покрытие тестами

Все 68 тестов в репозитории проходят:

| Крейт | Кол-во тестов |
|-------|--------|
| bee_config | 4 |
| bee_cache | 4 |
| bee_template | 2 |
| bee_logs | 3 |
| bee_kv | 4 |
| bee_search | 6 |
| bee_graph | 5 |
| bee_tsdb | 5 |
| bee_orm | 7 |
| bee_session | 2 |
| bee_router | 9 |
| bee_cli | 16 |

## Поддержка

Если этот проект вам помог, поддержите нас, отсканировав QR-коды. Спасибо!

**WeChat Pay**

<img src="docs/weixinpay.png" width="160" height="175" alt="WeChat Pay">

**Alipay**

<img src="docs/alipay.png" width="160" height="175" alt="Alipay">

### Лицензия

Apache-2.0
