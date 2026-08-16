<!-- Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz -->
# Beerust

[English](README.en.md)

Beerust是一个 Rust 语言的生产级 Web 框架，设计哲学源自 Go 的 Beego框架，用 Rust 惯用的 trait、macro、类型系统重新表达。

## 设计目标

| 目标 | 指标 |
|------|------|
| **开发体验** | 从 `bee-rust new` 到首请求 < 30 秒 |
| **性能** | 控制器层开销 < 5%（对比裸 axum），P99 路由延迟 < 100µs |
| **编译速度** | 元 crate 全量编译 < 60s（release），增量编译 < 5s |
| **二进制体积** | 最小应用（仅 router）< 5MB（strip + LTO） |
| **安全性** | 0 unsafe 业务代码；所有 FFI 封装在独立 `*-sys` crate |
| **兼容性** | Rust 1.80+ MSRV，跟踪 stable |

## 设计原则

1. **Beego 哲学，Rust 表达** — MVC、命名空间、过滤器链，用 trait + macro 实现
2. **渐进增强** — 最小核心只依赖 axum + tokio，其他全部 feature gate
3. **显式优于隐式** — 路由注册、模型映射、中间件顺序全部代码显式声明
4. **零成本抽象** — trait 静态分发，macro 编译期展开，不引入虚函数开销
5. **存储引擎独立性** — 每个引擎 trait 可单独替换实现，不影响上层业务
6. **观测性内置** — tracing + metrics 埋点全框架覆盖，结构化日志默认开启

## 架构设计

### Crate 拓扑

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

### 架构图

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

### Crate 依赖关系

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

### 支持的数据库

| 类别 | 数据库 | 对应 Crate | Feature Flag |
|------|--------|-----------|-------------|
| **关系型** | SQLite | `bee_orm` | `sqlite` |
| | PostgreSQL | `bee_orm` | `postgres` |
| | MySQL | `bee_orm` | `mysql` |
| | TiDB | `bee_orm` | `mysql` |
| **KV / 缓存** | Redis | `bee_kv` / `bee_cache` | `redis` |
| | Memcached | `bee_kv` / `bee_cache` | `memcache` |
| **搜索 / 分析** | Elasticsearch | `bee_search` | `elasticsearch` |
| | OpenSearch | `bee_search` | `opensearch` |
| | ClickHouse | `bee_search` | `clickhouse` |
| **图数据库** | Neo4j | `bee_graph` | `neo4j` |
| | NebulaGraph | `bee_graph` | `nebulagraph` |
| | ArangoDB | `bee_graph` | `arangodb` |
| **时序数据库** | InfluxDB | `bee_tsdb` | `influxdb` |
| | Apache IoTDB | `bee_tsdb` | `iotdb` |
| | QuestDB | `bee_tsdb` | `questdb` |

### 请求过滤器链

```
请求 → [SecurityFilter 攻击检测] → [Session 恢复] → [参数验证] → [prepare 钩子] → [handle 处理] → [finish 钩子] → 响应
                  ↓ 任何环节可中断（类似 Beego 的 Abort）
```

## 功能介绍

### Web 核心（bee_router）

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

**Context 提供：**
- `ctx.json()` / `ctx.text()` / `ctx.html()` — 响应输出
- `ctx.redirect()` — 重定向
- `ctx.abort()` — 中断请求
- `ctx.session` — 会话访问
- `ctx.params` — 路径参数

### 安全检测（`security` feature）

基于 [security-rust](https://crates.io/crates/security-rust) 的攻击检测过滤器，覆盖 XSS、SQL 注入、命令注入、SSRF 等 27 种攻击类型：

```rust
use bee_rust::prelude::*;

let security = SecurityFilter::new();  // 27 个检测器全开
```

在 `Cargo.toml` 中启用：
```toml
bee_rust = { features = ["security"] }
```

### ORM（bee_orm）

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

### 配置管理（bee_config）

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

### 存储引擎

**KV/Cache：**
```rust
let kv = RedisStore::new("redis://localhost:6379").await?;
kv.set("key", b"value", Some(Duration::from_secs(60))).await?;
let val = kv.get("key").await?;
```

**搜索引擎（计划中）：**
```rust
// 驱动实现计划中，当前为 trait stub
let engine = ElasticsearchEngine::new("http://localhost:9200")?;
let result = engine.search("my_index", &SearchQuery {
    q: Some("keyword".into()), ..Default::default()
}).await?;
```

**图数据库（计划中）：**
```rust
// 驱动实现计划中，当前为 trait stub
let db = Neo4jDB::new("bolt://localhost:7687").await?;
let vid = db.add_vertex("Person", &[("name", "Alice")]).await?;
```

**时序数据库（计划中）：**
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

### 日志

```rust
Logger::new()
    .level(Level::INFO)
    .output(Output::MultiFile("logs/"))
    .async_()
    .init()?;
```

### 模板

```rust
let engine = TemplateEngine::new("views/")?;
let result = engine.render("hello.html", &context! { name: &"World" })?;
// → "Hello, World!"
```

### CLI 工具

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

> 说明：`pack --target` 参数为预留接口，当前打包流程不区分目标平台。

## 使用步骤

### 环境要求

- Rust 1.80+
- Cargo

### 安装

```bash
# 克隆项目
git clone https://github.com/erikwang2013/bee-rust.git
cd bee-rust

# 编译
cargo build --workspace

# 运行测试
cargo test --workspace
```

### 快速开始

```bash
# 使用 CLI 创建新项目
cargo run -p bee_cli -- new hello
cd hello

# 运行开发服务器
cargo run
```

### 在项目中使用

```toml
[dependencies]
bee_rust = { git = "https://github.com/erikwang2013/bee-rust", features = ["full"] }
```

## 技术说明

### 技术栈

| 层 | 技术 |
|----|------|
| HTTP 基座 | axum 0.8 + tower 0.5 |
| 异步运行时 | tokio 1.x |
| 序列化 | serde + serde_json |
| 模板引擎 | tera 1.x |
| 日志底层 | tracing + tracing-subscriber |
| CLI | clap 4 |
| 配置解析 | toml / serde_yaml / 自研 INI |
| 错误处理 | thiserror |
| 过程宏 | syn + quote + proc-macro2 |

### 设计模式

| 模式 | 应用 |
|------|------|
| **Builder** | Logger, Router, QuerySet |
| **Trait 抽象** | Cache, KvStore, SearchEngine, GraphDB, TimeSeriesDB |
| **派生宏** | `#[derive(Model)]`, `#[derive(Config)]` |
| **Feature Gate** | 驱动实现按需编译（redis, memcached, elasticsearch 等） |
| **Filter Chain** | 请求过滤器链，对标 Beego Filter |

### Crate 清单

| Crate | 功能 | 对标 Beego |
|-------|------|-----------|
| `bee_rust` | 元 crate，统一入口 | — |
| `bee_router` | 路由 + 控制器 + Context + 过滤器 | `server/web`, `context` |
| `bee_orm` | ORM + QuerySet + Migration | `client/orm` |
| `bee_kv` | KV/Cache 统一抽象 | `client/cache`（扩展） |
| `bee_search` | 搜索/分析引擎 | —（新增） |
| `bee_graph` | 图数据库 | —（新增） |
| `bee_tsdb` | 时序数据库 | —（新增） |
| `bee_config` | 配置管理 + 热更新 | `client/config` |
| `bee_cache` | 缓存抽象 | `client/cache` |
| `bee_session` | Session 管理 | `server/web/session` |
| `bee_logs` | 日志 | `logs` |
| `bee_template` | 模板渲染 | —（增强） |
| `bee_cli` | CLI 工具 | `bee` 工具 |

### 测试覆盖

全仓 68 个测试通过：

| Crate | 测试数 |
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

## 欢迎支持

如果这个项目对你有帮助，欢迎扫描二维码打赏支持，谢谢！

**微信支付**

<img src="docs/weixinpay.png" width="160" height="175" alt="微信支付">

**支付宝**

<img src="docs/alipay.png" width="160" height="175" alt="支付宝">

### 许可证

Apache-2.0
