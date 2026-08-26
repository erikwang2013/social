# Beerust

**语言 / Languages:** [中文](README.md) · [English](README.en.md) · [한국어](README.ko.md) · [Русский](README.ru.md) · [Deutsch](README.de.md) · [Français](README.fr.md) · [Español](README.es.md) · [Português](README.pt.md) · [हिन्दी](README.hi.md) · [العربية](README.ar.md) · [বাংলা](README.bn.md) · [Bahasa Indonesia](README.id.md) · [日本語](README.ja.md)

<!-- Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz -->

Beerust एक Rust भाषा में लिखा गया प्रोडक्शन-ग्रेड वेब फ्रेमवर्क है। इसका डिज़ाइन दर्शन Go के Beego फ्रेमवर्क से लिया गया है, जिसे Rust के प्रचलित trait, macro और टाइप सिस्टम के माध्यम से पुनः व्यक्त किया गया है।

## डिज़ाइन लक्ष्य

| लक्ष्य | मानदंड |
|------|------|
| **डेवलपर अनुभव** | `bee-rust new` से पहले अनुरोध तक < 30 सेकंड |
| **प्रदर्शन** | कंट्रोलर परत का ओवरहेड < 5% (बिना axum की तुलना में), P99 रूटिंग विलंब < 100µs |
| **संकलन गति** | मेटा crate का पूर्ण संकलन < 60s (release), इन्क्रीमेंटल संकलन < 5s |
| **बाइनरी आकार** | न्यूनतम ऐप (केवल router) < 5MB (strip + LTO) |
| **सुरक्षा** | 0 unsafe व्यावसायिक कोड; सभी FFI को अलग `*-sys` crates में समाहित |
| **संगतता** | MSRV Rust 1.80+, stable का अनुसरण |

## डिज़ाइन सिद्धांत

1. **Beego दर्शन, Rust अभिव्यक्ति** — MVC, नेमस्पेस, फ़िल्टर चेन, trait + macro से कार्यान्वित
2. **प्रगतिशील वृद्धि** — न्यूनतम कोर केवल axum + tokio पर निर्भर, बाकी सब feature gate
3. **अस्पष्ट से अधिक स्पष्ट** — रूट पंजीकरण, मॉडल मैपिंग, मिडलवेयर क्रम सभी कोड में स्पष्ट रूप से घोषित
4. **शून्य-लागत अमूर्तन** — trait स्थैतिक डिस्पैच, macro संकलन-समय विस्तार, वर्चुअल फ़ंक्शन ओवरहेड नहीं
5. **स्टोरेज इंजन स्वतंत्रता** — प्रत्येक इंजन trait को अलग से बदला जा सकता है, ऊपरी व्यवसाय पर प्रभाव नहीं
6. **अंतर्निहित अवलोकन क्षमता** — tracing + metrics इंस्ट्रूमेंटेशन पूरे फ्रेमवर्क में, संरचित लॉगिंग डिफ़ॉल्ट रूप से चालू

## आर्किटेक्चर डिज़ाइन

### Crate टोपोलॉजी

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

### आर्किटेक्चर आरेख

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

### Crate निर्भरताएँ

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

### समर्थित डेटाबेस

| श्रेणी | डेटाबेस | संबंधित Crate | Feature Flag |
|------|--------|-----------|-------------|
| **रिलेशनल** | SQLite | `bee_orm` | `sqlite` |
| | PostgreSQL | `bee_orm` | `postgres` |
| | MySQL | `bee_orm` | `mysql` |
| | TiDB | `bee_orm` | `mysql` |
| **KV / कैश** | Redis | `bee_kv` / `bee_cache` | `redis` |
| | Memcached | `bee_kv` / `bee_cache` | `memcache` |
| **खोज / विश्लेषण** | Elasticsearch | `bee_search` | `elasticsearch` |
| | OpenSearch | `bee_search` | `opensearch` |
| | ClickHouse | `bee_search` | `clickhouse` |
| **ग्राफ डेटाबेस** | Neo4j | `bee_graph` | `neo4j` |
| | NebulaGraph | `bee_graph` | `nebulagraph` |
| | ArangoDB | `bee_graph` | `arangodb` |
| **टाइम-सीरीज़** | InfluxDB | `bee_tsdb` | `influxdb` |
| | Apache IoTDB | `bee_tsdb` | `iotdb` |
| | QuestDB | `bee_tsdb` | `questdb` |

### अनुरोध फ़िल्टर चेन

```
请求 → [SecurityFilter 攻击检测] → [Session 恢复] → [参数验证] → [prepare 钩子] → [handle 处理] → [finish 钩子] → 响应
                  ↓ 任何环节可中断（类似 Beego 的 Abort）
```

## सुविधा अवलोकन

### वेब कोर (bee_router)

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

**Context प्रदान करता है:**
- `ctx.json()` / `ctx.text()` / `ctx.html()` — प्रतिक्रिया आउटपुट
- `ctx.redirect()` — पुनर्निर्देशन
- `ctx.abort()` — अनुरोध रोकना
- `ctx.session` — सत्र पहुँच
- `ctx.params` — पथ पैरामीटर

### सुरक्षा जाँच (`security` feature)

[security-rust](https://crates.io/crates/security-rust) पर आधारित एक आक्रमण-जाँच फ़िल्टर, जो XSS, SQL इंजेक्शन, कमांड इंजेक्शन, SSRF आदि सहित 27 प्रकार के आक्रमणों को कवर करता है:

```rust
use bee_rust::prelude::*;

let security = SecurityFilter::new();  // 27 个检测器全开
```

`Cargo.toml` में सक्षम करें:
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

### कॉन्फ़िगरेशन प्रबंधन (bee_config)

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

### स्टोरेज इंजन

**KV/कैश:**
```rust
let kv = RedisStore::new("redis://localhost:6379").await?;
kv.set("key", b"value", Some(Duration::from_secs(60))).await?;
let val = kv.get("key").await?;
```

**सर्च इंजन (योजनाबद्ध):**
```rust
// 驱动实现计划中，当前为 trait stub
let engine = ElasticsearchEngine::new("http://localhost:9200")?;
let result = engine.search("my_index", &SearchQuery {
    q: Some("keyword".into()), ..Default::default()
}).await?;
```

**ग्राफ डेटाबेस (योजनाबद्ध):**
```rust
// 驱动实现计划中，当前为 trait stub
let db = Neo4jDB::new("bolt://localhost:7687").await?;
let vid = db.add_vertex("Person", &[("name", "Alice")]).await?;
```

**टाइम-सीरीज़ डेटाबेस (योजनाबद्ध):**
```rust
// 驱动实现计划中，当前为 trait stub
let tsdb = InfluxDB::new("http://localhost:8086").await?;
tsdb.write_point("cpu", &[("host", "srv1")], &[("value", 0.85)], Utc::now()).await?;
```

### सत्र

```rust
let cache = Arc::new(MemoryCache::new());
let mut session = Session::new(cache, Duration::from_secs(3600));
session.set("user_id", &"123")?;
let uid: String = session.get("user_id")?.unwrap();
```

### लॉगिंग

```rust
Logger::new()
    .level(Level::INFO)
    .output(Output::MultiFile("logs/"))
    .async_()
    .init()?;
```

### टेम्पलेट

```rust
let engine = TemplateEngine::new("views/")?;
let result = engine.render("hello.html", &context! { name: &"World" })?;
// → "Hello, World!"
```

### CLI उपकरण

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

> नोट: `pack --target` पैरामीटर एक आरक्षित इंटरफ़ेस है; वर्तमान पैकेजिंग प्रक्रिया लक्ष्य प्लेटफ़ॉर्म में अंतर नहीं करती।

## उपयोग के चरण

### आवश्यकताएँ

- Rust 1.80+
- Cargo

### स्थापना

```bash
# 克隆项目
git clone https://github.com/erikwang2013/bee-rust.git
cd bee-rust

# 编译
cargo build --workspace

# 运行测试
cargo test --workspace
```

### त्वरित आरंभ

```bash
# 使用 CLI 创建新项目
cargo run -p bee_cli -- new hello
cd hello

# 运行开发服务器
cargo run
```

### अपने प्रोजेक्ट में उपयोग

```toml
[dependencies]
bee_rust = { git = "https://github.com/erikwang2013/bee-rust", features = ["full"] }
```

## तकनीकी विवरण

### तकनीकी स्टैक

| परत | तकनीक |
|----|------|
| HTTP आधार | axum 0.8 + tower 0.5 |
| एसिंक्रोनस रनटाइम | tokio 1.x |
| सीरियलाइज़ेशन | serde + serde_json |
| टेम्पलेट इंजन | tera 1.x |
| लॉगिंग परत | tracing + tracing-subscriber |
| CLI | clap 4 |
| कॉन्फ़िग पार्सिंग | toml / serde_yaml / स्वनिर्मित INI |
| त्रुटि प्रबंधन | thiserror |
| प्रोसीजरल मैक्रो | syn + quote + proc-macro2 |

### डिज़ाइन पैटर्न

| पैटर्न | प्रयोग |
|------|------|
| **Builder** | Logger, Router, QuerySet |
| **Trait अमूर्तन** | Cache, KvStore, SearchEngine, GraphDB, TimeSeriesDB |
| **व्युत्पन्न मैक्रो** | `#[derive(Model)]`, `#[derive(Config)]` |
| **Feature Gate** | ड्राइवर कार्यान्वयन आवश्यकता पर संकलित (redis, memcached, elasticsearch आदि) |
| **Filter Chain** | अनुरोध फ़िल्टर चेन, Beego Filter के अनुरूप |

### Crate सूची

| Crate | कार्य | Beego समकक्ष |
|-------|------|-----------|
| `bee_rust` | मेटा crate, एकीकृत प्रवेश बिंदु | — |
| `bee_router` | रूटिंग + कंट्रोलर + Context + फ़िल्टर | `server/web`, `context` |
| `bee_orm` | ORM + QuerySet + Migration | `client/orm` |
| `bee_kv` | KV/कैश एकीकृत अमूर्तन | `client/cache` (विस्तारित) |
| `bee_search` | खोज/विश्लेषण इंजन | — (नया) |
| `bee_graph` | ग्राफ डेटाबेस | — (नया) |
| `bee_tsdb` | टाइम-सीरीज़ डेटाबेस | — (नया) |
| `bee_config` | कॉन्फ़िग प्रबंधन + हॉट रीलोड | `client/config` |
| `bee_cache` | कैश अमूर्तन | `client/cache` |
| `bee_session` | सत्र प्रबंधन | `server/web/session` |
| `bee_logs` | लॉगिंग | `logs` |
| `bee_template` | टेम्पलेट रेंडरिंग | — (वर्धित) |
| `bee_cli` | CLI उपकरण | `bee` उपकरण |

### परीक्षण कवरेज

रिपॉज़िटरी के सभी 68 परीक्षण पास:

| Crate | परीक्षणों की संख्या |
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

## समर्थन

यदि यह प्रोजेक्ट आपके लिए उपयोगी है, तो QR कोड स्कैन करके दान करें। धन्यवाद!

**वीचैट पे**

<img src="docs/weixinpay.png" width="160" height="175" alt="WeChat Pay">

**अलीपे**

<img src="docs/alipay.png" width="160" height="175" alt="Alipay">

### लाइसेंस

Apache-2.0
