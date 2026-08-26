# Beerust

**语言 / Languages:** [中文](README.md) · [English](README.en.md) · [한국어](README.ko.md) · [Русский](README.ru.md) · [Deutsch](README.de.md) · [Français](README.fr.md) · [Español](README.es.md) · [Português](README.pt.md) · [हिन्दी](README.hi.md) · [العربية](README.ar.md) · [বাংলা](README.bn.md) · [Bahasa Indonesia](README.id.md) · [日本語](README.ja.md)

<!-- Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz -->

Beerust is a production-grade web framework written in Rust. Its design philosophy derives from Beego, the Go framework, re-expressed through Rust's idiomatic traits, macros, and type system.

## Design Goals

| Goal | Metric |
|------|------|
| **Developer experience** | From `bee-rust new` to first request < 30 seconds |
| **Performance** | Controller-layer overhead < 5% (vs. bare axum), P99 routing latency < 100µs |
| **Compile speed** | Full build of the meta crate < 60s (release), incremental build < 5s |
| **Binary size** | Minimal app (router only) < 5MB (strip + LTO) |
| **Safety** | 0 unsafe business code; all FFI wrapped in dedicated `*-sys` crates |
| **Compatibility** | MSRV Rust 1.80+, tracking stable |

## Design Principles

1. **Beego philosophy, expressed in Rust** — MVC, namespaces, and filter chains implemented with traits + macros
2. **Progressive enhancement** — the minimal core only depends on axum + tokio; everything else is feature-gated
3. **Explicit over implicit** — route registration, model mapping, and middleware order are all explicitly declared in code
4. **Zero-cost abstraction** — static dispatch with traits, compile-time expansion with macros, no virtual call overhead
5. **Storage engine independence** — each engine trait can be replaced independently without affecting upper-layer business logic
6. **Built-in observability** — tracing + metrics instrumentation across the whole framework, structured logging enabled by default

## Architecture Design

### Crate Topology

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

### Crate Dependencies

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

### Supported Databases

| Category | Database | Crate | Feature Flag |
|------|--------|-----------|-------------|
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
请求 → [SecurityFilter 攻击检测] → [Session 恢复] → [参数验证] → [prepare 钩子] → [handle 处理] → [finish 钩子] → 响应
                  ↓ 任何环节可中断（类似 Beego 的 Abort）
```

## Feature Overview

### Web Core (bee_router)

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

**Context provides:**
- `ctx.json()` / `ctx.text()` / `ctx.html()` — response output
- `ctx.redirect()` — redirect
- `ctx.abort()` — abort the request
- `ctx.session` — session access
- `ctx.params` — path parameters

### Security Detection (`security` feature)

An attack detection filter based on [security-rust](https://crates.io/crates/security-rust), covering 27 attack types including XSS, SQL injection, command injection, SSRF, and more:

```rust
use bee_rust::prelude::*;

let security = SecurityFilter::new();  // 27 个检测器全开
```

Enable it in `Cargo.toml`:
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

### Configuration Management (bee_config)

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

**KV/Cache:**
```rust
let kv = RedisStore::new("redis://localhost:6379").await?;
kv.set("key", b"value", Some(Duration::from_secs(60))).await?;
let val = kv.get("key").await?;
```

**Search Engine (planned):**
```rust
// 驱动实现计划中，当前为 trait stub
let engine = ElasticsearchEngine::new("http://localhost:9200")?;
let result = engine.search("my_index", &SearchQuery {
    q: Some("keyword".into()), ..Default::default()
}).await?;
```

**Graph Database (planned):**
```rust
// 驱动实现计划中，当前为 trait stub
let db = Neo4jDB::new("bolt://localhost:7687").await?;
let vid = db.add_vertex("Person", &[("name", "Alice")]).await?;
```

**Time Series Database (planned):**
```rust
// 驱动实现计划中，当前为 trait stub
let tsdb = InfluxDB::new("http://localhost:8086").await?;
tsdb.write_point("cpu", &[("host", "srv1")], &[("value", 0.85)], Utc::now()).await?;
```

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

### CLI Tools

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

> Note: the `pack --target` parameter is a reserved interface; the current packaging flow does not distinguish target platforms.

## Usage

### Requirements

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

### Quick Start

```bash
# 使用 CLI 创建新项目
cargo run -p bee_cli -- new hello
cd hello

# 运行开发服务器
cargo run
```

### Using in Your Project

```toml
[dependencies]
bee_rust = { git = "https://github.com/erikwang2013/bee-rust", features = ["full"] }
```

## Technical Notes

### Tech Stack

| Layer | Technology |
|----|------|
| HTTP foundation | axum 0.8 + tower 0.5 |
| Async runtime | tokio 1.x |
| Serialization | serde + serde_json |
| Template engine | tera 1.x |
| Logging backend | tracing + tracing-subscriber |
| CLI | clap 4 |
| Config parsing | toml / serde_yaml / custom INI |
| Error handling | thiserror |
| Procedural macros | syn + quote + proc-macro2 |

### Design Patterns

| Pattern | Application |
|------|------|
| **Builder** | Logger, Router, QuerySet |
| **Trait abstraction** | Cache, KvStore, SearchEngine, GraphDB, TimeSeriesDB |
| **Derive macros** | `#[derive(Model)]`, `#[derive(Config)]` |
| **Feature Gate** | driver implementations compiled on demand (redis, memcached, elasticsearch, etc.) |
| **Filter Chain** | request filter chain, modeled after Beego Filter |

### Crate List

| Crate | Function | Beego Counterpart |
|-------|------|-----------|
| `bee_rust` | Meta crate, unified entry point | — |
| `bee_router` | Routing + controllers + Context + filters | `server/web`, `context` |
| `bee_orm` | ORM + QuerySet + Migration | `client/orm` |
| `bee_kv` | Unified KV/Cache abstraction | `client/cache` (extended) |
| `bee_search` | Search/analytics engine | — (new) |
| `bee_graph` | Graph database | — (new) |
| `bee_tsdb` | Time series database | — (new) |
| `bee_config` | Config management + hot reload | `client/config` |
| `bee_cache` | Cache abstraction | `client/cache` |
| `bee_session` | Session management | `server/web/session` |
| `bee_logs` | Logging | `logs` |
| `bee_template` | Template rendering | — (enhanced) |
| `bee_cli` | CLI tools | `bee` tool |

### Test Coverage

All 68 tests in the repo pass:

| Crate | Tests |
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

## Support

If this project helps you, feel free to scan the QR codes to donate. Thank you!

**WeChat Pay**

<img src="docs/weixinpay.png" width="160" height="175" alt="WeChat Pay">

**Alipay**

<img src="docs/alipay.png" width="160" height="175" alt="Alipay">

### License

Apache-2.0
