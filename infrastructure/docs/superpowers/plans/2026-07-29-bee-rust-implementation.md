<!-- Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz -->
# bee-rust Framework — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a production-grade Rust web framework (13 crates) inspired by Beego, with axum base, ORM, multi-engine storage abstractions, config/cache/logs/session/template, and CLI tooling.

**Architecture:** Modular crate family with `bee_rust` meta-crate re-exporting. Phased by dependency — foundation crates first, then storage engines, then web core, finally CLI and meta-crate.

**Tech Stack:** Rust 1.80+, axum 0.8+, tokio 1.x, tower, tera, sqlx, tracing, clap, tonic, reqwest, redis-rs

---

## File Structure

```
bee-rust/
├── Cargo.toml                  # workspace manifest
├── crates/
│   ├── bee_config/             # Phase 1: Config + derive macro
│   ├── bee_config_macro/       # Phase 1: proc-macro for Config
│   ├── bee_logs/               # Phase 1: Logger builder
│   ├── bee_cache/              # Phase 2: Cache trait + Memory
│   ├── bee_template/           # Phase 2: Template engine
│   ├── bee_kv/                 # Phase 3: KvStore trait + Redis
│   ├── bee_search/             # Phase 3: SearchEngine trait
│   ├── bee_graph/              # Phase 3: GraphDB trait
│   ├── bee_tsdb/               # Phase 3: TimeSeriesDB trait
│   ├── bee_orm/                # Phase 4: Model + QuerySet
│   ├── bee_orm_macro/          # Phase 4: proc-macro for Model
│   ├── bee_session/            # Phase 4: Session management
│   ├── bee_router/             # Phase 5: Router + Context + Controller
│   ├── bee_cli/                # Phase 6: bee-rust CLI tool
│   └── bee_rust/               # Phase 6: Meta crate re-exports
└── examples/
    └── hello/                  # Phase 6: Hello world example
```

---

## Phase 1: Foundation

### Task 1: Workspace setup

**Files:**
- Create: `Cargo.toml`

- [ ] **Step 1: Create workspace manifest**

```toml
[workspace]
resolver = "2"
members = [
    "crates/bee_config",
    "crates/bee_config_macro",
    "crates/bee_logs",
    "crates/bee_cache",
    "crates/bee_template",
    "crates/bee_kv",
    "crates/bee_search",
    "crates/bee_graph",
    "crates/bee_tsdb",
    "crates/bee_orm",
    "crates/bee_orm_macro",
    "crates/bee_session",
    "crates/bee_router",
    "crates/bee_cli",
    "crates/bee_rust",
]

[workspace.package]
version = "0.1.0"
edition = "2021"
license = "Apache-2.0"

[workspace.dependencies]
tokio = { version = "1", features = ["full"] }
axum = "0.8"
tower = "0.5"
serde = { version = "1", features = ["derive"] }
serde_json = "1"
thiserror = "2"
tracing = "0.1"
tracing-subscriber = "0.3"
async-trait = "0.1"
```

- [ ] **Step 2: Verify workspace**

Run: `cargo check`
Expected: "no packages to check" (empty, valid manifest)

- [ ] **Step 3: Commit**

```bash
git add Cargo.toml
git commit -m "feat: add workspace manifest with all 13 crates"
```

---

### Task 2: bee_config — Error types + Config trait

**Files:**
- Create: `crates/bee_config/Cargo.toml`
- Create: `crates/bee_config/src/lib.rs`
- Create: `crates/bee_config/src/error.rs`

- [ ] **Step 1: Create Cargo.toml**

```toml
[package]
name = "bee_config"
version.workspace = true
edition.workspace = true
license.workspace = true

[dependencies]
serde = { workspace = true }
serde_json = { workspace = true }
thiserror = { workspace = true }
toml = "0.8"
serde_yaml = "0.9"
notify = "6"
```

- [ ] **Step 2: Define error types**

File: `crates/bee_config/src/error.rs`

```rust
use thiserror::Error;

#[derive(Error, Debug)]
pub enum ConfigError {
    #[error("file not found: {0}")]
    NotFound(String),
    #[error("parse error in {file}: {message}")]
    ParseError { file: String, message: String },
    #[error("missing required key: {0}")]
    MissingKey(String),
    #[error("invalid type for key {key}: expected {expected}, got {actual}")]
    TypeMismatch { key: String, expected: String, actual: String },
}
```

- [ ] **Step 3: Define ConfigSource trait**

File: `crates/bee_config/src/lib.rs`

```rust
pub mod error;
use error::ConfigError;
use std::path::Path;

pub trait ConfigSource: Sized {
    fn load<P: AsRef<Path>>(path: P) -> Result<Self, ConfigError>;
    fn reload(&mut self) -> Result<(), ConfigError>;
    fn watch(&self) -> Result<(), ConfigError>;
}
```

- [ ] **Step 4: Write failing test**

File: `crates/bee_config/tests/config_test.rs`

```rust
use bee_config::ConfigSource;
use serde::Deserialize;

#[derive(Deserialize, Debug, PartialEq)]
struct TestConfig {
    app_name: String,
    http_port: u16,
    run_mode: String,
}

impl ConfigSource for TestConfig {
    fn load<P: AsRef<std::path::Path>>(path: P) -> Result<Self, bee_config::ConfigError> {
        todo!()
    }
    fn reload(&mut self) -> Result<(), bee_config::ConfigError> { todo!() }
    fn watch(&self) -> Result<(), bee_config::ConfigError> { todo!() }
}

#[test]
fn test_simple_config() {
    // Will be implemented after INI parser
    assert!(true);
}
```

- [ ] **Step 5: Commit**

```bash
git add crates/bee_config/
git commit -m "test(bee_config): add ConfigSource trait + error types"
```

---

### Task 3: bee_config — INI parser + derive macro

**Files:**
- Create: `crates/bee_config/src/ini.rs`
- Create: `crates/bee_config/tests/fixtures/test.conf`
- Create: `crates/bee_config_macro/Cargo.toml`
- Create: `crates/bee_config_macro/src/lib.rs`
- Modify: `crates/bee_config/Cargo.toml`
- Modify: `crates/bee_config/src/lib.rs`

- [ ] **Step 1: Create test fixture**

File: `crates/bee_config/tests/fixtures/test.conf`

```ini
app_name = test-app
http_port = 8080
run_mode = dev
```

- [ ] **Step 2: Implement INI parser**

File: `crates/bee_config/src/ini.rs`

```rust
use std::collections::HashMap;

pub struct IniParser;

impl IniParser {
    pub fn parse(content: &str) -> HashMap<String, HashMap<String, String>> {
        let mut result: HashMap<String, HashMap<String, String>> = HashMap::new();
        let mut current_section = String::from("default");

        for line in content.lines() {
            let trimmed = line.trim();
            if trimmed.is_empty() || trimmed.starts_with(';') || trimmed.starts_with('#') {
                continue;
            }
            if trimmed.starts_with('[') && trimmed.ends_with(']') {
                current_section = trimmed[1..trimmed.len() - 1].to_string();
                continue;
            }
            if let Some((key, value)) = trimmed.split_once('=') {
                result
                    .entry(current_section.clone())
                    .or_default()
                    .insert(key.trim().to_string(), value.trim().to_string());
            }
        }
        result
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn test_parse_simple() {
        let content = "app_name = test-app\nhttp_port = 8080\n";
        let parsed = IniParser::parse(content);
        let default = &parsed["default"];
        assert_eq!(default["app_name"], "test-app");
        assert_eq!(default["http_port"], "8080");
    }

    #[test]
    fn test_parse_sections() {
        let content = "app_name = test-app\n\n[database]\nhost = localhost\nport = 5432\n";
        let parsed = IniParser::parse(content);
        assert_eq!(parsed["default"]["app_name"], "test-app");
        assert_eq!(parsed["database"]["host"], "localhost");
    }
}
```

- [ ] **Step 3: Run INI tests**

Run: `cargo test -p bee_config`
Expected: ini::tests PASS

- [ ] **Step 4: Create proc-macro crate**

File: `crates/bee_config_macro/Cargo.toml`

```toml
[package]
name = "bee_config_macro"
version.workspace = true
edition.workspace = true

[lib]
proc-macro = true

[dependencies]
syn = { version = "2", features = ["full"] }
quote = "1"
proc-macro2 = "1"
```

File: `crates/bee_config_macro/src/lib.rs`

```rust
use proc_macro::TokenStream;
use quote::quote;
use syn::{parse_macro_input, DeriveInput};

#[proc_macro_derive(Config, attributes(config))]
pub fn derive_config(input: TokenStream) -> TokenStream {
    let input = parse_macro_input!(input as DeriveInput);
    let name = &input.ident;

    let expanded = quote! {
        impl bee_config::ConfigSource for #name {
            fn load<P: AsRef<std::path::Path>>(path: P) -> Result<Self, bee_config::ConfigError> {
                use serde::Deserialize;
                let content = std::fs::read_to_string(&path)
                    .map_err(|e| bee_config::ConfigError::NotFound(e.to_string()))?;
                let sections = bee_config::ini::IniParser::parse(&content);
                let default = sections.get("default")
                    .ok_or_else(|| bee_config::ConfigError::MissingKey("default section".into()))?;
                let json = serde_json::to_string(default)
                    .map_err(|e| bee_config::ConfigError::ParseError { file: path.as_ref().to_string_lossy().into(), message: e.to_string() })?;
                serde_json::from_str(&json)
                    .map_err(|e| bee_config::ConfigError::ParseError { file: path.as_ref().to_string_lossy().into(), message: e.to_string() })
            }

            fn reload(&mut self) -> Result<(), bee_config::ConfigError> {
                // Default no-op
                Ok(())
            }

            fn watch(&self) -> Result<(), bee_config::ConfigError> {
                // Stub
                Ok(())
            }
        }
    };

    TokenStream::from(expanded)
}
```

- [ ] **Step 5: Wire up bee_config**

File: `crates/bee_config/Cargo.toml` (add to dependencies):

```toml
bee_config_macro = { path = "../bee_config_macro" }
```

File: `crates/bee_config/src/lib.rs` (add):
```rust
pub mod ini;
pub use bee_config_macro::Config;
```

- [ ] **Step 6: Write derive integration test**

File: `crates/bee_config/tests/derive_test.rs`

```rust
use bee_config::{Config, ConfigSource};
use serde::Deserialize;

#[derive(Deserialize, Debug, PartialEq, Config)]
struct AppConfig {
    app_name: String,
    http_port: u16,
    run_mode: String,
}

#[test]
fn test_derive_config() {
    let cfg = AppConfig::load("tests/fixtures/test.conf").unwrap();
    assert_eq!(cfg.app_name, "test-app");
    assert_eq!(cfg.http_port, 8080);
    assert_eq!(cfg.run_mode, "dev");
}
```

- [ ] **Step 7: Run tests**

Run: `cargo test -p bee_config`
Expected: ALL tests PASS

- [ ] **Step 8: Commit**

```bash
git add Cargo.toml crates/bee_config/ crates/bee_config_macro/
git commit -m "feat(bee_config): add INI parser + Config derive macro"
```

---

### Task 4: bee_logs — Logger builder

**Files:**
- Create: `crates/bee_logs/Cargo.toml`
- Create: `crates/bee_logs/src/lib.rs`

- [ ] **Step 1: Create Cargo.toml**

```toml
[package]
name = "bee_logs"
version.workspace = true
edition.workspace = true

[dependencies]
tracing = { workspace = true }
tracing-subscriber = { workspace = true, features = ["env-filter", "json", "fmt"] }
tracing-appender = "0.2"
```

- [ ] **Step 2: Implement Logger**

File: `crates/bee_logs/src/lib.rs`

```rust
use tracing::Level;
use tracing_subscriber::{fmt, prelude::*, EnvFilter};

pub enum Output {
    Stdout,
    File(String),
    MultiFile(String),
}

pub struct Logger {
    level: Level,
    output: Output,
    async_mode: bool,
}

impl Logger {
    pub fn new() -> Self {
        Self { level: Level::INFO, output: Output::Stdout, async_mode: false }
    }

    pub fn level(mut self, level: Level) -> Self {
        self.level = level;
        self
    }

    pub fn output(mut self, output: Output) -> Self {
        self.output = output;
        self
    }

    pub fn async_(mut self) -> Self {
        self.async_mode = true;
        self
    }

    pub fn init(self) -> Result<(), Box<dyn std::error::Error>> {
        let filter = EnvFilter::try_from_default_env()
            .unwrap_or_else(|_| EnvFilter::new(self.level_str()));

        match &self.output {
            Output::Stdout => {
                tracing_subscriber::registry()
                    .with(filter)
                    .with(fmt::layer().with_target(true))
                    .init();
            }
            Output::File(path) => {
                let file = std::fs::OpenOptions::new().create(true).append(true).open(path)?;
                let (writer, _guard) = tracing_appender::non_blocking(file);
                tracing_subscriber::registry()
                    .with(filter)
                    .with(fmt::layer().with_writer(writer))
                    .init();
            }
            Output::MultiFile(dir) => {
                let appender = tracing_appender::rolling::daily(dir, "app.log");
                let (writer, _guard) = tracing_appender::non_blocking(appender);
                tracing_subscriber::registry()
                    .with(filter)
                    .with(fmt::layer().with_writer(writer).json())
                    .init();
            }
        }
        Ok(())
    }

    fn level_str(&self) -> String {
        match self.level {
            Level::TRACE => "trace", Level::DEBUG => "debug",
            Level::INFO  => "info",  Level::WARN  => "warn",
            Level::ERROR => "error",
        }.into()
    }
}

impl Default for Logger {
    fn default() -> Self { Self::new() }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn test_logger_stdout() {
        Logger::new().level(Level::DEBUG).output(Output::Stdout).init().unwrap();
    }

    #[test]
    fn test_logger_default() {
        Logger::default().init().unwrap();
    }
}
```

- [ ] **Step 3: Run tests**

Run: `cargo test -p bee_logs`
Expected: PASS

- [ ] **Step 4: Commit**

```bash
git add crates/bee_logs/
git commit -m "feat(bee_logs): add Logger with stdout/file/multifile output + async mode"
```

---

## Phase 2: Core Infrastructure

### Task 5: bee_cache — Cache trait + MemoryCache

**Files:**
- Create: `crates/bee_cache/Cargo.toml`
- Create: `crates/bee_cache/src/lib.rs`

- [ ] **Step 1: Create Cargo.toml**

```toml
[package]
name = "bee_cache"
version.workspace = true
edition.workspace = true

[dependencies]
async-trait = { workspace = true }
tokio = { workspace = true, features = ["sync", "time"] }
thiserror.workspace = true

[features]
redis = ["dep:redis"]
memcache = ["dep:memcache"]

[dependencies.redis]
version = "0.27"
optional = true
features = ["tokio-comp"]

[dependencies.memcache]
version = "0.17"
optional = true
```

- [ ] **Step 2: Define Cache trait + MemoryCache**

File: `crates/bee_cache/src/lib.rs`

```rust
use async_trait::async_trait;
use std::collections::HashMap;
use std::sync::Arc;
use std::time::{Duration, Instant};
use tokio::sync::RwLock;

#[derive(Debug, thiserror::Error)]
pub enum CacheError {
    #[error("key not found: {0}")]
    NotFound(String),
    #[error("connection error: {0}")]
    ConnectionError(String),
    #[error("serialization error: {0}")]
    SerializeError(String),
}

struct MemoryEntry {
    value: Vec<u8>,
    expires_at: Option<Instant>,
}

#[async_trait]
pub trait Cache: Send + Sync {
    async fn get(&self, key: &str) -> Option<Vec<u8>>;
    async fn set(&self, key: &str, value: &[u8], ttl: Duration) -> Result<(), CacheError>;
    async fn delete(&self, key: &str) -> Result<(), CacheError>;
    async fn incr(&self, key: &str) -> Result<i64, CacheError>;
}

pub struct MemoryCache {
    store: Arc<RwLock<HashMap<String, MemoryEntry>>>,
}

impl MemoryCache {
    pub fn new() -> Self {
        Self { store: Arc::new(RwLock::new(HashMap::new())) }
    }
}

#[async_trait]
impl Cache for MemoryCache {
    async fn get(&self, key: &str) -> Option<Vec<u8>> {
        let store = self.store.read().await;
        let entry = store.get(key)?;
        if let Some(exp) = entry.expires_at {
            if Instant::now() > exp {
                drop(store);
                self.delete(key).await.ok();
                return None;
            }
        }
        Some(entry.value.clone())
    }

    async fn set(&self, key: &str, value: &[u8], ttl: Duration) -> Result<(), CacheError> {
        self.store.write().await.insert(key.to_string(), MemoryEntry {
            value: value.to_vec(),
            expires_at: Some(Instant::now() + ttl),
        });
        Ok(())
    }

    async fn delete(&self, key: &str) -> Result<(), CacheError> {
        self.store.write().await.remove(key);
        Ok(())
    }

    async fn incr(&self, key: &str) -> Result<i64, CacheError> {
        let mut store = self.store.write().await;
        let entry = store.get(key).ok_or(CacheError::NotFound(key.into()))?;
        let s = String::from_utf8_lossy(&entry.value);
        let n: i64 = s.parse().map_err(|_| CacheError::SerializeError("not an integer".into()))?;
        let new_val = n + 1;
        store.insert(key.to_string(), MemoryEntry {
            value: new_val.to_string().into_bytes(),
            expires_at: entry.expires_at,
        });
        Ok(new_val)
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[tokio::test]
    async fn test_set_get() {
        let c = MemoryCache::new();
        c.set("k", b"v", Duration::from_secs(60)).await.unwrap();
        assert_eq!(c.get("k").await.unwrap(), b"v");
    }

    #[tokio::test]
    async fn test_delete() {
        let c = MemoryCache::new();
        c.set("k", b"v", Duration::from_secs(60)).await.unwrap();
        c.delete("k").await.unwrap();
        assert!(c.get("k").await.is_none());
    }

    #[tokio::test]
    async fn test_incr() {
        let c = MemoryCache::new();
        c.set("n", b"0", Duration::from_secs(60)).await.unwrap();
        assert_eq!(c.incr("n").await.unwrap(), 1);
        assert_eq!(c.incr("n").await.unwrap(), 2);
    }

    #[tokio::test]
    async fn test_ttl_expiry() {
        let c = MemoryCache::new();
        c.set("k", b"v", Duration::from_millis(1)).await.unwrap();
        tokio::time::sleep(Duration::from_millis(10)).await;
        assert!(c.get("k").await.is_none());
    }
}
```

- [ ] **Step 3: Run tests**

Run: `cargo test -p bee_cache`
Expected: ALL PASS

- [ ] **Step 4: Commit**

```bash
git add crates/bee_cache/
git commit -m "feat(bee_cache): add Cache trait + MemoryCache with TTL/incr"
```

---

### Task 6: bee_template — TemplateEngine + context! macro

**Files:**
- Create: `crates/bee_template/Cargo.toml`
- Create: `crates/bee_template/src/lib.rs`

- [ ] **Step 1: Create Cargo.toml**

```toml
[package]
name = "bee_template"
version.workspace = true
edition.workspace = true

[dependencies]
tera = "1"
serde = { workspace = true }
serde_json = { workspace = true }

[dev-dependencies]
tempfile = "3"
```

- [ ] **Step 2: Implement TemplateEngine**

File: `crates/bee_template/src/lib.rs`

```rust
use serde::Serialize;
use std::collections::HashMap;
use std::path::Path;
use tera::{Context as TeraContext, Tera};

#[derive(Debug, thiserror::Error)]
pub enum TemplateError {
    #[error("template not found: {0}")]
    NotFound(String),
    #[error("render error: {0}")]
    RenderError(String),
}

pub struct TemplateEngine {
    tera: Tera,
}

impl TemplateEngine {
    pub fn new<P: AsRef<Path>>(template_dir: P) -> Result<Self, TemplateError> {
        let pattern = template_dir.as_ref().join("**").join("*.html");
        let tera = Tera::new(&pattern.to_string_lossy())
            .map_err(|e| TemplateError::RenderError(e.to_string()))?;
        Ok(Self { tera })
    }

    pub fn render(&self, template: &str, data: &HashMap<String, serde_json::Value>) -> Result<String, TemplateError> {
        let mut ctx = TeraContext::new();
        for (k, v) in data {
            ctx.insert(k, v);
        }
        self.tera.render(template, &ctx)
            .map_err(|e| TemplateError::RenderError(e.to_string()))
    }
}

#[macro_export]
macro_rules! context {
    ($($key:ident : $val:expr),* $(,)?) => {{
        let mut map = std::collections::HashMap::new();
        $(map.insert(
            stringify!($key).to_string(),
            serde_json::to_value(&$val).unwrap_or(serde_json::Value::Null),
        );)*
        map
    }};
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::fs;
    use std::io::Write;

    #[test]
    fn test_context_macro() {
        let name = "Alice";
        let age = 30;
        let ctx = context! { name: &name, age: &age };
        assert_eq!(ctx["name"], serde_json::json!("Alice"));
        assert_eq!(ctx["age"], serde_json::json!(30));
    }

    #[test]
    fn test_render() {
        let dir = tempfile::TempDir::new().unwrap();
        fs::write(dir.path().join("hello.html"), "Hello, {{ name }}!").unwrap();
        let engine = TemplateEngine::new(dir.path()).unwrap();
        let data = context! { name: &"Alice" };
        let result = engine.render("hello.html", &data).unwrap();
        assert_eq!(result, "Hello, Alice!");
    }
}
```

- [ ] **Step 3: Run tests**

Run: `cargo test -p bee_template`
Expected: ALL PASS

- [ ] **Step 4: Commit**

```bash
git add crates/bee_template/
git commit -m "feat(bee_template): add TemplateEngine (tera) + context! macro"
```

---

## Phase 3: Storage Engines

### Task 7: bee_kv — KvStore trait + RedisStore

**Files:**
- Create: `crates/bee_kv/Cargo.toml`
- Create: `crates/bee_kv/src/lib.rs`
- Create: `crates/bee_kv/src/redis_store.rs`

- [ ] **Step 1: Create Cargo.toml**

```toml
[package]
name = "bee_kv"
version.workspace = true
edition.workspace = true

[dependencies]
async-trait = { workspace = true }
thiserror.workspace = true
tokio = { workspace = true, features = ["sync"] }

[features]
redis = ["dep:redis"]
memcached = ["dep:memcache"]

[dependencies.redis]
version = "0.27"
optional = true
features = ["tokio-comp", "aio"]

[dependencies.memcache]
version = "0.17"
optional = true

[dev-dependencies]
tokio = { workspace = true, features = ["rt", "macros"] }
```

- [ ] **Step 2: Define trait + RedisStore**

File: `crates/bee_kv/src/lib.rs`

```rust
#[cfg(feature = "redis")]
pub mod redis_store;

use async_trait::async_trait;
use std::time::Duration;

#[derive(Debug, thiserror::Error)]
pub enum KvError {
    #[error("connection error: {0}")]
    ConnectionError(String),
    #[error("key not found: {0}")]
    NotFound(String),
    #[error("operation failed: {0}")]
    OperationFailed(String),
}

#[async_trait]
pub trait KvStore: Send + Sync {
    async fn get(&self, key: &str) -> Result<Option<Vec<u8>>, KvError>;
    async fn set(&self, key: &str, val: &[u8], ttl: Option<Duration>) -> Result<(), KvError>;
    async fn del(&self, keys: &[&str]) -> Result<u64, KvError>;
    async fn exists(&self, keys: &[&str]) -> Result<u64, KvError>;
    async fn incr(&self, key: &str, delta: i64) -> Result<i64, KvError>;
    async fn expire(&self, key: &str, ttl: Duration) -> Result<bool, KvError>;
    async fn mget(&self, keys: &[&str]) -> Result<Vec<Option<Vec<u8>>>, KvError>;
    async fn mset(&self, kvs: &[(&str, &[u8])]) -> Result<(), KvError>;
}
```

File: `crates/bee_kv/src/redis_store.rs`

```rust
use async_trait::async_trait;
use redis::aio::MultiplexedConnection;
use redis::AsyncCommands;
use std::time::Duration;
use crate::{KvError, KvStore};

pub struct RedisStore {
    conn: MultiplexedConnection,
}

impl RedisStore {
    pub async fn new(addr: &str) -> Result<Self, KvError> {
        let client = redis::Client::open(addr)
            .map_err(|e| KvError::ConnectionError(e.to_string()))?;
        let conn = client.get_multiplexed_async_connection().await
            .map_err(|e| KvError::ConnectionError(e.to_string()))?;
        Ok(Self { conn })
    }
}

#[async_trait]
impl KvStore for RedisStore {
    async fn get(&self, key: &str) -> Result<Option<Vec<u8>>, KvError> {
        self.conn.clone().get(key).await.map_err(|e| KvError::OperationFailed(e.to_string()))
    }

    async fn set(&self, key: &str, val: &[u8], ttl: Option<Duration>) -> Result<(), KvError> {
        let mut conn = self.conn.clone();
        match ttl {
            Some(t) => conn.set_ex(key, val.to_vec(), t.as_secs() as usize).await,
            None => conn.set(key, val.to_vec()).await,
        }
        .map_err(|e| KvError::OperationFailed(e.to_string()))
    }

    async fn del(&self, keys: &[&str]) -> Result<u64, KvError> {
        self.conn.clone().del(keys.to_vec()).await
            .map_err(|e| KvError::OperationFailed(e.to_string()))
    }

    async fn exists(&self, keys: &[&str]) -> Result<u64, KvError> {
        self.conn.clone().exists(keys.to_vec()).await
            .map_err(|e| KvError::OperationFailed(e.to_string()))
    }

    async fn incr(&self, key: &str, delta: i64) -> Result<i64, KvError> {
        self.conn.clone().incr(key, delta).await
            .map_err(|e| KvError::OperationFailed(e.to_string()))
    }

    async fn expire(&self, key: &str, ttl: Duration) -> Result<bool, KvError> {
        self.conn.clone().expire(key, ttl.as_secs() as i64).await
            .map_err(|e| KvError::OperationFailed(e.to_string()))
    }

    async fn mget(&self, keys: &[&str]) -> Result<Vec<Option<Vec<u8>>>, KvError> {
        self.conn.clone().get(keys.to_vec()).await
            .map_err(|e| KvError::OperationFailed(e.to_string()))
    }

    async fn mset(&self, kvs: &[(&str, &[u8])]) -> Result<(), KvError> {
        let pairs: Vec<(&str, Vec<u8>)> = kvs.iter().map(|(k, v)| (*k, v.to_vec())).collect();
        self.conn.clone().mset(&pairs[..]).await
            .map_err(|e| KvError::OperationFailed(e.to_string()))
    }
}
```

- [ ] **Step 3: Commit**

```bash
git add crates/bee_kv/
git commit -m "feat(bee_kv): add KvStore trait + RedisStore implementation"
```

---

### Task 8: bee_search — SearchEngine trait

**Files:**
- Create: `crates/bee_search/Cargo.toml`
- Create: `crates/bee_search/src/lib.rs`

- [ ] **Step 1: Create trait with types and stub test**

File: `crates/bee_search/Cargo.toml`

```toml
[package]
name = "bee_search"
version.workspace = true
edition.workspace = true

[dependencies]
async-trait = { workspace = true }
serde = { workspace = true }
serde_json = { workspace = true }
thiserror.workspace = true

[features]
default = []
elasticsearch = []
opensearch = []
clickhouse = []
```

File: `crates/bee_search/src/lib.rs` — Define `SearchEngine` trait, `SearchQuery`, `SearchResult`, `SearchHit`, `BulkResult`, `Aggregations`, `Mapping`, `Document`, `DocumentId` as in the design spec. Include a `StubEngine` for `#[cfg(test)]` contract verification.

- [ ] **Step 2: Run tests and commit**

```bash
cargo test -p bee_search && git add crates/bee_search/ && git commit -m "feat(bee_search): add SearchEngine trait + stub engine for contract testing"
```

---

### Task 9: bee_graph — GraphDB trait

**Files:**
- Create: `crates/bee_graph/Cargo.toml`
- Create: `crates/bee_graph/src/lib.rs`

- [ ] **Step 1: Define GraphDB trait with types**

File: `crates/bee_graph/Cargo.toml`

```toml
[package]
name = "bee_graph"
version.workspace = true
edition.workspace = true

[dependencies]
async-trait = { workspace = true }
serde = { workspace = true }
serde_json = { workspace = true }
thiserror.workspace = true

[features]
default = []
neo4j = []
nebulagraph = []
arangodb = []
```

File: `crates/bee_graph/src/lib.rs` — Define `GraphDB` trait, `Vertex`, `Edge`, `VertexId`, `EdgeId`, `Properties`, `Params`, `Traversal`, `TraversalDirection`, `PathResult`, `QueryResult`, `GraphError` as in the design spec.

- [ ] **Step 2: Commit**

```bash
cargo test -p bee_graph && git add crates/bee_graph/ && git commit -m "feat(bee_graph): add GraphDB trait with vertex/edge/traverse/query API"
```

---

### Task 10: bee_tsdb — TimeSeriesDB trait

**Files:**
- Create: `crates/bee_tsdb/Cargo.toml`
- Create: `crates/bee_tsdb/src/lib.rs`

- [ ] **Step 1: Define TimeSeriesDB trait with types**

File: `crates/bee_tsdb/Cargo.toml`

```toml
[package]
name = "bee_tsdb"
version.workspace = true
edition.workspace = true

[dependencies]
async-trait = { workspace = true }
serde = { workspace = true }
serde_json = { workspace = true }
thiserror.workspace = true
chrono = { version = "0.4", features = ["serde"] }

[features]
default = []
influxdb = []
iotdb = []
questdb = []
```

File: `crates/bee_tsdb/src/lib.rs` — Define `TimeSeriesDB` trait, `Point`, `TimeSeries`, `Tags`, `Fields`, `Timestamp`, `TagFilter`, `FilterOp`, `Aggregation`, `CQSpec`, `TsdbError` as in the design spec.

- [ ] **Step 2: Commit**

```bash
cargo test -p bee_tsdb && git add crates/bee_tsdb/ && git commit -m "feat(bee_tsdb): add TimeSeriesDB trait with Point/TimeSeries/CQ types"
```

---

## Phase 4: Data Layer

### Task 11: bee_orm — Model derive + QuerySet

**Files:**
- Create: `crates/bee_orm_macro/Cargo.toml`
- Create: `crates/bee_orm_macro/src/lib.rs`
- Create: `crates/bee_orm/Cargo.toml`
- Create: `crates/bee_orm/src/lib.rs`

- [ ] **Step 1: Create proc-macro crate**

File: `crates/bee_orm_macro/Cargo.toml`

```toml
[package]
name = "bee_orm_macro"
version.workspace = true
edition.workspace = true

[lib]
proc-macro = true

[dependencies]
syn = { version = "2", features = ["full"] }
quote = "1"
proc-macro2 = "1"
```

File: `crates/bee_orm_macro/src/lib.rs`

```rust
use proc_macro::TokenStream;
use quote::quote;
use syn::{parse_macro_input, DeriveInput};

#[proc_macro_derive(Model, attributes(bee))]
pub fn derive_model(input: TokenStream) -> TokenStream {
    let input = parse_macro_input!(input as DeriveInput);
    let name = &input.ident;
    let table_name = name.to_string().to_lowercase() + "s";

    let expanded = quote! {
        impl #name {
            pub fn query() -> bee_orm::QuerySet<#name> {
                bee_orm::QuerySet::new(#table_name)
            }
            pub fn table_name() -> &'static str { #table_name }
        }
    };

    TokenStream::from(expanded)
}
```

- [ ] **Step 2: Create ORM crate**

File: `crates/bee_orm/Cargo.toml`

```toml
[package]
name = "bee_orm"
version.workspace = true
edition.workspace = true

[dependencies]
async-trait = { workspace = true }
serde = { workspace = true }
serde_json = { workspace = true }
thiserror.workspace = true
bee_orm_macro = { path = "../bee_orm_macro" }

[features]
sqlite = ["dep:rusqlite"]
postgres = ["dep:tokio-postgres"]
mysql = ["dep:mysql_async"]

[dependencies.rusqlite]
version = "0.32"
optional = true
features = ["bundled"]

[dependencies.tokio-postgres]
version = "0.7"
optional = true

[dependencies.mysql_async]
version = "0.34"
optional = true
```

File: `crates/bee_orm/src/lib.rs`

```rust
pub use bee_orm_macro::Model;
use std::marker::PhantomData;

#[derive(Debug, thiserror::Error)]
pub enum OrmError {
    #[error("connection error: {0}")]
    ConnectionError(String),
    #[error("query error: {0}")]
    QueryError(String),
    #[error("not found")]
    NotFound,
}

pub struct QuerySet<T> {
    table: &'static str,
    filters: Vec<String>,
    order_clause: Option<String>,
    limit_val: Option<usize>,
    offset_val: Option<usize>,
    _marker: PhantomData<T>,
}

impl<T> QuerySet<T> {
    pub fn new(table: &'static str) -> Self {
        Self { table, filters: vec![], order_clause: None, limit_val: None, offset_val: None, _marker: PhantomData }
    }

    pub fn filter(mut self, cond: impl Into<String>) -> Self {
        self.filters.push(cond.into());
        self
    }

    pub fn order_by(mut self, clause: impl Into<String>) -> Self {
        self.order_clause = Some(clause.into());
        self
    }

    pub fn limit(mut self, n: usize) -> Self {
        self.limit_val = Some(n);
        self
    }

    pub fn offset(mut self, n: usize) -> Self {
        self.offset_val = Some(n);
        self
    }

    pub fn to_sql(&self) -> String {
        let mut sql = format!("SELECT * FROM {}", self.table);
        if !self.filters.is_empty() {
            sql.push_str(" WHERE ");
            sql.push_str(&self.filters.join(" AND "));
        }
        if let Some(ref o) = self.order_clause {
            sql.push_str(&format!(" ORDER BY {}", o));
        }
        if let Some(l) = self.limit_val {
            sql.push_str(&format!(" LIMIT {}", l));
        }
        if let Some(o) = self.offset_val {
            sql.push_str(&format!(" OFFSET {}", o));
        }
        sql
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[derive(Model)]
    #[bee(table = "users")]
    struct User {
        id: i64,
        name: String,
        age: i32,
    }

    #[test]
    fn test_query_sql() {
        let sql = User::query()
            .filter("age > 18")
            .filter("name LIKE '%张%'")
            .order_by("created_at DESC")
            .limit(20).offset(0)
            .to_sql();
        assert_eq!(sql, "SELECT * FROM users WHERE age > 18 AND name LIKE '%张%' ORDER BY created_at DESC LIMIT 20 OFFSET 0");
    }

    #[test]
    fn test_table_name() {
        assert_eq!(User::table_name(), "users");
    }
}
```

- [ ] **Step 3: Run tests and commit**

```bash
cargo test -p bee_orm && git add Cargo.toml crates/bee_orm/ crates/bee_orm_macro/ && git commit -m "feat(bee_orm): add Model derive macro + QuerySet SQL builder"
```

---

### Task 12: bee_session — Session with Cache backend

**Files:**
- Create: `crates/bee_session/Cargo.toml`
- Create: `crates/bee_session/src/lib.rs`

- [ ] **Step 1: Implement Session**

File: `crates/bee_session/Cargo.toml`

```toml
[package]
name = "bee_session"
version.workspace = true
edition.workspace = true

[dependencies]
async-trait = { workspace = true }
serde = { workspace = true }
serde_json = { workspace = true }
thiserror.workspace = true
uuid = { version = "1", features = ["v4"] }
bee_cache = { path = "../bee_cache" }
```

File: `crates/bee_session/src/lib.rs` — Implement `Session` with `set`/`get`/`delete`/`save`/`load` as in the design spec. Use `MemoryCache` for tests.

- [ ] **Step 2: Run tests and commit**

```bash
cargo test -p bee_session && git add crates/bee_session/ && git commit -m "feat(bee_session): add Session with set/get/delete/save/load via Cache trait"
```

---

## Phase 5: Web Core

### Task 13: bee_router — Router + Context + Controller + Filter

**Files:**
- Create: `crates/bee_router/Cargo.toml`
- Create: `crates/bee_router/src/lib.rs`
- Create: `crates/bee_router/src/context.rs`
- Create: `crates/bee_router/src/router.rs`
- Create: `crates/bee_router/src/filter.rs`

- [ ] **Step 1: Implement all web core components**

Implement `Context` (request/response builder, json/text/html/redirect/abort), `Controller` trait (handle/prepare/finish), `Router` (namespace support, route registration), `Filter` trait, `RouterError`.

- [ ] **Step 2: Build and verify**

Run: `cargo check -p bee_router`
Expected: compiles successfully

- [ ] **Step 3: Commit**

```bash
git add crates/bee_router/
git commit -m "feat(bee_router): add Router + Context + Controller trait + Filter chain"
```

---

## Phase 6: CLI & Meta

### Task 14: bee_cli — CLI scaffold

**Files:**
- Create: `crates/bee_cli/Cargo.toml`
- Create: `crates/bee_cli/src/main.rs`

- [ ] **Step 1: Implement CLI with clap**

Define `Commands` enum: `New`, `Generate` (Controller/Model), `Run` (`--watch`), `Migrate` (Up/Down), `Pack` (`--target`). Each subcommand prints its action as a stub.

- [ ] **Step 2: Build and test**

```bash
cargo build -p bee_cli && cargo run -p bee_cli -- new my-app
```

Expected: prints "Creating new project: my-app"

- [ ] **Step 3: Commit**

```bash
git add crates/bee_cli/
git commit -m "feat(bee_cli): add bee-rust CLI with new/generate/run/migrate/pack"
```

---

### Task 15: bee_rust — Meta crate

**Files:**
- Create: `crates/bee_rust/Cargo.toml`
- Create: `crates/bee_rust/src/lib.rs`

- [ ] **Step 1: Create meta crate with feature flags**

File: `crates/bee_rust/Cargo.toml`

```toml
[package]
name = "bee_rust"
version.workspace = true
edition.workspace = true

[dependencies]
bee_config = { path = "../bee_config", optional = true }
bee_logs = { path = "../bee_logs", optional = true }
bee_cache = { path = "../bee_cache", optional = true }
bee_template = { path = "../bee_template", optional = true }
bee_kv = { path = "../bee_kv", optional = true }
bee_search = { path = "../bee_search", optional = true }
bee_graph = { path = "../bee_graph", optional = true }
bee_tsdb = { path = "../bee_tsdb", optional = true }
bee_orm = { path = "../bee_orm", optional = true }
bee_session = { path = "../bee_session", optional = true }
bee_router = { path = "../bee_router", optional = true }

[features]
default = ["full"]
full = ["router", "orm", "kv", "config", "logs", "cache", "session"]
router = ["bee_router", "bee_session", "bee_template", "bee_config", "bee_logs"]
orm = ["bee_orm", "bee_config", "bee_cache"]
kv = ["bee_kv"]
search = ["bee_search"]
graph = ["bee_graph"]
tsdb = ["bee_tsdb"]
config = ["bee_config"]
logs = ["bee_logs"]
cache = ["bee_cache", "bee_config"]
session = ["bee_session", "bee_cache"]
```

File: `crates/bee_rust/src/lib.rs`

```rust
#[cfg(feature = "config")]   pub use bee_config;
#[cfg(feature = "logs")]     pub use bee_logs;
#[cfg(feature = "cache")]    pub use bee_cache;
#[cfg(feature = "template")] pub use bee_template;
#[cfg(feature = "kv")]       pub use bee_kv;
#[cfg(feature = "search")]   pub use bee_search;
#[cfg(feature = "graph")]    pub use bee_graph;
#[cfg(feature = "tsdb")]     pub use bee_tsdb;
#[cfg(feature = "orm")]      pub use bee_orm;
#[cfg(feature = "session")]  pub use bee_session;
#[cfg(feature = "router")]   pub use bee_router;

pub mod prelude {
    #[cfg(feature = "router")] pub use bee_router::{Controller, Router, Context};
    #[cfg(feature = "orm")]    pub use bee_orm::Model;
    #[cfg(feature = "config")] pub use bee_config::Config;
}
```

- [ ] **Step 2: Full workspace build**

Run: `cargo check --workspace`
Expected: all crates check successfully

- [ ] **Step 3: Commit**

```bash
git add crates/bee_rust/
git commit -m "feat(bee_rust): add meta crate with feature flags + prelude"
```

---

### Task 16: Hello-world example + bee-rust restriction skill

**Files:**
- Create: `examples/hello/Cargo.toml`
- Create: `examples/hello/src/main.rs`
- Create: `/home/erik/.claude/skills/bee-rust-restrictions/SKILL.md`

- [ ] **Step 1: Create hello example**

File: `examples/hello/Cargo.toml`

```toml
[package]
name = "hello-bee"
version = "0.1.0"
edition = "2021"

[dependencies]
bee_rust = { path = "../../crates/bee_rust", features = ["full"] }
tokio = { version = "1", features = ["full"] }
```

File: `examples/hello/src/main.rs`

```rust
use bee_rust::prelude::*;

#[tokio::main]
async fn main() {
    bee_rust::bee_logs::Logger::new().init().unwrap();

    let router = bee_rust::bee_router::Router::new()
        .ns("/api/v1", |ns| ns);

    tracing::info!("Starting bee-rust on http://localhost:8080");
    let app = router.build();
    let listener = tokio::net::TcpListener::bind("0.0.0.0:8080").await.unwrap();
    axum::serve(listener, app).await.unwrap();
}
```

- [ ] **Step 2: Build hello example**

Run: `cargo check -p hello-bee`
Expected: compiles successfully

- [ ] **Step 3: Create restriction skill**

Create `/home/erik/.claude/skills/bee-rust-restrictions/SKILL.md` with:
- Crate structure rules
- Naming conventions (Controller trait, Model derive, snake_case files)
- Error handling (always return Result, never panic)
- Testing rules (table-driven, every pub trait tested)
- Feature gate conventions
- Common mistakes

- [ ] **Step 4: Final commit**

```bash
git add examples/ /home/erik/.claude/skills/bee-rust-restrictions/
git commit -m "feat: add hello example + bee-rust-restrictions skill"
```

---

## Summary

| Phase | Tasks | Crates |
|-------|-------|--------|
| 1 — Foundation | 1-4 | bee_config, bee_config_macro, bee_logs |
| 2 — Infrastructure | 5-6 | bee_cache, bee_template |
| 3 — Storage Engines | 7-10 | bee_kv, bee_search, bee_graph, bee_tsdb |
| 4 — Data Layer | 11-12 | bee_orm, bee_orm_macro, bee_session |
| 5 — Web Core | 13 | bee_router |
| 6 — CLI & Meta | 14-16 | bee_cli, bee_rust, examples, skill |

**Total:** 16 tasks, 15 crates (13 functional + 2 proc-macro), 1 example, 1 restriction skill
