<!-- Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz -->
# bee-rust Framework — Architecture Design

> 日期：2026-07-29 | 状态：已确认

## 概述

bee-rust 是一个 Rust 语言的生产级 Web 框架，设计哲学源自 Go 的 Beego 框架，用 Rust 惯用的 trait / macro / 类型系统重新表达。

**核心目标：**

| 目标 | 指标 |
|------|------|
| 开发体验 | 从 `bee-rust new` 到首请求 < 30 秒 |
| 性能 | 控制器层开销 < 5%（对比裸 axum），P99 路由延迟 < 100µs |
| 编译速度 | 元 crate 全量编译 < 60s（release），增量编译 < 5s |
| 二进制体积 | 最小应用（仅 router）< 5MB（strip + LTO） |
| 安全性 | 0 unsafe 业务代码；所有 FFI 封装在独立 `*-sys` crate |
| 兼容性 | Rust 1.80+ MSRV，跟踪 stable |

**设计原则：**

1. **Beego 哲学，Rust 表达** — MVC、命名空间、过滤器链，用 trait + macro 实现
2. **渐进增强** — 最小核心只依赖 axum + tokio，其他全部 feature gate
3. **显式优于隐式** — 路由注册、模型映射、中间件顺序全部代码显式声明
4. **零成本抽象** — trait 静态分发，macro 编译期展开，不引入虚函数开销
5. **存储引擎独立性** — 每个引擎 trait 可单独替换实现，不影响上层业务
6. **观测性内置** — tracing + metrics 埋点全框架覆盖，结构化日志默认开启

---

## 架构：模块化 Crate 生态

选择方案 B — 独立 crate 家族，核心 crate 统一 re-export。

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
bee_cli/            # CLI — 脚手架/代码生成/热重载/迁移
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

---

## Web 核心层（bee_router）

基于 axum/tower，提供 Beego 风格的控制器和路由抽象。

### Controller trait

```rust
#[axum::async_trait]
pub trait Controller: Send + Sync + 'static {
    async fn handle(&self, ctx: &mut Context) -> Result<(), Error>;
    async fn prepare(&self, _ctx: &mut Context) -> Result<(), Error> { Ok(()) }
    async fn finish(&self, _ctx: &mut Context) -> Result<(), Error> { Ok(()) }
}
```

### Context

```rust
pub struct Context {
    pub request:   Request<Body>,
    pub params:    PathParams,
    pub query:     QueryMap,
    pub session:   Session,
    pub config:    Arc<AppConfig>,
    pub logger:    Logger,
    pub templates: TemplateEngine,
}

impl Context {
    pub fn json<T: Serialize>(&mut self, data: &T) -> Result<(), Error>;
    pub fn text(&mut self, body: &str) -> Result<(), Error>;
    pub fn html(&mut self, template: &str, data: &dyn Serialize) -> Result<(), Error>;
    pub fn redirect(&mut self, url: &str) -> Result<(), Error>;
    pub fn abort(&mut self, status: StatusCode, msg: &str);
}
```

### 路由注册

```rust
// 自动路由
Router::new()
    .auto_route::<UserController>()
    .auto_route::<AdminController>()

// 命名空间 + 显式路由 + 中间件
Router::new()
    .ns("/api/v1", |ns| {
        ns.get("/users", user_controller.list)
          .post("/users", user_controller.create)
          .group("/admin", |admin| {
              admin.get("/dashboard", admin_controller.index);
          });
    })
    .with_middleware(tracing_layer)
    .with_middleware(session_layer);
```

### 过滤器链（Request → Response）

```
Request → [Session恢复] → [参数验证] → [prepare] → [handle] → [finish] → Response
              ↓ 任何环节可中断
```

---

## ORM 层（bee_orm）

派生宏驱动的 ORM，对标 Beego ORM。

### Model 定义

```rust
#[derive(Model)]
#[bee(table = "users", engine = "innodb")]
pub struct User {
    #[bee(pk, auto)]
    pub id: i64,
    #[bee(unique, size = 64)]
    pub name: String,
    #[bee(default = 0)]
    pub age: i32,
    #[bee(null)]
    pub email: Option<String>,
    #[bee(auto_now_add)]
    pub created_at: NaiveDateTime,
    #[bee(auto_now)]
    pub updated_at: NaiveDateTime,
}
```

### QuerySet

```rust
// 链式构建
let users: Vec<User> = User::query()
    .filter(User::age.gt(18))
    .filter(User::name.like("%张%"))
    .order_by(User::created_at.desc())
    .limit(20).offset(0)
    .all(&conn).await?;

// 关联
let posts: Vec<(Post, User)> = Post::query()
    .with_related::<User>()
    .all(&conn).await?;

// 原生 SQL
let users = User::raw("SELECT * FROM users WHERE age > ?", (18,))
    .all(&conn).await?;
```

### Migration

```rust
#[derive(Migration)]
struct CreateUsersTable;

#[async_trait]
impl MigrationTrait for CreateUsersTable {
    fn up(&self) -> Schema {
        Schema::create_table("users")
            .column("id",   ColumnType::BigInt.pk().auto_increment())
            .column("name", ColumnType::String(64).unique().not_null())
            .column("age",  ColumnType::Int.default_value(0))
            .column("email",ColumnType::String(255).null())
            .timestamps()
    }
    fn down(&self) -> Schema {
        Schema::drop_table("users")
    }
}
```

### 数据库后端

SQLite / PostgreSQL / MySQL / TiDB，通过 feature gate 切换。

---

## 存储引擎层

### bee_kv — KV/Cache 统一抽象

```rust
#[async_trait]
pub trait KvStore: Send + Sync {
    async fn get(&self, key: &str) -> Result<Option<Vec<u8>>>;
    async fn set(&self, key: &str, val: &[u8], ttl: Option<Duration>) -> Result<()>;
    async fn del(&self, keys: &[&str]) -> Result<u64>;
    async fn exists(&self, keys: &[&str]) -> Result<u64>;
    async fn incr(&self, key: &str, delta: i64) -> Result<i64>;
    async fn expire(&self, key: &str, ttl: Duration) -> Result<bool>;
    async fn mget(&self, keys: &[&str]) -> Result<Vec<Option<Vec<u8>>>>;
    async fn mset(&self, kvs: &[(&str, &[u8])]) -> Result<()>;
    async fn publish(&self, channel: &str, msg: &[u8]) -> Result<u64>;
    async fn subscribe(&self, channels: &[&str]) -> Result<MessageStream>;
}
```

**驱动：** `RedisStore`（feature = "redis"） | `MemcachedStore`（feature = "memcached"）

### bee_search — 搜索/分析引擎

```rust
#[async_trait]
pub trait SearchEngine: Send + Sync {
    async fn create_index(&self, name: &str, mapping: &Mapping) -> Result<()>;
    async fn delete_index(&self, name: &str) -> Result<()>;
    async fn index(&self, index: &str, id: &str, doc: &Document) -> Result<()>;
    async fn bulk_index(&self, index: &str, docs: &[(String, Document)]) -> Result<BulkResult>;
    async fn get(&self, index: &str, id: &str) -> Result<Option<Document>>;
    async fn delete(&self, index: &str, id: &str) -> Result<()>;
    async fn search(&self, index: &str, query: &SearchQuery) -> Result<SearchResult>;
    async fn scroll(&self, index: &str, query: &SearchQuery, ttl: Duration) -> Result<ScrollHandle>;
    async fn aggregate(&self, index: &str, aggs: &Aggregations) -> Result<AggResult>;
}
```

**驱动：** `ElasticsearchEngine` | `OpensearchEngine` | `ClickhouseEngine`（含批量写入/原生SQL扩展方法）

### bee_graph — 图数据库

```rust
#[async_trait]
pub trait GraphDB: Send + Sync {
    async fn add_vertex(&self, label: &str, props: &Properties) -> Result<VertexId>;
    async fn get_vertex(&self, id: &VertexId) -> Result<Option<Vertex>>;
    async fn update_vertex(&self, id: &VertexId, props: &Properties) -> Result<()>;
    async fn delete_vertex(&self, id: &VertexId) -> Result<()>;
    async fn add_edge(&self, from: &VertexId, to: &VertexId, label: &str, props: &Properties) -> Result<EdgeId>;
    async fn traverse(&self, start: &VertexId, traversal: &Traversal) -> Result<PathResult>;
    async fn query(&self, query: &str, params: &Params) -> Result<QueryResult>;
}
```

**驱动：** `Neo4jDB`（bolt） | `NebulaGraphDB`（ngql） | `ArangoDB`（HTTP API）

### bee_tsdb — 时序数据库

```rust
#[async_trait]
pub trait TimeSeriesDB: Send + Sync {
    async fn write_point(&self, measurement: &str, tags: &Tags, fields: &Fields, ts: Timestamp) -> Result<()>;
    async fn write_batch(&self, points: &[Point]) -> Result<()>;
    async fn query_range(&self, measurement: &str, range: (Timestamp, Timestamp), filter: &TagFilter, agg: Option<Aggregation>) -> Result<TimeSeries>;
    async fn create_continuous_query(&self, name: &str, spec: &CQSpec) -> Result<()>;
}
```

**驱动：** `InfluxDB` | `ApacheIoTDB`（Thrift） | `QuestDB`（ILP + PG Wire）

---

## 配置 & 缓存 & 日志

### bee_config

```rust
#[derive(Config)]
#[config(file = "conf/app.conf", format = "ini")]
pub struct AppConfig {
    pub app_name: String,
    pub http_port: u16,
    pub run_mode: RunMode,
    #[config(section = "database")]
    pub db: DbConfig,
}

let config = AppConfig::load()?;
config.watch()?;  // 热更新
```

### bee_cache

```rust
#[async_trait]
pub trait Cache: Send + Sync {
    async fn get(&self, key: &str) -> Option<Vec<u8>>;
    async fn set(&self, key: &str, value: &[u8], ttl: Duration) -> Result<()>;
    async fn delete(&self, key: &str) -> Result<()>;
    async fn incr(&self, key: &str) -> Result<i64>;
}
```

后端：Memory | Redis | Memcache

### bee_logs

基于 tracing 生态，兼容 Beego 日志级别习惯。

```rust
Logger::new()
    .level(Level::Debug)
    .output(Output::MultiFile("logs/app.log"))
    .async_()
    .init()?;
```

---

## Session & Template

### bee_session

```rust
ctx.session.set("user_id", "123")?;
let uid: String = ctx.session.get("user_id")?;
ctx.session.delete("user_id")?;
```

后端：Memory | Redis | Cookie | Database

### bee_template

基于 tera，提供 `context!` 宏。

```rust
ctx.html("users/profile", &context! { user: &user, posts: &posts })?;
```

---

## CLI 工具（bee-rust 命令）

```bash
bee-rust new my-app                        # 创建项目
bee-rust generate controller user          # 生成控制器
bee-rust generate model user --fields "name:string,age:int"  # 生成模型
bee-rust run --watch                       # 开发服务器（热重载）
bee-rust migrate up                        # 数据库迁移
bee-rust migrate down
bee-rust pack --target linux/x86_64        # 打包部署
```

---

## Crate 清单

| Crate | 功能 | 对标 Beego |
|-------|------|-----------|
| bee_rust | 元 crate，统一入口 | — |
| bee_router | 路由 + 控制器 + Context + 过滤器 | `server/web`, `context` |
| bee_orm | ORM | `client/orm` |
| bee_kv | KV/Cache 统一抽象 | `client/cache`（扩展） |
| bee_search | 搜索/分析引擎 | —（新增） |
| bee_graph | 图数据库 | —（新增） |
| bee_tsdb | 时序数据库 | —（新增） |
| bee_config | 配置管理 | `client/config` |
| bee_cache | 缓存抽象 | `client/cache` |
| bee_session | Session 管理 | `server/web/session` |
| bee_logs | 日志 | `logs` |
| bee_template | 模板渲染 | —（增强，基于 tera） |
| bee_cli | CLI 工具 | `bee` 工具 |
