// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
#[cfg(feature = "cache")]
pub use bee_cache;
#[cfg(feature = "config")]
pub use bee_config;
#[cfg(feature = "graph")]
pub use bee_graph;
#[cfg(feature = "kv")]
pub use bee_kv;
#[cfg(feature = "logs")]
pub use bee_logs;
#[cfg(feature = "orm")]
pub use bee_orm;
#[cfg(feature = "router")]
pub use bee_router;
#[cfg(feature = "search")]
pub use bee_search;
#[cfg(feature = "session")]
pub use bee_session;
#[cfg(feature = "template")]
pub use bee_template;
#[cfg(feature = "tsdb")]
pub use bee_tsdb;

pub mod prelude {
    #[cfg(feature = "cache")]
    pub use bee_cache::Cache;
    #[cfg(feature = "config")]
    pub use bee_config::{Config, ConfigSource};
    #[cfg(feature = "logs")]
    pub use bee_logs::{Logger, Output};
    #[cfg(feature = "orm")]
    pub use bee_orm::Model;
    #[cfg(feature = "security")]
    pub use bee_router::SecurityFilter;
    #[cfg(feature = "router")]
    pub use bee_router::{Context, Controller, Filter, Router};
    #[cfg(feature = "session")]
    pub use bee_session::Session;
    #[cfg(feature = "template")]
    pub use bee_template::context;
}

pub type Result<T> = std::result::Result<T, Box<dyn std::error::Error>>;

/// One-click startup: initializes the logger and returns a handle.
///
/// Keep the returned `LogHandle` alive for the program lifetime.
///
/// Available only with the `logs` feature (included in the default `full`
/// feature set).
#[cfg(feature = "logs")]
pub fn init() -> Result<bee_logs::LogHandle> {
    bee_logs::Logger::new().init()
}
