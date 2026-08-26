# Beerust

**语言 / Languages:** [中文](README.md) · [English](README.en.md) · [한국어](README.ko.md) · [Русский](README.ru.md) · [Deutsch](README.de.md) · [Français](README.fr.md) · [Español](README.es.md) · [Português](README.pt.md) · [हिन्दी](README.hi.md) · [العربية](README.ar.md) · [বাংলা](README.bn.md) · [Bahasa Indonesia](README.id.md) · [日本語](README.ja.md)

<!-- Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz -->

Beerust ist ein produktionsreifes Web-Framework in Rust. Seine Designphilosophie stammt vom Go-Framework Beego und wird mit Rust-typischen Traits, Makros und dem Typsystem neu ausgedrückt.

## Designziele

| Ziel | Kennzahl |
|------|------|
| **Entwicklererfahrung** | von `bee-rust new` bis zur ersten Anfrage < 30 Sekunden |
| **Leistung** | Overhead der Controller-Ebene < 5 % (gegenüber reinem axum), P99-Routing-Latenz < 100µs |
| **Kompiliergeschwindigkeit** | vollständiger Build des Meta-Crates < 60 s (release), inkrementeller Build < 5 s |
| **Binärgröße** | minimale Anwendung (nur Router) < 5MB (strip + LTO) |
| **Sicherheit** | 0 unsafe im Geschäftscode; sämtliches FFI in separaten `*-sys`-Crates gekapselt |
| **Kompatibilität** | MSRV Rust 1.80+, folgt stable |

## Designprinzipien

1. **Beego-Philosophie, in Rust ausgedrückt** — MVC, Namespaces und Filterketten implementiert mit Traits + Makros
2. **Progressive Verbesserung** — der minimale Kern hängt nur von axum + tokio ab, alles andere ist per Feature-Gate steuerbar
3. **Explizit vor implizit** — Routenregistrierung, Modellzuordnung und Middleware-Reihenfolge sind im Code explizit deklariert
4. **Zero-Cost-Abstraktion** — statischer Dispatch über Traits, Makros werden zur Kompilierzeit expandiert, keine Virtual-Call-Overheads
5. **Unabhängigkeit der Speicher-Engines** — jedes Engine-Trait lässt sich einzeln austauschen, ohne die darüberliegende Geschäftslogik zu beeinflussen
6. **Integrierte Observability** — tracing + metrics Instrumentierung deckt das gesamte Framework ab, strukturiertes Logging standardmäßig aktiviert

## Architektur-Design

### Crate-Topologie

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

### Architekturdiagramm

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

### Crate-Abhängigkeiten

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

### Unterstützte Datenbanken

| Kategorie | Datenbank | Zugehöriges Crate | Feature Flag |
|------|--------|-----------|-------------|
| **Relational** | SQLite | `bee_orm` | `sqlite` |
| | PostgreSQL | `bee_orm` | `postgres` |
| | MySQL | `bee_orm` | `mysql` |
| | TiDB | `bee_orm` | `mysql` |
| **KV / Cache** | Redis | `bee_kv` / `bee_cache` | `redis` |
| | Memcached | `bee_kv` / `bee_cache` | `memcache` |
| **Suche / Analyse** | Elasticsearch | `bee_search` | `elasticsearch` |
| | OpenSearch | `bee_search` | `opensearch` |
| | ClickHouse | `bee_search` | `clickhouse` |
| **Graphdatenbanken** | Neo4j | `bee_graph` | `neo4j` |
| | NebulaGraph | `bee_graph` | `nebulagraph` |
| | ArangoDB | `bee_graph` | `arangodb` |
| **Zeitreihen** | InfluxDB | `bee_tsdb` | `influxdb` |
| | Apache IoTDB | `bee_tsdb` | `iotdb` |
| | QuestDB | `bee_tsdb` | `questdb` |

### Anfrage-Filterkette

```
请求 → [SecurityFilter 攻击检测] → [Session 恢复] → [参数验证] → [prepare 钩子] → [handle 处理] → [finish 钩子] → 响应
                  ↓ 任何环节可中断（类似 Beego 的 Abort）
```

## Funktionsübersicht

### Web-Kern (bee_router)

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

**Context bietet:**
- `ctx.json()` / `ctx.text()` / `ctx.html()` — Antwortausgabe
- `ctx.redirect()` — Weiterleitung
- `ctx.abort()` — Anfrage abbrechen
- `ctx.session` — Session-Zugriff
- `ctx.params` — Pfadparameter

### Angriffserkennung (Feature `security`)

Ein Angriffserkennungs-Filter basierend auf [security-rust](https://crates.io/crates/security-rust), der 27 Angriffsarten abdeckt, darunter XSS, SQL-Injection, Command Injection, SSRF und mehr:

```rust
use bee_rust::prelude::*;

let security = SecurityFilter::new();  // 27 个检测器全开
```

Aktivieren in `Cargo.toml`:
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

### Konfigurationsverwaltung (bee_config)

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

### Speicher-Engines

**KV/Cache:**
```rust
let kv = RedisStore::new("redis://localhost:6379").await?;
kv.set("key", b"value", Some(Duration::from_secs(60))).await?;
let val = kv.get("key").await?;
```

**Suchmaschine (geplant):**
```rust
// 驱动实现计划中，当前为 trait stub
let engine = ElasticsearchEngine::new("http://localhost:9200")?;
let result = engine.search("my_index", &SearchQuery {
    q: Some("keyword".into()), ..Default::default()
}).await?;
```

**Graphdatenbank (geplant):**
```rust
// 驱动实现计划中，当前为 trait stub
let db = Neo4jDB::new("bolt://localhost:7687").await?;
let vid = db.add_vertex("Person", &[("name", "Alice")]).await?;
```

**Zeitreihendatenbank (geplant):**
```rust
// 驱动实现计划中，当前为 trait stub
let tsdb = InfluxDB::new("http://localhost:8086").await?;
tsdb.write_point("cpu", &[("host", "srv1")], &[("value", 0.85)], Utc::now()).await?;
```

### Sessions

```rust
let cache = Arc::new(MemoryCache::new());
let mut session = Session::new(cache, Duration::from_secs(3600));
session.set("user_id", &"123")?;
let uid: String = session.get("user_id")?.unwrap();
```

### Logging

```rust
Logger::new()
    .level(Level::INFO)
    .output(Output::MultiFile("logs/"))
    .async_()
    .init()?;
```

### Templates

```rust
let engine = TemplateEngine::new("views/")?;
let result = engine.render("hello.html", &context! { name: &"World" })?;
// → "Hello, World!"
```

### CLI-Werkzeuge

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

> Hinweis: Der Parameter `pack --target` ist eine reservierte Schnittstelle; der aktuelle Verpackungsprozess unterscheidet nicht zwischen Zielplattformen.

## Verwendung

### Voraussetzungen

- Rust 1.80+
- Cargo

### Installation

```bash
# 克隆项目
git clone https://github.com/erikwang2013/bee-rust.git
cd bee-rust

# 编译
cargo build --workspace

# 运行测试
cargo test --workspace
```

### Schnellstart

```bash
# 使用 CLI 创建新项目
cargo run -p bee_cli -- new hello
cd hello

# 运行开发服务器
cargo run
```

### Im eigenen Projekt verwenden

```toml
[dependencies]
bee_rust = { git = "https://github.com/erikwang2013/bee-rust", features = ["full"] }
```

## Technische Hinweise

### Technologie-Stack

| Ebene | Technologie |
|----|------|
| HTTP-Basis | axum 0.8 + tower 0.5 |
| Async-Laufzeit | tokio 1.x |
| Serialisierung | serde + serde_json |
| Template-Engine | tera 1.x |
| Logging-Basis | tracing + tracing-subscriber |
| CLI | clap 4 |
| Konfigurationsparsing | toml / serde_yaml / eigenes INI |
| Fehlerbehandlung | thiserror |
| Prozedurale Makros | syn + quote + proc-macro2 |

### Entwurfsmuster

| Muster | Anwendung |
|------|------|
| **Builder** | Logger, Router, QuerySet |
| **Trait-Abstraktion** | Cache, KvStore, SearchEngine, GraphDB, TimeSeriesDB |
| **Ableitungs-Makros** | `#[derive(Model)]`, `#[derive(Config)]` |
| **Feature Gate** | Treiberimplementierungen werden bei Bedarf kompiliert (redis, memcached, elasticsearch usw.) |
| **Filter Chain** | Anfrage-Filterkette, angelehnt an Beego Filter |

### Crate-Liste

| Crate | Funktion | Beego-Pendant |
|-------|------|-----------|
| `bee_rust` | Meta-Crate, einheitlicher Einstiegspunkt | — |
| `bee_router` | Routing + Controller + Context + Filter | `server/web`, `context` |
| `bee_orm` | ORM + QuerySet + Migration | `client/orm` |
| `bee_kv` | Einheitliche KV/Cache-Abstraktion | `client/cache` (erweitert) |
| `bee_search` | Such-/Analyse-Engine | — (neu) |
| `bee_graph` | Graphdatenbank | — (neu) |
| `bee_tsdb` | Zeitreihendatenbank | — (neu) |
| `bee_config` | Konfigurationsverwaltung + Hot Reload | `client/config` |
| `bee_cache` | Cache-Abstraktion | `client/cache` |
| `bee_session` | Session-Verwaltung | `server/web/session` |
| `bee_logs` | Logging | `logs` |
| `bee_template` | Template-Rendering | — (erweitert) |
| `bee_cli` | CLI-Werkzeug | `bee`-Werkzeug |

### Testabdeckung

Alle 68 Tests im Repository bestehen:

| Crate | Anzahl Tests |
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

## Unterstützung

Wenn Ihnen dieses Projekt hilft, freuen wir uns über eine Spende per QR-Code-Scan. Vielen Dank!

**WeChat Pay**

<img src="docs/weixinpay.png" width="160" height="175" alt="WeChat Pay">

**Alipay**

<img src="docs/alipay.png" width="160" height="175" alt="Alipay">

### Lizenz

Apache-2.0
