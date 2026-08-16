<!-- Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz -->
# Beerust

[中文](README.md)

Beerust is a production-grade web framework for Rust, inspired by Go's [Beego](https://github.com/beego/beego) framework and reimagined with Rust-idiomatic traits, macros, and type system.

## Design Goals

| Goal | Target |
|------|--------|
| **Developer Experience** | `Beerust new` to first request < 30 seconds |
| **Performance** | Controller overhead < 5% (vs bare axum), P99 routing < 100µs |
| **Compile Speed** | Full workspace < 60s (release), incremental < 5s |
| **Binary Size** | Minimal app (router only) < 5MB (strip + LTO) |
| **Safety** | Zero unsafe in business code; FFI isolated in `*-sys` crates |
| **Compatibility** | Rust 1.80+ MSRV, tracks stable |

## Design Principles

1. **Beego philosophy, Rust expression** — MVC, namespaces, filter chains via trait + macro
2. **Progressive enhancement** — Minimal core depends only on axum + tokio; everything else is feature-gated
3. **Explicit over implicit** — Route registration, model mapping, middleware ordering all explicitly declared in code
4. **Zero-cost abstraction** — Static dispatch via traits, compile-time macro expansion, no virtual call overhead
5. **Storage engine independence** — Each engine trait is independently swappable without affecting business logic
6. **Observability built-in** — tracing + metrics instrumentation across the framework; structured logging by default

## Architecture

### Crate Topology

```
bee_rust/           # Meta crate, re-export + feature flags
bee_router/         # Routing + Controller + Context + Filter chain
bee_orm/            # ORM — Model trait + QuerySet + Migration + Relations
bee_kv/             # Unified KV/Cache — Redis + Memcached
bee_search/         # Search/Analytics — Elasticsearch + OpenSearch + ClickHouse
bee_graph/          # Graph DB — Neo4j + NebulaGraph + ArangoDB
bee_tsdb/           # Time Series — InfluxDB + Apache IoTDB + QuestDB
bee_config/         # Config — INI/YAML/ENV + hot-reload
bee_cache/          # Cache abstraction — Memory/Redis/Memcache
bee_session/        # Session — Memory/Redis/Cookie/Database backends
bee_logs/           # Logging — multi-level + tracing integration
bee_template/       # Template rendering — tera-based
bee_cli/            # CLI — scaffolding/codegen/dev-run/pack (migrations planned)
```

### Architecture Diagram

```
                          ┌───────────────────────┐
                          │  bee_rust  (meta)      │
                          │  re-export + features  │
                          └───────────┬───────────┘
                                      │
              ┌───────────────────────┼───────────────────────┐
              │                       │                       │
     ┌────────▼────────┐    ┌────────▼────────┐    ┌────────▼────────┐
     │    Web Tier      │    │   Data Tier     │    │   Tool Tier     │
     └────────┬────────┘    └────────┬────────┘    └────────┬────────┘
              │                       │                       │
  ┌───────────┼───────────┐  ┌───────┼───────┐  ┌───────────┼───────────┐
  │ bee_router            │  │ bee_orm        │  │ bee_cli               │
  │  - routes             │  │  - Model/Query  │  │  - scaffolding        │
  │  - controller trait   │  │  - Migration   │  │  - hot reload          │
  │  - filter chain       │  │  - Connection   │  │  - codegen            │
  ├────────────────────────┤  ├────────────────┤  ├───────────────────────┤
  │ bee_template           │  │ bee_config     │  │ bee_logs              │
  ├────────────────────────┤  ├────────────────┤  └───────────────────────┘
  │ bee_session            │  │ bee_cache      │
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

### Dependency Graph

```
bee_config (no deps)
bee_logs   (no deps)
bee_cache  → bee_config
bee_kv     → bee_config
bee_session → bee_cache, bee_config
bee_template (no deps)
bee_orm    → bee_config, bee_cache
bee_search → bee_config
bee_graph  → bee_config
bee_tsdb   → bee_config
bee_router → bee_session, bee_template, bee_config, bee_logs
bee_cli    → bee_router, bee_orm
bee_rust   → all above (re-export)
```

### Supported Databases

| Category | Database | Crate | Feature Flag |
|----------|---------|-------|-------------|
| **Relational** | SQLite | `bee_orm` | `sqlite` |
| | PostgreSQL | `bee_orm` | `postgres` |
| | MySQL | `bee_orm` | `mysql` |
| | TiDB | `bee_orm` | `mysql` |
| **KV / Cache** | Redis | `bee_kv` / `bee_cache` | `redis` |
| | Memcached | `bee_kv` / `bee_cache` | `memcache` |
| **Search / Analytics** | Elasticsearch | `bee_search` | `elasticsearch` |
| | OpenSearch | `bee_search` | `opensearch` |
| | ClickHouse | `bee_search` | `clickhouse` |
| **Graph** | Neo4j | `bee_graph` | `neo4j` |
| | NebulaGraph | `bee_graph` | `nebulagraph` |
| | ArangoDB | `bee_graph` | `arangodb` |
| **Time Series** | InfluxDB | `bee_tsdb` | `influxdb` |
| | Apache IoTDB | `bee_tsdb` | `iotdb` |
| | QuestDB | `bee_tsdb` | `questdb` |

### Request Filter Chain

```
Request → [SecurityFilter Attack Detection] → [Session Restore] → [Validation] → [prepare hook] → [handle] → [finish hook] → Response
                  ↓ Interruptible at any stage (Beego-style Abort)
```

## Features

### Web Core (bee_router)

```rust
use bee_rust::prelude::*;

struct UserController;

#[bee_router::async_trait]
impl Controller for UserController {
    async fn handle(&self, ctx: &mut Context) -> Result<(), RouterError> {
        ctx.json(&serde_json::json!({"users": []}))
    }
}

let router = Router::new()
    .ns("/api/v1", |ns| {
        ns.get("/users")
          .post("/users");
    });
```

**Context provides:**
- `ctx.json()` / `ctx.text()` / `ctx.html()` — response output
- `ctx.redirect()` — redirection
- `ctx.abort()` — request interruption
- `ctx.session` — session access
- `ctx.params` — path parameters

### Security Detection (`security` feature)

Attack detection powered by [security-rust](https://crates.io/crates/security-rust), covering XSS, SQL injection, command injection, SSRF, and 23 other attack types via 27 detectors:

```rust
use bee_rust::prelude::*;

let security = SecurityFilter::new();  // all 27 detectors enabled
```

Enable in `Cargo.toml`:
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

let users = User::query()
    .filter("age > 18")
    .order_by("created_at DESC")
    .limit(20)
    .to_sql();
// → SELECT * FROM users WHERE age > 18 ORDER BY created_at DESC LIMIT 20
```

### Config (bee_config)

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

### Storage Engines

**KV/Cache** — unified trait with swappable driver implementations via feature gates.

**Search, Graph, Time Series** — unified traits defined; driver implementations planned.

### Session

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

### CLI Tool

```bash
# Scaffold a runnable project (Cargo.toml + src/main.rs)
bee-rust new my-app

# Code generation
bee-rust generate controller user
bee-rust generate model user --fields "name:string,age:int"

# Dev server (--watch restarts on src/ changes)
bee-rust run
bee-rust run --watch

# Packaging (cargo build --release + copy to dist/)
bee-rust pack

# Database migrations (not implemented yet — planned)
bee-rust migrate up
```

> Note: `pack --target` is a reserved argument; packaging does not currently
> distinguish target platforms.

## Getting Started

### Prerequisites

- Rust 1.80+
- Cargo

### Install

```bash
git clone https://github.com/erikwang2013/bee-rust.git
cd bee-rust
cargo build --workspace
cargo test --workspace
```

### Quick Start

```bash
cargo run -p bee_cli -- new hello
cd hello
cargo run
```

### Use as Dependency

```toml
[dependencies]
bee_rust = { git = "https://github.com/erikwang2013/bee-rust", features = ["full"] }
```

## Tech Stack

| Layer | Technology |
|-------|-----------|
| HTTP Base | axum 0.8 + tower 0.5 |
| Async Runtime | tokio 1.x |
| Serialization | serde + serde_json |
| Template Engine | tera 1.x |
| Logging | tracing + tracing-subscriber |
| CLI | clap 4 |
| Config Parsing | toml / serde_yaml / custom INI |
| Error Handling | thiserror |
| Proc Macros | syn + quote + proc-macro2 |

## Design Patterns

| Pattern | Usage |
|---------|-------|
| **Builder** | Logger, Router, QuerySet |
| **Trait Abstraction** | Cache, KvStore, SearchEngine, GraphDB, TimeSeriesDB |
| **Derive Macros** | `#[derive(Model)]`, `#[derive(Config)]` |
| **Feature Gates** | Driver implementations compiled on demand |
| **Filter Chain** | Request filter chain, mirrors Beego Filter |

## Crate Index

| Crate | Purpose | Beego Equivalent |
|-------|---------|-----------------|
| `bee_rust` | Meta crate, unified entry | — |
| `bee_router` | Routing + Controller + Context + Filter | `server/web`, `context` |
| `bee_orm` | ORM + QuerySet + Migration | `client/orm` |
| `bee_kv` | Unified KV/Cache | `client/cache` (extended) |
| `bee_search` | Search/Analytics engine | — (new) |
| `bee_graph` | Graph database | — (new) |
| `bee_tsdb` | Time series database | — (new) |
| `bee_config` | Config + hot-reload | `client/config` |
| `bee_cache` | Cache abstraction | `client/cache` |
| `bee_session` | Session management | `server/web/session` |
| `bee_logs` | Logging | `logs` |
| `bee_template` | Template rendering | — (enhanced) |
| `bee_cli` | CLI tooling | `bee` tool |

## Test Coverage

68 tests passing across all crates:

| Crate | Tests |
|-------|-------|
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

## Support

If this project helps you, feel free to scan the QR codes below to show your support. Thank you!

**WeChat Pay**

<img src="docs/weixinpay.png" width="160" height="175" alt="WeChat Pay">

**Alipay**

<img src="docs/alipay.png" width="160" height="175" alt="Alipay">

## License

Apache-2.0
