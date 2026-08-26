# Beerust

**语言 / Languages:** [中文](README.md) · [English](README.en.md) · [한국어](README.ko.md) · [Русский](README.ru.md) · [Deutsch](README.de.md) · [Français](README.fr.md) · [Español](README.es.md) · [Português](README.pt.md) · [हिन्दी](README.hi.md) · [العربية](README.ar.md) · [বাংলা](README.bn.md) · [Bahasa Indonesia](README.id.md) · [日本語](README.ja.md)

<!-- Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz -->

Beerust es un framework web de nivel de producción escrito en Rust. Su filosofía de diseño proviene de Beego, el framework de Go, reexpresada mediante los rasgos idiomáticos de Rust: traits, macros y el sistema de tipos.

## Objetivos de diseño

| Objetivo | Métrica |
|------|------|
| **Experiencia de desarrollo** | de `bee-rust new` a la primera petición < 30 segundos |
| **Rendimiento** | sobrecarga de la capa de controladores < 5 % (frente a axum puro), latencia de enrutado P99 < 100 µs |
| **Velocidad de compilación** | compilación completa del meta-crate < 60 s (release), compilación incremental < 5 s |
| **Tamaño del binario** | aplicación mínima (solo router) < 5 MB (strip + LTO) |
| **Seguridad** | 0 código de negocio unsafe; todo el FFI encapsulado en crates `*-sys` dedicados |
| **Compatibilidad** | MSRV Rust 1.80+, siguiendo stable |

## Principios de diseño

1. **Filosofía Beego, expresión en Rust** — MVC, espacios de nombres y cadenas de filtros implementados con traits + macros
2. **Mejora progresiva** — el núcleo mínimo solo depende de axum + tokio; todo lo demás está detrás de feature flags
3. **Explícito sobre implícito** — registro de rutas, mapeo de modelos y orden de middlewares, todo declarado explícitamente en el código
4. **Abstracción de costo cero** — despacho estático con traits, macros expandidas en tiempo de compilación, sin sobrecarga de llamadas virtuales
5. **Independencia de los motores de almacenamiento** — cada trait de motor puede sustituirse por separado sin afectar a la lógica de negocio de las capas superiores
6. **Observabilidad integrada** — instrumentación de tracing + metrics en todo el framework, logs estructurados activados por defecto

## Diseño de arquitectura

### Topología de crates

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

### Diagrama de arquitectura

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

### Dependencias entre crates

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

### Bases de datos compatibles

| Categoría | Base de datos | Crate | Feature Flag |
|------|--------|-----------|-------------|
| **Relacionales** | SQLite | `bee_orm` | `sqlite` |
| | PostgreSQL | `bee_orm` | `postgres` |
| | MySQL | `bee_orm` | `mysql` |
| | TiDB | `bee_orm` | `mysql` |
| **KV / caché** | Redis | `bee_kv` / `bee_cache` | `redis` |
| | Memcached | `bee_kv` / `bee_cache` | `memcache` |
| **Búsqueda / análisis** | Elasticsearch | `bee_search` | `elasticsearch` |
| | OpenSearch | `bee_search` | `opensearch` |
| | ClickHouse | `bee_search` | `clickhouse` |
| **Bases de datos de grafos** | Neo4j | `bee_graph` | `neo4j` |
| | NebulaGraph | `bee_graph` | `nebulagraph` |
| | ArangoDB | `bee_graph` | `arangodb` |
| **Series temporales** | InfluxDB | `bee_tsdb` | `influxdb` |
| | Apache IoTDB | `bee_tsdb` | `iotdb` |
| | QuestDB | `bee_tsdb` | `questdb` |

### Cadena de filtros de peticiones

```
请求 → [SecurityFilter 攻击检测] → [Session 恢复] → [参数验证] → [prepare 钩子] → [handle 处理] → [finish 钩子] → 响应
                  ↓ 任何环节可中断（类似 Beego 的 Abort）
```

## Resumen de funciones

### Núcleo web (bee_router)

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

**Context proporciona:**
- `ctx.json()` / `ctx.text()` / `ctx.html()` — salida de respuesta
- `ctx.redirect()` — redirección
- `ctx.abort()` — interrumpir la petición
- `ctx.session` — acceso a la sesión
- `ctx.params` — parámetros de ruta

### Detección de ataques (feature `security`)

Un filtro de detección de ataques basado en [security-rust](https://crates.io/crates/security-rust), que cubre 27 tipos de ataques, entre ellos XSS, inyección SQL, inyección de comandos, SSRF, etc.:

```rust
use bee_rust::prelude::*;

let security = SecurityFilter::new();  // 27 个检测器全开
```

Actívalo en `Cargo.toml`:
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

### Gestión de configuración (bee_config)

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

### Motores de almacenamiento

**KV/caché:**
```rust
let kv = RedisStore::new("redis://localhost:6379").await?;
kv.set("key", b"value", Some(Duration::from_secs(60))).await?;
let val = kv.get("key").await?;
```

**Motor de búsqueda (planificado):**
```rust
// 驱动实现计划中，当前为 trait stub
let engine = ElasticsearchEngine::new("http://localhost:9200")?;
let result = engine.search("my_index", &SearchQuery {
    q: Some("keyword".into()), ..Default::default()
}).await?;
```

**Base de datos de grafos (planificada):**
```rust
// 驱动实现计划中，当前为 trait stub
let db = Neo4jDB::new("bolt://localhost:7687").await?;
let vid = db.add_vertex("Person", &[("name", "Alice")]).await?;
```

**Base de datos de series temporales (planificada):**
```rust
// 驱动实现计划中，当前为 trait stub
let tsdb = InfluxDB::new("http://localhost:8086").await?;
tsdb.write_point("cpu", &[("host", "srv1")], &[("value", 0.85)], Utc::now()).await?;
```

### Sesiones

```rust
let cache = Arc::new(MemoryCache::new());
let mut session = Session::new(cache, Duration::from_secs(3600));
session.set("user_id", &"123")?;
let uid: String = session.get("user_id")?.unwrap();
```

### Registro de logs

```rust
Logger::new()
    .level(Level::INFO)
    .output(Output::MultiFile("logs/"))
    .async_()
    .init()?;
```

### Plantillas

```rust
let engine = TemplateEngine::new("views/")?;
let result = engine.render("hello.html", &context! { name: &"World" })?;
// → "Hello, World!"
```

### Herramientas CLI

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

> Nota: el parámetro `pack --target` es una interfaz reservada; el proceso de empaquetado actual no distingue entre plataformas de destino.

## Uso

### Requisitos

- Rust 1.80+
- Cargo

### Instalación

```bash
# 克隆项目
git clone https://github.com/erikwang2013/bee-rust.git
cd bee-rust

# 编译
cargo build --workspace

# 运行测试
cargo test --workspace
```

### Inicio rápido

```bash
# 使用 CLI 创建新项目
cargo run -p bee_cli -- new hello
cd hello

# 运行开发服务器
cargo run
```

### Uso en tu proyecto

```toml
[dependencies]
bee_rust = { git = "https://github.com/erikwang2013/bee-rust", features = ["full"] }
```

## Notas técnicas

### Pila tecnológica

| Capa | Tecnología |
|----|------|
| Base HTTP | axum 0.8 + tower 0.5 |
| Runtime asíncrono | tokio 1.x |
| Serialización | serde + serde_json |
| Motor de plantillas | tera 1.x |
| Capa de logs | tracing + tracing-subscriber |
| CLI | clap 4 |
| Análisis de configuración | toml / serde_yaml / INI propio |
| Manejo de errores | thiserror |
| Macros procedurales | syn + quote + proc-macro2 |

### Patrones de diseño

| Patrón | Aplicación |
|------|------|
| **Builder** | Logger, Router, QuerySet |
| **Abstracción con traits** | Cache, KvStore, SearchEngine, GraphDB, TimeSeriesDB |
| **Macros derivadas** | `#[derive(Model)]`, `#[derive(Config)]` |
| **Feature Gate** | implementaciones de drivers compiladas bajo demanda (redis, memcached, elasticsearch, etc.) |
| **Filter Chain** | cadena de filtros de peticiones, siguiendo el modelo de Beego Filter |

### Lista de crates

| Crate | Función | Equivalente en Beego |
|-------|------|-----------|
| `bee_rust` | meta-crate, punto de entrada unificado | — |
| `bee_router` | enrutado + controladores + Context + filtros | `server/web`, `context` |
| `bee_orm` | ORM + QuerySet + Migration | `client/orm` |
| `bee_kv` | abstracción unificada de KV/caché | `client/cache` (extendido) |
| `bee_search` | motor de búsqueda/análisis | — (nuevo) |
| `bee_graph` | base de datos de grafos | — (nuevo) |
| `bee_tsdb` | base de datos de series temporales | — (nuevo) |
| `bee_config` | gestión de configuración + recarga en caliente | `client/config` |
| `bee_cache` | abstracción de caché | `client/cache` |
| `bee_session` | gestión de sesiones | `server/web/session` |
| `bee_logs` | logs | `logs` |
| `bee_template` | renderizado de plantillas | — (mejorado) |
| `bee_cli` | herramientas CLI | herramienta `bee` |

### Cobertura de pruebas

Los 68 tests del repositorio pasan:

| Crate | N.º de tests |
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

## Soporte

Si este proyecto te resulta útil, no dudes en escanear los códigos QR para donar. ¡Gracias!

**WeChat Pay**

<img src="docs/weixinpay.png" width="160" height="175" alt="WeChat Pay">

**Alipay**

<img src="docs/alipay.png" width="160" height="175" alt="Alipay">

### Licencia

Apache-2.0
