# Beerust

**语言 / Languages:** [中文](README.md) · [English](README.en.md) · [한국어](README.ko.md) · [Русский](README.ru.md) · [Deutsch](README.de.md) · [Français](README.fr.md) · [Español](README.es.md) · [Português](README.pt.md) · [हिन्दी](README.hi.md) · [العربية](README.ar.md) · [বাংলা](README.bn.md) · [Bahasa Indonesia](README.id.md) · [日本語](README.ja.md)

<!-- Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz -->

Beerust は Rust で書かれた本番グレードの Web フレームワークです。設計哲学は Go の Beego フレームワークに由来し、Rust らしい trait・macro・型システムで再表現されています。

## 設計目標

| 目標 | 指標 |
|------|------|
| **開発体験** | `bee-rust new` から最初のリクエストまで < 30 秒 |
| **パフォーマンス** | コントローラ層のオーバーヘッド < 5%（素の axum 比）、P99 ルーティング遅延 < 100µs |
| **コンパイル速度** | メタ crate のフルビルド < 60 秒（release）、インクリメンタルビルド < 5 秒 |
| **バイナリサイズ** | 最小アプリ（router のみ）< 5MB（strip + LTO） |
| **安全性** | unsafe な業務コード 0；FFI はすべて独立した `*-sys` crate にカプセル化 |
| **互換性** | MSRV Rust 1.80+、stable を追従 |

## 設計原則

1. **Beego の哲学、Rust で表現** — MVC・名前空間・フィルタチェーンを trait + macro で実装
2. **段階的拡張** — 最小コアは axum + tokio のみに依存し、他はすべて feature gate
3. **暗黙より明示** — ルート登録・モデルマッピング・ミドルウェア順序をすべてコードで明示的に宣言
4. **ゼロコスト抽象化** — trait による静的ディスパッチ、macro はコンパイル時に展開され、仮想関数のオーバーヘッドなし
5. **ストレージエンジンの独立性** — 各エンジン trait を個別に差し替え可能で、上位のビジネスに影響なし
6. **観測可能性を内蔵** — tracing + metrics の計装がフレームワーク全体をカバー、構造化ログはデフォルトで有効

## アーキテクチャ設計

### Crate トポロジー

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

### アーキテクチャ図

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

### Crate 依存関係

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

### 対応データベース

| カテゴリ | データベース | 対応 Crate | Feature Flag |
|------|--------|-----------|-------------|
| **リレーショナル** | SQLite | `bee_orm` | `sqlite` |
| | PostgreSQL | `bee_orm` | `postgres` |
| | MySQL | `bee_orm` | `mysql` |
| | TiDB | `bee_orm` | `mysql` |
| **KV / キャッシュ** | Redis | `bee_kv` / `bee_cache` | `redis` |
| | Memcached | `bee_kv` / `bee_cache` | `memcache` |
| **検索 / 分析** | Elasticsearch | `bee_search` | `elasticsearch` |
| | OpenSearch | `bee_search` | `opensearch` |
| | ClickHouse | `bee_search` | `clickhouse` |
| **グラフデータベース** | Neo4j | `bee_graph` | `neo4j` |
| | NebulaGraph | `bee_graph` | `nebulagraph` |
| | ArangoDB | `bee_graph` | `arangodb` |
| **時系列データベース** | InfluxDB | `bee_tsdb` | `influxdb` |
| | Apache IoTDB | `bee_tsdb` | `iotdb` |
| | QuestDB | `bee_tsdb` | `questdb` |

### リクエストフィルタチェーン

```
请求 → [SecurityFilter 攻击检测] → [Session 恢复] → [参数验证] → [prepare 钩子] → [handle 处理] → [finish 钩子] → 响应
                  ↓ 任何环节可中断（类似 Beego 的 Abort）
```

## 機能紹介

### Web コア（bee_router）

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

**Context が提供するもの:**
- `ctx.json()` / `ctx.text()` / `ctx.html()` — レスポンス出力
- `ctx.redirect()` — リダイレクト
- `ctx.abort()` — リクエスト中断
- `ctx.session` — セッションアクセス
- `ctx.params` — パスパラメータ

### セキュリティ検出（`security` feature）

[security-rust](https://crates.io/crates/security-rust) ベースの攻撃検出フィルタで、XSS・SQL インジェクション・コマンドインジェクション・SSRF など 27 種類の攻撃をカバーします:

```rust
use bee_rust::prelude::*;

let security = SecurityFilter::new();  // 27 个检测器全开
```

`Cargo.toml` で有効化:
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

### 設定管理（bee_config）

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

### ストレージエンジン

**KV/キャッシュ:**
```rust
let kv = RedisStore::new("redis://localhost:6379").await?;
kv.set("key", b"value", Some(Duration::from_secs(60))).await?;
let val = kv.get("key").await?;
```

**検索エンジン（計画中）:**
```rust
// 驱动实现计划中，当前为 trait stub
let engine = ElasticsearchEngine::new("http://localhost:9200")?;
let result = engine.search("my_index", &SearchQuery {
    q: Some("keyword".into()), ..Default::default()
}).await?;
```

**グラフデータベース（計画中）:**
```rust
// 驱动实现计划中，当前为 trait stub
let db = Neo4jDB::new("bolt://localhost:7687").await?;
let vid = db.add_vertex("Person", &[("name", "Alice")]).await?;
```

**時系列データベース（計画中）:**
```rust
// 驱动实现计划中，当前为 trait stub
let tsdb = InfluxDB::new("http://localhost:8086").await?;
tsdb.write_point("cpu", &[("host", "srv1")], &[("value", 0.85)], Utc::now()).await?;
```

### セッション

```rust
let cache = Arc::new(MemoryCache::new());
let mut session = Session::new(cache, Duration::from_secs(3600));
session.set("user_id", &"123")?;
let uid: String = session.get("user_id")?.unwrap();
```

### ログ

```rust
Logger::new()
    .level(Level::INFO)
    .output(Output::MultiFile("logs/"))
    .async_()
    .init()?;
```

### テンプレート

```rust
let engine = TemplateEngine::new("views/")?;
let result = engine.render("hello.html", &context! { name: &"World" })?;
// → "Hello, World!"
```

### CLI ツール

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

> 注: `pack --target` パラメータは予約済みインターフェースです。現在のパッケージング処理は対象プラットフォームを区別しません。

## 使用方法

### 環境要件

- Rust 1.80+
- Cargo

### インストール

```bash
# 克隆项目
git clone https://github.com/erikwang2013/bee-rust.git
cd bee-rust

# 编译
cargo build --workspace

# 运行测试
cargo test --workspace
```

### クイックスタート

```bash
# 使用 CLI 创建新项目
cargo run -p bee_cli -- new hello
cd hello

# 运行开发服务器
cargo run
```

### プロジェクトでの利用

```toml
[dependencies]
bee_rust = { git = "https://github.com/erikwang2013/bee-rust", features = ["full"] }
```

## 技術説明

### 技術スタック

| 層 | 技術 |
|----|------|
| HTTP 基盤 | axum 0.8 + tower 0.5 |
| 非同期ランタイム | tokio 1.x |
| シリアライゼーション | serde + serde_json |
| テンプレートエンジン | tera 1.x |
| ログ基盤 | tracing + tracing-subscriber |
| CLI | clap 4 |
| 設定パース | toml / serde_yaml / 自作 INI |
| エラー処理 | thiserror |
| プロシージャルマクロ | syn + quote + proc-macro2 |

### デザインパターン

| パターン | 適用 |
|------|------|
| **Builder** | Logger, Router, QuerySet |
| **Trait 抽象** | Cache, KvStore, SearchEngine, GraphDB, TimeSeriesDB |
| **派生マクロ** | `#[derive(Model)]`, `#[derive(Config)]` |
| **Feature Gate** | ドライバ実装を必要に応じてコンパイル（redis, memcached, elasticsearch など） |
| **Filter Chain** | リクエストフィルタチェーン、Beego Filter に対応 |

### Crate 一覧

| Crate | 機能 | Beego 対比 |
|-------|------|-----------|
| `bee_rust` | メタ crate、統一エントリポイント | — |
| `bee_router` | ルーティング + コントローラ + Context + フィルタ | `server/web`, `context` |
| `bee_orm` | ORM + QuerySet + Migration | `client/orm` |
| `bee_kv` | KV/キャッシュの統一抽象 | `client/cache`（拡張） |
| `bee_search` | 検索/分析エンジン | —（新規） |
| `bee_graph` | グラフデータベース | —（新規） |
| `bee_tsdb` | 時系列データベース | —（新規） |
| `bee_config` | 設定管理 + ホットリロード | `client/config` |
| `bee_cache` | キャッシュ抽象 | `client/cache` |
| `bee_session` | セッション管理 | `server/web/session` |
| `bee_logs` | ログ | `logs` |
| `bee_template` | テンプレートレンダリング | —（強化） |
| `bee_cli` | CLI ツール | `bee` ツール |

### テストカバレッジ

リポジトリ全体の 68 テストがすべて合格:

| Crate | テスト数 |
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

## 支援のお願い

このプロジェクトが役に立ったなら、QR コードをスキャンして支援いただけると幸いです。ありがとうございます!

**WeChat Pay**

<img src="docs/weixinpay.png" width="160" height="175" alt="WeChat Pay">

**Alipay**

<img src="docs/alipay.png" width="160" height="175" alt="Alipay">

### ライセンス

Apache-2.0
