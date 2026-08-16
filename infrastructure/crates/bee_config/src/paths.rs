// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
// Process-level registry of load paths, keyed by config type.
// One path per type: the last load() wins. reload()/watch() use it
// because they receive no path argument.
use std::any::TypeId;
use std::collections::HashMap;
use std::path::{Path, PathBuf};
use std::sync::{Mutex, OnceLock};
use std::time::Duration;

use notify::{Config as NotifyConfig, RecommendedWatcher, RecursiveMode, Watcher};

use crate::ConfigError;

static PATHS: OnceLock<Mutex<HashMap<TypeId, PathBuf>>> = OnceLock::new();

fn registry() -> &'static Mutex<HashMap<TypeId, PathBuf>> {
    PATHS.get_or_init(|| Mutex::new(HashMap::new()))
}

/// Records the path `T` was loaded from. Errors if `T` was already loaded
/// from a different path, so reload()/watch() never silently point at a
/// second location.
pub fn record<T: 'static>(path: &Path) -> Result<(), ConfigError> {
    let mut map = registry().lock().unwrap();
    if let Some(previous) = map.get(&TypeId::of::<T>())
        && previous != path
    {
        return Err(ConfigError::PathConflict(
            std::any::type_name::<T>().to_string(),
            previous.clone(),
            path.to_path_buf(),
        ));
    }
    map.insert(TypeId::of::<T>(), path.to_path_buf());
    Ok(())
}

/// Returns the path `T` was last loaded from, or `ConfigError::NotFound`.
pub fn path_of<T: 'static>() -> Result<PathBuf, ConfigError> {
    registry()
        .lock()
        .unwrap()
        .get(&TypeId::of::<T>())
        .cloned()
        .ok_or_else(|| ConfigError::NotFound("no load path recorded for this config type".into()))
}

/// Blocks until `path` changes once, then returns. Callers should call
/// `reload()` afterwards. Changes that happen before the watcher is
/// registered are not observed.
///
/// Synchronous only: this blocks the calling thread on a `recv()` with no
/// timeout. In async contexts, run it via
/// `tokio::task::spawn_blocking` (or the equivalent of your executor) —
/// never call it directly on a worker thread.
pub fn watch_path(path: &Path) -> Result<(), ConfigError> {
    let (tx, rx) = std::sync::mpsc::channel();
    let mut watcher = RecommendedWatcher::new(tx, NotifyConfig::default())
        .map_err(|e| ConfigError::Io(std::io::Error::other(e.to_string())))?;
    watcher
        .watch(path, RecursiveMode::NonRecursive)
        .map_err(|e| ConfigError::Io(std::io::Error::other(e.to_string())))?;
    match rx.recv() {
        Ok(_) => Ok(()),
        Err(_) => Err(ConfigError::Io(std::io::Error::other("watch channel closed"))),
    }
}

/// Like [`watch_path`], but waits at most `timeout` and returns `Ok(false)`
/// when the deadline expires without observing a change, so callers can poll
/// instead of blocking forever.
pub fn watch_path_timeout(path: &Path, timeout: Duration) -> Result<bool, ConfigError> {
    let (tx, rx) = std::sync::mpsc::channel();
    let mut watcher = RecommendedWatcher::new(tx, NotifyConfig::default())
        .map_err(|e| ConfigError::Io(std::io::Error::other(e.to_string())))?;
    watcher
        .watch(path, RecursiveMode::NonRecursive)
        .map_err(|e| ConfigError::Io(std::io::Error::other(e.to_string())))?;
    match rx.recv_timeout(timeout) {
        Ok(_) => Ok(true),
        Err(std::sync::mpsc::RecvTimeoutError::Timeout) => Ok(false),
        Err(std::sync::mpsc::RecvTimeoutError::Disconnected) => {
            Err(ConfigError::Io(std::io::Error::other("watch channel closed")))
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn watch_timeout_returns_false_without_change() {
        let dir = std::env::temp_dir().join(format!(
            "bee-config-watch-{}-{}",
            std::process::id(),
            std::time::SystemTime::now()
                .duration_since(std::time::SystemTime::UNIX_EPOCH)
                .unwrap()
                .as_nanos()
        ));
        std::fs::create_dir_all(&dir).unwrap();
        let file = dir.join("conf.json");
        std::fs::write(&file, "{}").unwrap();
        assert!(!watch_path_timeout(&file, Duration::from_millis(50)).unwrap());
        std::fs::remove_dir_all(&dir).unwrap();
    }
}
