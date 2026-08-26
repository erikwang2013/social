# Beerust

**语言 / Languages:** [中文](README.md) · [English](README.en.md) · [한국어](README.ko.md) · [Русский](README.ru.md) · [Deutsch](README.de.md) · [Français](README.fr.md) · [Español](README.es.md) · [Português](README.pt.md) · [हिन्दी](README.hi.md) · [العربية](README.ar.md) · [বাংলা](README.bn.md) · [Bahasa Indonesia](README.id.md) · [日本語](README.ja.md)

<!-- Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz -->

Beerust는 Rust 언어로 작성된 프로덕션급 웹 프레임워크입니다. 디자인 철학은 Go의 Beego 프레임워크에서 비롯되었으며, Rust의 관용적인 trait, macro, 타입 시스템으로 재표현되었습니다.

## 설계 목표

| 목표 | 지표 |
|------|------|
| **개발 경험** | `bee-rust new`부터 첫 요청까지 < 30초 |
| **성능** | 컨트롤러 계층 오버헤드 < 5% (순수 axum 대비), P99 라우팅 지연 < 100µs |
| **컴파일 속도** | 메타 crate 전체 컴파일 < 60초 (release), 증분 컴파일 < 5초 |
| **바이너리 크기** | 최소 애플리케이션 (router만) < 5MB (strip + LTO) |
| **안전성** | unsafe 비즈니스 코드 0; 모든 FFI는 별도 `*-sys` crate에 캡슐화 |
| **호환성** | MSRV Rust 1.80+, stable 트랙 추적 |

## 설계 원칙

1. **Beego 철학, Rust로 표현** — MVC, 네임스페이스, 필터 체인을 trait + macro로 구현
2. **점진적 강화** — 최소 코어는 axum + tokio만 의존하고, 나머지는 전부 feature gate
3. **암시보다 명시** — 라우트 등록, 모델 매핑, 미들웨어 순서를 모두 코드에 명시적으로 선언
4. **제로 비용 추상화** — trait 정적 디스패치, macro 컴파일 타임 확장, 가상 함수 오버헤드 없음
5. **스토리지 엔진 독립성** — 각 엔진 trait을 독립적으로 교체 가능하며, 상위 비즈니스에 영향 없음
6. **내장 관측성** — tracing + metrics 계측이 프레임워크 전체를 커버, 구조화 로깅 기본 활성화

## 아키텍처 설계

### Crate 토폴로지

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

### 아키텍처 다이어그램

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

### Crate 의존 관계

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

### 지원 데이터베이스

| 카테고리 | 데이터베이스 | 해당 Crate | Feature Flag |
|------|--------|-----------|-------------|
| **관계형** | SQLite | `bee_orm` | `sqlite` |
| | PostgreSQL | `bee_orm` | `postgres` |
| | MySQL | `bee_orm` | `mysql` |
| | TiDB | `bee_orm` | `mysql` |
| **KV / 캐시** | Redis | `bee_kv` / `bee_cache` | `redis` |
| | Memcached | `bee_kv` / `bee_cache` | `memcache` |
| **검색 / 분석** | Elasticsearch | `bee_search` | `elasticsearch` |
| | OpenSearch | `bee_search` | `opensearch` |
| | ClickHouse | `bee_search` | `clickhouse` |
| **그래프 데이터베이스** | Neo4j | `bee_graph` | `neo4j` |
| | NebulaGraph | `bee_graph` | `nebulagraph` |
| | ArangoDB | `bee_graph` | `arangodb` |
| **시계열 데이터베이스** | InfluxDB | `bee_tsdb` | `influxdb` |
| | Apache IoTDB | `bee_tsdb` | `iotdb` |
| | QuestDB | `bee_tsdb` | `questdb` |

### 요청 필터 체인

```
请求 → [SecurityFilter 攻击检测] → [Session 恢复] → [参数验证] → [prepare 钩子] → [handle 处理] → [finish 钩子] → 响应
                  ↓ 任何环节可中断（类似 Beego 的 Abort）
```

## 기능 소개

### 웹 코어 (bee_router)

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

**Context 제공:**
- `ctx.json()` / `ctx.text()` / `ctx.html()` — 응답 출력
- `ctx.redirect()` — 리다이렉트
- `ctx.abort()` — 요청 중단
- `ctx.session` — 세션 접근
- `ctx.params` — 경로 매개변수

### 보안 감지 (`security` feature)

[security-rust](https://crates.io/crates/security-rust) 기반의 공격 감지 필터로, XSS, SQL 인젝션, 커맨드 인젝션, SSRF 등 27가지 공격 유형을 커버합니다:

```rust
use bee_rust::prelude::*;

let security = SecurityFilter::new();  // 27 个检测器全开
```

`Cargo.toml`에서 활성화:
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

### 설정 관리 (bee_config)

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

### 스토리지 엔진

**KV/캐시:**
```rust
let kv = RedisStore::new("redis://localhost:6379").await?;
kv.set("key", b"value", Some(Duration::from_secs(60))).await?;
let val = kv.get("key").await?;
```

**검색 엔진 (계획 중):**
```rust
// 驱动实现计划中，当前为 trait stub
let engine = ElasticsearchEngine::new("http://localhost:9200")?;
let result = engine.search("my_index", &SearchQuery {
    q: Some("keyword".into()), ..Default::default()
}).await?;
```

**그래프 데이터베이스 (계획 중):**
```rust
// 驱动实现计划中，当前为 trait stub
let db = Neo4jDB::new("bolt://localhost:7687").await?;
let vid = db.add_vertex("Person", &[("name", "Alice")]).await?;
```

**시계열 데이터베이스 (계획 중):**
```rust
// 驱动实现计划中，当前为 trait stub
let tsdb = InfluxDB::new("http://localhost:8086").await?;
tsdb.write_point("cpu", &[("host", "srv1")], &[("value", 0.85)], Utc::now()).await?;
```

### 세션

```rust
let cache = Arc::new(MemoryCache::new());
let mut session = Session::new(cache, Duration::from_secs(3600));
session.set("user_id", &"123")?;
let uid: String = session.get("user_id")?.unwrap();
```

### 로깅

```rust
Logger::new()
    .level(Level::INFO)
    .output(Output::MultiFile("logs/"))
    .async_()
    .init()?;
```

### 템플릿

```rust
let engine = TemplateEngine::new("views/")?;
let result = engine.render("hello.html", &context! { name: &"World" })?;
// → "Hello, World!"
```

### CLI 도구

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

> 참고: `pack --target` 매개변수는 예약된 인터페이스이며, 현재 패키징 프로세스는 대상 플랫폼을 구분하지 않습니다.

## 사용 방법

### 환경 요구 사항

- Rust 1.80+
- Cargo

### 설치

```bash
# 克隆项目
git clone https://github.com/erikwang2013/bee-rust.git
cd bee-rust

# 编译
cargo build --workspace

# 运行测试
cargo test --workspace
```

### 빠른 시작

```bash
# 使用 CLI 创建新项目
cargo run -p bee_cli -- new hello
cd hello

# 运行开发服务器
cargo run
```

### 프로젝트에서 사용하기

```toml
[dependencies]
bee_rust = { git = "https://github.com/erikwang2013/bee-rust", features = ["full"] }
```

## 기술 설명

### 기술 스택

| 계층 | 기술 |
|----|------|
| HTTP 기반 | axum 0.8 + tower 0.5 |
| 비동기 런타임 | tokio 1.x |
| 직렬화 | serde + serde_json |
| 템플릿 엔진 | tera 1.x |
| 로깅 기반 | tracing + tracing-subscriber |
| CLI | clap 4 |
| 설정 파싱 | toml / serde_yaml / 자체 개발 INI |
| 오류 처리 | thiserror |
| 프로시저 매크로 | syn + quote + proc-macro2 |

### 디자인 패턴

| 패턴 | 적용 |
|------|------|
| **Builder** | Logger, Router, QuerySet |
| **Trait 추상화** | Cache, KvStore, SearchEngine, GraphDB, TimeSeriesDB |
| **파생 매크로** | `#[derive(Model)]`, `#[derive(Config)]` |
| **Feature Gate** | 드라이버 구현을 필요 시 컴파일 (redis, memcached, elasticsearch 등) |
| **Filter Chain** | 요청 필터 체인, Beego Filter에 대응 |

### Crate 목록

| Crate | 기능 | Beego 대응 |
|-------|------|-----------|
| `bee_rust` | 메타 crate, 통합 진입점 | — |
| `bee_router` | 라우팅 + 컨트롤러 + Context + 필터 | `server/web`, `context` |
| `bee_orm` | ORM + QuerySet + Migration | `client/orm` |
| `bee_kv` | KV/캐시 통합 추상화 | `client/cache` (확장) |
| `bee_search` | 검색/분석 엔진 | — (신규) |
| `bee_graph` | 그래프 데이터베이스 | — (신규) |
| `bee_tsdb` | 시계열 데이터베이스 | — (신규) |
| `bee_config` | 설정 관리 + 핫 리로드 | `client/config` |
| `bee_cache` | 캐시 추상화 | `client/cache` |
| `bee_session` | 세션 관리 | `server/web/session` |
| `bee_logs` | 로깅 | `logs` |
| `bee_template` | 템플릿 렌더링 | — (강화) |
| `bee_cli` | CLI 도구 | `bee` 도구 |

### 테스트 커버리지

저장소 전체 68개 테스트 통과:

| Crate | 테스트 수 |
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

## 후원

이 프로젝트가 도움이 되셨다면 QR 코드를 스캔하여 후원해 주시면 감사하겠습니다!

**위챗 페이**

<img src="docs/weixinpay.png" width="160" height="175" alt="WeChat Pay">

**알리페이**

<img src="docs/alipay.png" width="160" height="175" alt="Alipay">

### 라이선스

Apache-2.0
