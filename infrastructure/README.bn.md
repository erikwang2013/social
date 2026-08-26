# Beerust

**语言 / Languages:** [中文](README.md) · [English](README.en.md) · [한국어](README.ko.md) · [Русский](README.ru.md) · [Deutsch](README.de.md) · [Français](README.fr.md) · [Español](README.es.md) · [Português](README.pt.md) · [हिन्दी](README.hi.md) · [العربية](README.ar.md) · [বাংলা](README.bn.md) · [Bahasa Indonesia](README.id.md) · [日本語](README.ja.md)

<!-- Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz -->

Beerust হলো Rust ভাষায় লেখা একটি প্রোডাকশন-গ্রেড ওয়েব ফ্রেমওয়ার্ক। এর ডিজাইন দর্শন Go-র Beego ফ্রেমওয়ার্ক থেকে নেওয়া, যা Rust-এর প্রচলিত trait, macro ও টাইপ সিস্টেম দিয়ে নতুন করে প্রকাশ করা হয়েছে।

## ডিজাইন লক্ষ্য

| লক্ষ্য | মেট্রিক |
|------|------|
| **ডেভেলপার অভিজ্ঞতা** | `bee-rust new` থেকে প্রথম অনুরোধ < ৩০ সেকেন্ড |
| **পারফরম্যান্স** | কন্ট্রোলার স্তরের ওভারহেড < ৫% (খালি axum-এর তুলনায়), P99 রাউটিং লেটেন্সি < 100µs |
| **কম্পাইল গতি** | মেটা crate-এর পূর্ণ কম্পাইল < ৬০ সেকেন্ড (release), ইনক্রিমেন্টাল কম্পাইল < ৫ সেকেন্ড |
| **বাইনারি আকার** | ন্যূনতম অ্যাপ (শুধু router) < 5MB (strip + LTO) |
| **নিরাপত্তা** | ০ unsafe ব্যবসায়িক কোড; সমস্ত FFI আলাদা `*-sys` crates-এ আবদ্ধ |
| **সামঞ্জস্য** | MSRV Rust 1.80+, stable অনুসরণ করে |

## ডিজাইন নীতি

1. **Beego দর্শন, Rust অভিব্যক্তি** — MVC, নেমস্পেস, ফিল্টার চেইন trait + macro দিয়ে বাস্তবায়িত
2. **ক্রমবর্ধমান উন্নয়ন** — ন্যূনতম কোর শুধু axum + tokio-র উপর নির্ভরশীল, বাকি সব feature gate
3. **অস্পষ্টের চেয়ে স্পষ্ট** — রুট নিবন্ধন, মডেল ম্যাপিং, মিডলওয়্যার ক্রম সব কোডে স্পষ্টভাবে ঘোষিত
4. **শূন্য-খরচ অ্যাবস্ট্রাকশন** — trait স্ট্যাটিক ডিসপ্যাচ, macro কম্পাইল-সময় সম্প্রসারণ, ভার্চুয়াল ফাংশন ওভারহেড নেই
5. **স্টোরেজ ইঞ্জিন স্বাধীনতা** — প্রতিটি ইঞ্জিন trait আলাদাভাবে বদলানো যায়, উপরের ব্যবসায়িক স্তরে প্রভাব নেই
6. **অন্তর্নির্মিত পর্যবেক্ষণযোগ্যতা** — tracing + metrics ইন্সট্রুমেন্টেশন পুরো ফ্রেমওয়ার্ক জুড়ে, গঠনমূলক লগিং ডিফল্টভাবে চালু

## আর্কিটেকচার ডিজাইন

### Crate টপোলজি

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

### আর্কিটেকচার ডায়াগ্রাম

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

### Crate নির্ভরতা

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

### সমর্থিত ডেটাবেস

| শ্রেণী | ডেটাবেস | সংশ্লিষ্ট Crate | Feature Flag |
|------|--------|-----------|-------------|
| **রিলেশনাল** | SQLite | `bee_orm` | `sqlite` |
| | PostgreSQL | `bee_orm` | `postgres` |
| | MySQL | `bee_orm` | `mysql` |
| | TiDB | `bee_orm` | `mysql` |
| **KV / ক্যাশ** | Redis | `bee_kv` / `bee_cache` | `redis` |
| | Memcached | `bee_kv` / `bee_cache` | `memcache` |
| **সার্চ / বিশ্লেষণ** | Elasticsearch | `bee_search` | `elasticsearch` |
| | OpenSearch | `bee_search` | `opensearch` |
| | ClickHouse | `bee_search` | `clickhouse` |
| **গ্রাফ ডেটাবেস** | Neo4j | `bee_graph` | `neo4j` |
| | NebulaGraph | `bee_graph` | `nebulagraph` |
| | ArangoDB | `bee_graph` | `arangodb` |
| **টাইম-সিরিজ** | InfluxDB | `bee_tsdb` | `influxdb` |
| | Apache IoTDB | `bee_tsdb` | `iotdb` |
| | QuestDB | `bee_tsdb` | `questdb` |

### অনুরোধ ফিল্টার চেইন

```
请求 → [SecurityFilter 攻击检测] → [Session 恢复] → [参数验证] → [prepare 钩子] → [handle 处理] → [finish 钩子] → 响应
                  ↓ 任何环节可中断（类似 Beego 的 Abort）
```

## ফিচার সংক্ষিপ্ত বিবরণ

### ওয়েব কোর (bee_router)

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

**Context যা প্রদান করে:**
- `ctx.json()` / `ctx.text()` / `ctx.html()` — রেসপন্স আউটপুট
- `ctx.redirect()` — রিডাইরেক্ট
- `ctx.abort()` — অনুরোধ বাতিল
- `ctx.session` — সেশন অ্যাক্সেস
- `ctx.params` — পাথ প্যারামিটার

### নিরাপত্তা সনাক্তকরণ (`security` feature)

[security-rust](https://crates.io/crates/security-rust)-ভিত্তিক একটি আক্রমণ সনাক্তকরণ ফিল্টার, যা XSS, SQL ইনজেকশন, কমান্ড ইনজেকশন, SSRF সহ ২৭ ধরনের আক্রমণ কভার করে:

```rust
use bee_rust::prelude::*;

let security = SecurityFilter::new();  // 27 个检测器全开
```

`Cargo.toml`-এ সক্রিয় করুন:
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

### কনফিগারেশন ব্যবস্থাপনা (bee_config)

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

### স্টোরেজ ইঞ্জিন

**KV/ক্যাশ:**
```rust
let kv = RedisStore::new("redis://localhost:6379").await?;
kv.set("key", b"value", Some(Duration::from_secs(60))).await?;
let val = kv.get("key").await?;
```

**সার্চ ইঞ্জিন (পরিকল্পিত):**
```rust
// 驱动实现计划中，当前为 trait stub
let engine = ElasticsearchEngine::new("http://localhost:9200")?;
let result = engine.search("my_index", &SearchQuery {
    q: Some("keyword".into()), ..Default::default()
}).await?;
```

**গ্রাফ ডেটাবেস (পরিকল্পিত):**
```rust
// 驱动实现计划中，当前为 trait stub
let db = Neo4jDB::new("bolt://localhost:7687").await?;
let vid = db.add_vertex("Person", &[("name", "Alice")]).await?;
```

**টাইম-সিরিজ ডেটাবেস (পরিকল্পিত):**
```rust
// 驱动实现计划中，当前为 trait stub
let tsdb = InfluxDB::new("http://localhost:8086").await?;
tsdb.write_point("cpu", &[("host", "srv1")], &[("value", 0.85)], Utc::now()).await?;
```

### সেশন

```rust
let cache = Arc::new(MemoryCache::new());
let mut session = Session::new(cache, Duration::from_secs(3600));
session.set("user_id", &"123")?;
let uid: String = session.get("user_id")?.unwrap();
```

### লগিং

```rust
Logger::new()
    .level(Level::INFO)
    .output(Output::MultiFile("logs/"))
    .async_()
    .init()?;
```

### টেমপ্লেট

```rust
let engine = TemplateEngine::new("views/")?;
let result = engine.render("hello.html", &context! { name: &"World" })?;
// → "Hello, World!"
```

### CLI টুলস

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

> দ্রষ্টব্য: `pack --target` প্যারামিটারটি একটি সংরক্ষিত ইন্টারফেস; বর্তমান প্যাকেজিং প্রক্রিয়া টার্গেট প্ল্যাটফর্মের পার্থক্য করে না।

## ব্যবহারের ধাপ

### পরিবেশের প্রয়োজনীয়তা

- Rust 1.80+
- Cargo

### ইনস্টলেশন

```bash
# 克隆项目
git clone https://github.com/erikwang2013/bee-rust.git
cd bee-rust

# 编译
cargo build --workspace

# 运行测试
cargo test --workspace
```

### দ্রুত শুরু

```bash
# 使用 CLI 创建新项目
cargo run -p bee_cli -- new hello
cd hello

# 运行开发服务器
cargo run
```

### আপনার প্রজেক্টে ব্যবহার

```toml
[dependencies]
bee_rust = { git = "https://github.com/erikwang2013/bee-rust", features = ["full"] }
```

## প্রযুক্তিগত বিবরণ

### টেকনোলজি স্ট্যাক

| স্তর | প্রযুক্তি |
|----|------|
| HTTP ভিত্তি | axum 0.8 + tower 0.5 |
| অ্যাসিঙ্ক রানটাইম | tokio 1.x |
| সিরিয়ালাইজেশন | serde + serde_json |
| টেমপ্লেট ইঞ্জিন | tera 1.x |
| লগিং স্তর | tracing + tracing-subscriber |
| CLI | clap 4 |
| কনফিগ পার্সিং | toml / serde_yaml / নিজস্ব INI |
| এরর হ্যান্ডলিং | thiserror |
| প্রসিডিউরাল ম্যাক্রো | syn + quote + proc-macro2 |

### ডিজাইন প্যাটার্ন

| প্যাটার্ন | প্রয়োগ |
|------|------|
| **Builder** | Logger, Router, QuerySet |
| **Trait অ্যাবস্ট্রাকশন** | Cache, KvStore, SearchEngine, GraphDB, TimeSeriesDB |
| **ডিরাইভ ম্যাক্রো** | `#[derive(Model)]`, `#[derive(Config)]` |
| **Feature Gate** | ড্রাইভার বাস্তবায়ন প্রয়োজন অনুযায়ী কম্পাইল হয় (redis, memcached, elasticsearch ইত্যাদি) |
| **Filter Chain** | অনুরোধ ফিল্টার চেইন, Beego Filter-এর আদলে |

### Crate তালিকা

| Crate | কার্যকারিতা | Beego সমতুল্য |
|-------|------|-----------|
| `bee_rust` | মেটা crate, একীভূত প্রবেশ বিন্দু | — |
| `bee_router` | রাউটিং + কন্ট্রোলার + Context + ফিল্টার | `server/web`, `context` |
| `bee_orm` | ORM + QuerySet + Migration | `client/orm` |
| `bee_kv` | KV/ক্যাশ একীভূত অ্যাবস্ট্রাকশন | `client/cache` (বর্ধিত) |
| `bee_search` | সার্চ/বিশ্লেষণ ইঞ্জিন | — (নতুন) |
| `bee_graph` | গ্রাফ ডেটাবেস | — (নতুন) |
| `bee_tsdb` | টাইম-সিরিজ ডেটাবেস | — (নতুন) |
| `bee_config` | কনফিগ ব্যবস্থাপনা + হট রিলোড | `client/config` |
| `bee_cache` | ক্যাশ অ্যাবস্ট্রাকশন | `client/cache` |
| `bee_session` | সেশন ব্যবস্থাপনা | `server/web/session` |
| `bee_logs` | লগিং | `logs` |
| `bee_template` | টেমপ্লেট রেন্ডারিং | — (উন্নত) |
| `bee_cli` | CLI টুল | `bee` টুল |

### টেস্ট কভারেজ

রিপোজিটরির ৬৮টি টেস্টই পাস:

| Crate | টেস্ট সংখ্যা |
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

## সমর্থন

প্রকল্পটি যদি আপনার উপকারে আসে, QR কোড স্ক্যান করে দান করে সহায়তা করুন। ধন্যবাদ!

**উইচ্যাট পে**

<img src="docs/weixinpay.png" width="160" height="175" alt="WeChat Pay">

**আলিপে**

<img src="docs/alipay.png" width="160" height="175" alt="Alipay">

### লাইসেন্স

Apache-2.0
