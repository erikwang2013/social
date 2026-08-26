# Beerust

**语言 / Languages:** [中文](README.md) · [English](README.en.md) · [한국어](README.ko.md) · [Русский](README.ru.md) · [Deutsch](README.de.md) · [Français](README.fr.md) · [Español](README.es.md) · [Português](README.pt.md) · [हिन्दी](README.hi.md) · [العربية](README.ar.md) · [বাংলা](README.bn.md) · [Bahasa Indonesia](README.id.md) · [日本語](README.ja.md)

<!-- Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz -->

Beerust est un framework web de production écrit en Rust. Sa philosophie de conception s'inspire de Beego, le framework Go, réexprimée à travers les idiomes de Rust : traits, macros et système de types.

## Objectifs de conception

| Objectif | Indicateur |
|------|------|
| **Expérience développeur** | de `bee-rust new` à la première requête < 30 secondes |
| **Performance** | surcoût de la couche contrôleur < 5 % (vs axum nu), latence de routage P99 < 100 µs |
| **Vitesse de compilation** | build complet du méta-crate < 60 s (release), build incrémental < 5 s |
| **Taille du binaire** | application minimale (router uniquement) < 5 Mo (strip + LTO) |
| **Sécurité** | 0 code métier unsafe ; tout le FFI encapsulé dans des crates `*-sys` dédiés |
| **Compatibilité** | MSRV Rust 1.80+, suivi de la branche stable |

## Principes de conception

1. **Philosophie Beego, expression Rust** — MVC, namespaces et chaînes de filtres implémentés avec des traits + macros
2. **Amélioration progressive** — le noyau minimal ne dépend que d'axum + tokio, tout le reste est derrière des feature flags
3. **Explicite plutôt qu'implicite** — enregistrement des routes, mapping des modèles, ordre des middlewares : tout est déclaré explicitement dans le code
4. **Abstraction à coût nul** — dispatch statique via les traits, macros expansées à la compilation, aucun surcoût d'appel virtuel
5. **Indépendance des moteurs de stockage** — chaque trait de moteur peut être remplacé séparément sans impacter la logique métier en amont
6. **Observabilité intégrée** — instrumentation tracing + metrics couvrant tout le framework, logs structurés activés par défaut

## Conception de l'architecture

### Topologie des crates

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

### Schéma d'architecture

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

### Dépendances des crates

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

### Bases de données supportées

| Catégorie | Base de données | Crate | Feature Flag |
|------|--------|-----------|-------------|
| **Relationnelles** | SQLite | `bee_orm` | `sqlite` |
| | PostgreSQL | `bee_orm` | `postgres` |
| | MySQL | `bee_orm` | `mysql` |
| | TiDB | `bee_orm` | `mysql` |
| **KV / cache** | Redis | `bee_kv` / `bee_cache` | `redis` |
| | Memcached | `bee_kv` / `bee_cache` | `memcache` |
| **Recherche / analyse** | Elasticsearch | `bee_search` | `elasticsearch` |
| | OpenSearch | `bee_search` | `opensearch` |
| | ClickHouse | `bee_search` | `clickhouse` |
| **Graphes** | Neo4j | `bee_graph` | `neo4j` |
| | NebulaGraph | `bee_graph` | `nebulagraph` |
| | ArangoDB | `bee_graph` | `arangodb` |
| **Séries temporelles** | InfluxDB | `bee_tsdb` | `influxdb` |
| | Apache IoTDB | `bee_tsdb` | `iotdb` |
| | QuestDB | `bee_tsdb` | `questdb` |

### Chaîne de filtres de requêtes

```
请求 → [SecurityFilter 攻击检测] → [Session 恢复] → [参数验证] → [prepare 钩子] → [handle 处理] → [finish 钩子] → 响应
                  ↓ 任何环节可中断（类似 Beego 的 Abort）
```

## Aperçu des fonctionnalités

### Noyau Web (bee_router)

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

**Context fournit :**
- `ctx.json()` / `ctx.text()` / `ctx.html()` — sortie de réponse
- `ctx.redirect()` — redirection
- `ctx.abort()` — interrompre la requête
- `ctx.session` — accès à la session
- `ctx.params` — paramètres de chemin

### Détection d'attaques (feature `security`)

Un filtre de détection d'attaques basé sur [security-rust](https://crates.io/crates/security-rust), couvrant 27 types d'attaques dont XSS, injection SQL, injection de commandes, SSRF, etc. :

```rust
use bee_rust::prelude::*;

let security = SecurityFilter::new();  // 27 个检测器全开
```

À activer dans `Cargo.toml` :
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

### Gestion de la configuration (bee_config)

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

### Moteurs de stockage

**KV/cache :**
```rust
let kv = RedisStore::new("redis://localhost:6379").await?;
kv.set("key", b"value", Some(Duration::from_secs(60))).await?;
let val = kv.get("key").await?;
```

**Moteur de recherche (prévu) :**
```rust
// 驱动实现计划中，当前为 trait stub
let engine = ElasticsearchEngine::new("http://localhost:9200")?;
let result = engine.search("my_index", &SearchQuery {
    q: Some("keyword".into()), ..Default::default()
}).await?;
```

**Base de graphes (prévue) :**
```rust
// 驱动实现计划中，当前为 trait stub
let db = Neo4jDB::new("bolt://localhost:7687").await?;
let vid = db.add_vertex("Person", &[("name", "Alice")]).await?;
```

**Base de séries temporelles (prévue) :**
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

### Journalisation

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

### Outils CLI

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

> Note : le paramètre `pack --target` est une interface réservée ; le processus d'empaquetage actuel ne distingue pas les plateformes cibles.

## Utilisation

### Prérequis

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

### Démarrage rapide

```bash
# 使用 CLI 创建新项目
cargo run -p bee_cli -- new hello
cd hello

# 运行开发服务器
cargo run
```

### Utilisation dans votre projet

```toml
[dependencies]
bee_rust = { git = "https://github.com/erikwang2013/bee-rust", features = ["full"] }
```

## Notes techniques

### Pile technologique

| Couche | Technologie |
|----|------|
| Base HTTP | axum 0.8 + tower 0.5 |
| Runtime asynchrone | tokio 1.x |
| Sérialisation | serde + serde_json |
| Moteur de templates | tera 1.x |
| Sous-couche de logs | tracing + tracing-subscriber |
| CLI | clap 4 |
| Parsing de configuration | toml / serde_yaml / INI maison |
| Gestion des erreurs | thiserror |
| Macros procédurales | syn + quote + proc-macro2 |

### Patrons de conception

| Patron | Application |
|------|------|
| **Builder** | Logger, Router, QuerySet |
| **Abstraction par traits** | Cache, KvStore, SearchEngine, GraphDB, TimeSeriesDB |
| **Macros dérivées** | `#[derive(Model)]`, `#[derive(Config)]` |
| **Feature Gate** | implémentations de pilotes compilées à la demande (redis, memcached, elasticsearch, etc.) |
| **Filter Chain** | chaîne de filtres de requêtes, inspirée de Beego Filter |

### Liste des crates

| Crate | Fonction | Équivalent Beego |
|-------|------|-----------|
| `bee_rust` | méta-crate, point d'entrée unifié | — |
| `bee_router` | routage + contrôleurs + Context + filtres | `server/web`, `context` |
| `bee_orm` | ORM + QuerySet + Migration | `client/orm` |
| `bee_kv` | abstraction unifiée KV/cache | `client/cache` (étendu) |
| `bee_search` | moteur de recherche/analyse | — (nouveau) |
| `bee_graph` | base de graphes | — (nouveau) |
| `bee_tsdb` | base de séries temporelles | — (nouveau) |
| `bee_config` | gestion de configuration + rechargement à chaud | `client/config` |
| `bee_cache` | abstraction de cache | `client/cache` |
| `bee_session` | gestion de session | `server/web/session` |
| `bee_logs` | journalisation | `logs` |
| `bee_template` | rendu de templates | — (amélioré) |
| `bee_cli` | outils CLI | outil `bee` |

### Couverture de tests

Les 68 tests du dépôt passent :

| Crate | Nombre de tests |
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

## Soutien

Si ce projet vous est utile, vous pouvez soutenir son développement en scannant les QR codes. Merci !

**WeChat Pay**

<img src="docs/weixinpay.png" width="160" height="175" alt="WeChat Pay">

**Alipay**

<img src="docs/alipay.png" width="160" height="175" alt="Alipay">

### Licence

Apache-2.0
