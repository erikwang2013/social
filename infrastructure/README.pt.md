# Beerust

**语言 / Languages:** [中文](README.md) · [English](README.en.md) · [한국어](README.ko.md) · [Русский](README.ru.md) · [Deutsch](README.de.md) · [Français](README.fr.md) · [Español](README.es.md) · [Português](README.pt.md) · [हिन्दी](README.hi.md) · [العربية](README.ar.md) · [বাংলা](README.bn.md) · [Bahasa Indonesia](README.id.md) · [日本語](README.ja.md)

<!-- Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz -->

Beerust é um framework web de nível de produção escrito em Rust. Sua filosofia de design deriva do Beego, o framework de Go, reexpressa por meio dos idiomas do Rust: traits, macros e o sistema de tipos.

## Objetivos de design

| Objetivo | Métrica |
|------|------|
| **Experiência do desenvolvedor** | de `bee-rust new` até a primeira requisição < 30 segundos |
| **Desempenho** | overhead da camada de controladores < 5% (em comparação com axum puro), latência de roteamento P99 < 100µs |
| **Velocidade de compilação** | build completo do meta-crate < 60s (release), build incremental < 5s |
| **Tamanho do binário** | aplicação mínima (somente router) < 5MB (strip + LTO) |
| **Segurança** | 0 código de negócio unsafe; todo o FFI encapsulado em crates `*-sys` dedicados |
| **Compatibilidade** | MSRV Rust 1.80+, acompanhando a stable |

## Princípios de design

1. **Filosofia Beego, expressão em Rust** — MVC, namespaces e cadeias de filtros implementados com traits + macros
2. **Aprimoramento progressivo** — o núcleo mínimo depende apenas de axum + tokio; todo o resto fica atrás de feature flags
3. **Explícito em vez de implícito** — registro de rotas, mapeamento de modelos e ordem dos middlewares, tudo declarado explicitamente no código
4. **Abstração de custo zero** — dispatch estático com traits, macros expandidas em tempo de compilação, sem overhead de chamadas virtuais
5. **Independência dos mecanismos de armazenamento** — cada trait de engine pode ser substituído separadamente sem afetar a lógica de negócio das camadas superiores
6. **Observabilidade integrada** — instrumentação com tracing + metrics em todo o framework, logs estruturados ativados por padrão

## Design de arquitetura

### Topologia de crates

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

### Diagrama de arquitetura

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

### Dependências entre crates

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

### Bancos de dados suportados

| Categoria | Banco de dados | Crate | Feature Flag |
|------|--------|-----------|-------------|
| **Relacionais** | SQLite | `bee_orm` | `sqlite` |
| | PostgreSQL | `bee_orm` | `postgres` |
| | MySQL | `bee_orm` | `mysql` |
| | TiDB | `bee_orm` | `mysql` |
| **KV / cache** | Redis | `bee_kv` / `bee_cache` | `redis` |
| | Memcached | `bee_kv` / `bee_cache` | `memcache` |
| **Busca / análise** | Elasticsearch | `bee_search` | `elasticsearch` |
| | OpenSearch | `bee_search` | `opensearch` |
| | ClickHouse | `bee_search` | `clickhouse` |
| **Bancos de grafos** | Neo4j | `bee_graph` | `neo4j` |
| | NebulaGraph | `bee_graph` | `nebulagraph` |
| | ArangoDB | `bee_graph` | `arangodb` |
| **Séries temporais** | InfluxDB | `bee_tsdb` | `influxdb` |
| | Apache IoTDB | `bee_tsdb` | `iotdb` |
| | QuestDB | `bee_tsdb` | `questdb` |

### Cadeia de filtros de requisição

```
请求 → [SecurityFilter 攻击检测] → [Session 恢复] → [参数验证] → [prepare 钩子] → [handle 处理] → [finish 钩子] → 响应
                  ↓ 任何环节可中断（类似 Beego 的 Abort）
```

## Visão geral dos recursos

### Núcleo Web (bee_router)

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

**Context fornece:**
- `ctx.json()` / `ctx.text()` / `ctx.html()` — saída da resposta
- `ctx.redirect()` — redirecionamento
- `ctx.abort()` — interromper a requisição
- `ctx.session` — acesso à sessão
- `ctx.params` — parâmetros de caminho

### Detecção de ataques (feature `security`)

Um filtro de detecção de ataques baseado em [security-rust](https://crates.io/crates/security-rust), cobrindo 27 tipos de ataques, incluindo XSS, injeção de SQL, injeção de comandos, SSRF e outros:

```rust
use bee_rust::prelude::*;

let security = SecurityFilter::new();  // 27 个检测器全开
```

Ative em `Cargo.toml`:
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

### Gerenciamento de configuração (bee_config)

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

### Mecanismos de armazenamento

**KV/cache:**
```rust
let kv = RedisStore::new("redis://localhost:6379").await?;
kv.set("key", b"value", Some(Duration::from_secs(60))).await?;
let val = kv.get("key").await?;
```

**Mecanismo de busca (planejado):**
```rust
// 驱动实现计划中，当前为 trait stub
let engine = ElasticsearchEngine::new("http://localhost:9200")?;
let result = engine.search("my_index", &SearchQuery {
    q: Some("keyword".into()), ..Default::default()
}).await?;
```

**Banco de grafos (planejado):**
```rust
// 驱动实现计划中，当前为 trait stub
let db = Neo4jDB::new("bolt://localhost:7687").await?;
let vid = db.add_vertex("Person", &[("name", "Alice")]).await?;
```

**Banco de séries temporais (planejado):**
```rust
// 驱动实现计划中，当前为 trait stub
let tsdb = InfluxDB::new("http://localhost:8086").await?;
tsdb.write_point("cpu", &[("host", "srv1")], &[("value", 0.85)], Utc::now()).await?;
```

### Sessões

```rust
let cache = Arc::new(MemoryCache::new());
let mut session = Session::new(cache, Duration::from_secs(3600));
session.set("user_id", &"123")?;
let uid: String = session.get("user_id")?.unwrap();
```

### Logs

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

### Ferramentas CLI

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

> Nota: o parâmetro `pack --target` é uma interface reservada; o processo de empacotamento atual não diferencia plataformas de destino.

## Uso

### Requisitos

- Rust 1.80+
- Cargo

### Instalação

```bash
# 克隆项目
git clone https://github.com/erikwang2013/bee-rust.git
cd bee-rust

# 编译
cargo build --workspace

# 运行测试
cargo test --workspace
```

### Início rápido

```bash
# 使用 CLI 创建新项目
cargo run -p bee_cli -- new hello
cd hello

# 运行开发服务器
cargo run
```

### Usando no seu projeto

```toml
[dependencies]
bee_rust = { git = "https://github.com/erikwang2013/bee-rust", features = ["full"] }
```

## Notas técnicas

### Pilha tecnológica

| Camada | Tecnologia |
|----|------|
| Base HTTP | axum 0.8 + tower 0.5 |
| Runtime assíncrono | tokio 1.x |
| Serialização | serde + serde_json |
| Engine de templates | tera 1.x |
| Camada de logs | tracing + tracing-subscriber |
| CLI | clap 4 |
| Parsing de configuração | toml / serde_yaml / INI próprio |
| Tratamento de erros | thiserror |
| Macros procedurais | syn + quote + proc-macro2 |

### Padrões de design

| Padrão | Aplicação |
|------|------|
| **Builder** | Logger, Router, QuerySet |
| **Abstração com traits** | Cache, KvStore, SearchEngine, GraphDB, TimeSeriesDB |
| **Macros de derivação** | `#[derive(Model)]`, `#[derive(Config)]` |
| **Feature Gate** | implementações de drivers compiladas sob demanda (redis, memcached, elasticsearch, etc.) |
| **Filter Chain** | cadeia de filtros de requisição, modelada após Beego Filter |

### Lista de crates

| Crate | Função | Equivalente no Beego |
|-------|------|-----------|
| `bee_rust` | meta-crate, ponto de entrada unificado | — |
| `bee_router` | roteamento + controladores + Context + filtros | `server/web`, `context` |
| `bee_orm` | ORM + QuerySet + Migration | `client/orm` |
| `bee_kv` | abstração unificada de KV/cache | `client/cache` (estendido) |
| `bee_search` | engine de busca/análise | — (novo) |
| `bee_graph` | banco de grafos | — (novo) |
| `bee_tsdb` | banco de séries temporais | — (novo) |
| `bee_config` | gerenciamento de configuração + recarga a quente | `client/config` |
| `bee_cache` | abstração de cache | `client/cache` |
| `bee_session` | gerenciamento de sessões | `server/web/session` |
| `bee_logs` | logs | `logs` |
| `bee_template` | renderização de templates | — (aprimorado) |
| `bee_cli` | ferramenta CLI | ferramenta `bee` |

### Cobertura de testes

Todos os 68 testes do repositório passam:

| Crate | N.º de testes |
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

## Apoio

Se este projeto for útil para você, sinta-se à vontade para escanear os QR codes e fazer uma doação. Obrigado!

**WeChat Pay**

<img src="docs/weixinpay.png" width="160" height="175" alt="WeChat Pay">

**Alipay**

<img src="docs/alipay.png" width="160" height="175" alt="Alipay">

### Licença

Apache-2.0
