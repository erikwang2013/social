// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
pub mod error;
pub mod ini;
pub mod paths;

use std::path::Path;

pub use error::ConfigError;

pub use bee_config_macro::Config;

pub trait ConfigSource: Sized {
    fn load<P: AsRef<Path>>(path: P) -> Result<Self, ConfigError>;
    /// Re-reads the file the config was loaded from and replaces `self` with
    /// the new values. Returns `ConfigError::NotFound` if `load` was never
    /// called for this type.
    fn reload(&mut self) -> Result<(), ConfigError>;
    /// Blocks until the config file changes once, then returns. Call
    /// `reload()` afterwards to pick up the new values.
    ///
    /// Synchronous only — blocks the calling thread. In async code run it
    /// with `tokio::task::spawn_blocking`, never on a worker thread.
    fn watch(&self) -> Result<(), ConfigError>;
}
