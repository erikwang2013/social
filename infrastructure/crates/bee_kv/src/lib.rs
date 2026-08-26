// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
use async_trait::async_trait;
use thiserror::Error;

#[cfg(feature = "redis")]
mod redis_store;
#[cfg(feature = "redis")]
pub use redis_store::RedisStore;

/// Errors that can occur when interacting with a key-value store.
#[derive(Error, Debug)]
pub enum KvError {
    #[error("connection error: {0}")]
    ConnectionError(String),

    #[error("key not found: {0}")]
    NotFound(String),

    #[error("operation failed: {0}")]
    OperationFailed(String),
}

/// A generic async key-value store trait.
///
/// Implementors provide basic CRUD operations, expiration, atomic
/// increment, and batched get/set.
#[async_trait]
pub trait KvStore: Send + Sync {
    /// Get the value for `key`.  Returns `None` when the key does not exist.
    async fn get(&self, key: &str) -> Result<Option<String>, KvError>;

    /// Set `key` to `value`, overwriting any existing value.
    async fn set(&self, key: &str, value: &str) -> Result<(), KvError>;

    /// Delete `key`.
    async fn del(&self, key: &str) -> Result<(), KvError>;

    /// Returns `true` when `key` exists.
    async fn exists(&self, key: &str) -> Result<bool, KvError>;

    /// Atomically increment the integer stored at `key` by `amount`.
    /// Returns the new value.
    async fn incr(&self, key: &str, amount: i64) -> Result<i64, KvError>;

    /// Set a TTL (time-to-live) in seconds on `key`.
    async fn expire(&self, key: &str, seconds: i64) -> Result<(), KvError>;

    /// Batch get — returns values in the same order as the requested keys.
    /// Missing keys are represented as `None`.
    async fn mget(&self, keys: &[&str]) -> Result<Vec<Option<String>>, KvError>;

    /// Batch set — each tuple is `(key, value)`.
    async fn mset(&self, pairs: &[(&str, &str)]) -> Result<(), KvError>;
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::sync::Mutex;

    struct Entry {
        value: String,
        expires_at: Option<std::time::Instant>,
    }

    /// In-memory stub used by doc-tests and integration tests.
    pub struct StubKvStore {
        data: Mutex<std::collections::HashMap<String, Entry>>,
    }

    impl StubKvStore {
        pub fn new() -> Self {
            Self { data: Mutex::new(std::collections::HashMap::new()) }
        }

        fn check_expired(entry: &Entry) -> bool {
            match entry.expires_at {
                Some(expires_at) => std::time::Instant::now() >= expires_at,
                None => false,
            }
        }
    }

    #[async_trait]
    impl KvStore for StubKvStore {
        async fn get(&self, key: &str) -> Result<Option<String>, KvError> {
            let map = self.data.lock().unwrap_or_else(|e| e.into_inner());
            Ok(map.get(key).filter(|e| !Self::check_expired(e)).map(|e| e.value.clone()))
        }

        async fn set(&self, key: &str, value: &str) -> Result<(), KvError> {
            let mut map = self.data.lock().unwrap_or_else(|e| e.into_inner());
            map.insert(key.to_string(), Entry { value: value.to_string(), expires_at: None });
            Ok(())
        }

        async fn del(&self, key: &str) -> Result<(), KvError> {
            let mut map = self.data.lock().unwrap_or_else(|e| e.into_inner());
            map.remove(key);
            Ok(())
        }

        async fn exists(&self, key: &str) -> Result<bool, KvError> {
            let map = self.data.lock().unwrap_or_else(|e| e.into_inner());
            Ok(map.get(key).is_some_and(|e| !Self::check_expired(e)))
        }

        async fn incr(&self, key: &str, amount: i64) -> Result<i64, KvError> {
            let mut map = self.data.lock().unwrap_or_else(|e| e.into_inner());
            let entry = map
                .entry(key.to_string())
                .or_insert_with(|| Entry { value: "0".to_string(), expires_at: None });
            if Self::check_expired(entry) {
                *entry = Entry { value: "0".to_string(), expires_at: None };
            }
            let current: i64 = entry
                .value
                .parse()
                .map_err(|_| KvError::OperationFailed("value is not an integer".into()))?;
            let new_val = current + amount;
            entry.value = new_val.to_string();
            Ok(new_val)
        }

        async fn expire(&self, key: &str, seconds: i64) -> Result<(), KvError> {
            let mut map = self.data.lock().unwrap_or_else(|e| e.into_inner());
            let entry = map.get_mut(key).ok_or_else(|| KvError::NotFound(key.into()))?;
            entry.expires_at =
                Some(std::time::Instant::now() + std::time::Duration::from_secs(seconds as u64));
            Ok(())
        }

        async fn mget(&self, keys: &[&str]) -> Result<Vec<Option<String>>, KvError> {
            let map = self.data.lock().unwrap_or_else(|e| e.into_inner());
            Ok(keys
                .iter()
                .map(|k| map.get(*k).filter(|e| !Self::check_expired(e)).map(|e| e.value.clone()))
                .collect())
        }

        async fn mset(&self, pairs: &[(&str, &str)]) -> Result<(), KvError> {
            let mut map = self.data.lock().unwrap_or_else(|e| e.into_inner());
            for (k, v) in pairs {
                map.insert(k.to_string(), Entry { value: v.to_string(), expires_at: None });
            }
            Ok(())
        }
    }

    #[tokio::test]
    async fn test_stub_get_set() {
        let store = StubKvStore::new();
        store.set("hello", "world").await.unwrap();
        assert_eq!(store.get("hello").await.unwrap(), Some("world".into()));
    }

    #[tokio::test]
    async fn test_stub_incr() {
        let store = StubKvStore::new();
        let val = store.incr("counter", 1).await.unwrap();
        assert_eq!(val, 1);
        let val = store.incr("counter", 4).await.unwrap();
        assert_eq!(val, 5);
    }

    #[tokio::test]
    async fn test_stub_mget_mset() {
        let store = StubKvStore::new();
        store.mset(&[("a", "1"), ("b", "2"), ("c", "3")]).await.unwrap();
        let vals = store.mget(&["a", "b", "missing"]).await.unwrap();
        assert_eq!(vals[0], Some("1".into()));
        assert_eq!(vals[1], Some("2".into()));
        assert_eq!(vals[2], None);
    }

    #[tokio::test]
    async fn test_stub_exists_del() {
        let store = StubKvStore::new();
        assert!(!store.exists("x").await.unwrap());
        store.set("x", "y").await.unwrap();
        assert!(store.exists("x").await.unwrap());
        store.del("x").await.unwrap();
        assert!(!store.exists("x").await.unwrap());
    }

    #[tokio::test]
    async fn test_stub_get_missing_is_none() {
        let store = StubKvStore::new();
        assert_eq!(store.get("missing").await.unwrap(), None);
    }

    #[tokio::test]
    async fn test_stub_incr_starts_at_zero() {
        let store = StubKvStore::new();
        // A missing key is treated as 0 before incrementing (Redis semantics).
        let val = store.incr("fresh", 7).await.unwrap();
        assert_eq!(val, 7);
        assert_eq!(store.incr("fresh", -3).await.unwrap(), 4);
    }

    #[tokio::test]
    async fn test_stub_incr_non_integer_errors() {
        let store = StubKvStore::new();
        store.set("word", "hello").await.unwrap();
        let err = store.incr("word", 1).await.unwrap_err();
        assert!(matches!(err, KvError::OperationFailed(_)));
    }

    #[tokio::test]
    async fn test_stub_expire_missing_key_errors() {
        let store = StubKvStore::new();
        let err = store.expire("nope", 10).await.unwrap_err();
        assert!(matches!(err, KvError::NotFound(_)));
    }

    #[tokio::test]
    async fn test_stub_expired_key_reads_as_missing() {
        let store = StubKvStore::new();
        store.set("temp", "v").await.unwrap();
        store.expire("temp", 0).await.unwrap(); // expires immediately
        assert_eq!(store.get("temp").await.unwrap(), None);
        assert!(!store.exists("temp").await.unwrap());
        let vals = store.mget(&["temp"]).await.unwrap();
        assert_eq!(vals, vec![None]);
    }

    #[tokio::test]
    async fn test_stub_mset_overwrites_and_keeps_order() {
        let store = StubKvStore::new();
        store.set("a", "old").await.unwrap();
        store.mset(&[("a", "new"), ("b", "2")]).await.unwrap();
        let vals = store.mget(&["b", "a"]).await.unwrap();
        assert_eq!(vals, vec![Some("2".into()), Some("new".into())]);
    }
}
