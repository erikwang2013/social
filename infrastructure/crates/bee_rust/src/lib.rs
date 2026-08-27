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

#[cfg(test)]
mod tests {
    use super::*;

    /// The umbrella crate exists to re-export the feature-gated sub-crates;
    /// with default features (`full`) the prelude must expose the core API.
    #[test]
    fn prelude_exposes_core_api() {
        use crate::prelude::*;
        use std::sync::Arc;
        use std::time::Duration;

        // Compile-time checks that the re-exports resolve.
        let _ = std::any::type_name::<Router>();
        let _ = std::any::type_name::<Context>();
        let _ = std::any::type_name::<Session>();
        let _ = std::any::type_name::<Logger>();
        let _ = std::any::type_name::<Output>();
        let _ = std::any::type_name::<bee_config::ConfigError>();
        // ConfigSource is a trait (no type_name possible); the import above
        // already fails to compile if the re-export is missing.
        let _ = std::any::type_name::<Session>();
        let _ = std::any::type_name::<SecurityFilter>();
        let _: Result<()> = Ok(());

        // Runtime check that the Result alias type-checks through a call.
        let session: Session =
            Session::new(Arc::new(crate::bee_cache::MemoryCache::new()), Duration::from_secs(60));
        assert!(session.is_empty());
    }

    #[test]
    fn result_alias_is_boxed_error() {
        let r: Result<i32> = Ok(42);
        assert_eq!(r.unwrap(), 42);
        let e: Result<i32> = Err("boom".into());
        assert!(e.is_err());
    }
}
