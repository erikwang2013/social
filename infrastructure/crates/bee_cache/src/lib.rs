// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
use std::collections::HashMap;
use std::sync::Arc;
use std::time::{Duration, Instant};

use async_trait::async_trait;
use tokio::sync::RwLock;

#[derive(Debug, thiserror::Error)]
pub enum CacheError {
    #[error("key not found")]
    NotFound,
    #[error("connection error: {0}")]
    ConnectionError(String),
    #[error("serialization error: {0}")]
    SerializeError(String),
}

#[async_trait]
pub trait Cache: Send + Sync {
    /// Retrieve a value by key. Returns `Ok(None)` if not found or expired.
    /// Returns `Err(...)` on connection or deserialization errors.
    async fn get(&self, key: &str) -> Result<Option<Vec<u8>>, CacheError>;

    /// Set a key-value pair with an optional TTL in seconds.
    async fn set(&self, key: &str, value: Vec<u8>, ttl: Option<u64>) -> Result<(), CacheError>;

    /// Delete a key. Returns `Err(CacheError::NotFound)` if the key does not exist.
    async fn delete(&self, key: &str) -> Result<(), CacheError>;

    /// Increment a counter stored at `key` and return the new value.
    /// If the key does not exist, it is set to 0 before incrementing.
    async fn incr(&self, key: &str) -> Result<i64, CacheError>;
}

struct MemoryEntry {
    value: Vec<u8>,
    expires_at: Option<Instant>,
    ttl: Option<Duration>,
}

/// An in-memory cache backed by `Arc<RwLock<HashMap<String, MemoryEntry>>>` with TTL expiry.
pub struct MemoryCache {
    store: Arc<RwLock<HashMap<String, MemoryEntry>>>,
}

impl MemoryCache {
    pub fn new() -> Self {
        Self { store: Arc::new(RwLock::new(HashMap::new())) }
    }
}

impl Default for MemoryCache {
    fn default() -> Self {
        Self::new()
    }
}

#[async_trait]
impl Cache for MemoryCache {
    async fn get(&self, key: &str) -> Result<Option<Vec<u8>>, CacheError> {
        let store = self.store.read().await;
        if let Some(entry) = store.get(key) {
            if let Some(expires_at) = entry.expires_at
                && Instant::now() >= expires_at
            {
                drop(store);
                let mut write = self.store.write().await;
                // Re-check under the write lock: another task may have set() a
                // fresh value (or deleted the key) between the two locks.
                if let Some(expires_at) = write.get(key).and_then(|e| e.expires_at)
                    && Instant::now() >= expires_at
                {
                    write.remove(key);
                }
                return Ok(None);
            }
            return Ok(Some(entry.value.clone()));
        }
        Ok(None)
    }

    async fn set(&self, key: &str, value: Vec<u8>, ttl: Option<u64>) -> Result<(), CacheError> {
        let ttl = ttl.map(Duration::from_secs);
        let expires_at = ttl.map(|d| Instant::now() + d);
        let entry = MemoryEntry { value, expires_at, ttl };
        self.store.write().await.insert(key.to_string(), entry);
        Ok(())
    }

    async fn delete(&self, key: &str) -> Result<(), CacheError> {
        let mut store = self.store.write().await;
        if store.remove(key).is_some() { Ok(()) } else { Err(CacheError::NotFound) }
    }

    async fn incr(&self, key: &str) -> Result<i64, CacheError> {
        let mut store = self.store.write().await;

        let entry = store.entry(key.to_string());
        match entry {
            std::collections::hash_map::Entry::Occupied(mut occ) => {
                if let Some(expires_at) = occ.get().expires_at
                    && Instant::now() >= expires_at
                {
                    // Refresh with the original TTL instead of dropping it, so
                    // the counter keeps expiring.
                    let ttl = occ.get().ttl;
                    let expires_at = ttl.map(|d| Instant::now() + d);
                    occ.insert(MemoryEntry { value: b"1".to_vec(), expires_at, ttl });
                    return Ok(1);
                }
                let current: i64 =
                    String::from_utf8_lossy(&occ.get().value).trim().parse().map_err(|_| {
                        CacheError::SerializeError(format!(
                            "value for key '{key}' is not an integer"
                        ))
                    })?;
                let new_value = current + 1;
                occ.get_mut().value = new_value.to_string().into_bytes();
                Ok(new_value)
            }
            std::collections::hash_map::Entry::Vacant(vac) => {
                vac.insert(MemoryEntry { value: b"1".to_vec(), expires_at: None, ttl: None });
                Ok(1)
            }
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[tokio::test]
    async fn test_set_get() {
        let cache = MemoryCache::new();
        cache.set("hello", b"world".to_vec(), None).await.unwrap();
        assert_eq!(cache.get("hello").await.unwrap(), Some(b"world".to_vec()));
    }

    #[tokio::test]
    async fn test_delete() {
        let cache = MemoryCache::new();
        cache.set("temp", b"data".to_vec(), None).await.unwrap();
        assert!(cache.delete("temp").await.is_ok());
        assert!(cache.get("temp").await.unwrap().is_none());
        // Deleting a non-existent key should error
        assert!(cache.delete("temp").await.is_err());
    }

    #[tokio::test]
    async fn test_incr() {
        let cache = MemoryCache::new();
        let v = cache.incr("counter").await.unwrap();
        assert_eq!(v, 1);
        let v = cache.incr("counter").await.unwrap();
        assert_eq!(v, 2);
        let v = cache.incr("counter").await.unwrap();
        assert_eq!(v, 3);
    }

    #[tokio::test]
    async fn test_incr_non_integer_errors() {
        let cache = MemoryCache::new();
        cache.set("word", b"hello".to_vec(), None).await.unwrap();
        let err = cache.incr("word").await.unwrap_err();
        assert!(matches!(err, CacheError::SerializeError(_)));
    }

    #[tokio::test]
    async fn test_set_overwrites_value_and_ttl() {
        let cache = MemoryCache::new();
        cache.set("k", b"first".to_vec(), Some(1)).await.unwrap();
        cache.set("k", b"second".to_vec(), None).await.unwrap();
        assert_eq!(cache.get("k").await.unwrap(), Some(b"second".to_vec()));
    }

    #[tokio::test]
    async fn test_ttl_expiry() {
        let cache = MemoryCache::new();
        cache.set("ephemeral", b"data".to_vec(), Some(1)).await.unwrap();
        assert_eq!(cache.get("ephemeral").await.unwrap(), Some(b"data".to_vec()));
        tokio::time::sleep(tokio::time::Duration::from_secs(2)).await;
        assert_eq!(cache.get("ephemeral").await.unwrap(), None);
    }

    #[tokio::test]
    async fn test_expired_get_cleanup_preserves_fresh_value() {
        let cache = Arc::new(MemoryCache::new());
        cache.store.write().await.insert(
            "k".to_string(),
            MemoryEntry {
                value: b"old".to_vec(),
                expires_at: Some(Instant::now() - Duration::from_secs(1)),
                ttl: None,
            },
        );

        // Orchestrate the TOCTOU window deterministically: hold the write lock,
        // spawn a get() that queues on the read lock, then release and set() a
        // fresh value. The set()'s write request queues before get()'s cleanup
        // phase, so the old code would delete the fresh value.
        let write = cache.store.write().await;
        let get_task = tokio::spawn({
            let cache = cache.clone();
            async move { cache.get("k").await }
        });
        tokio::task::yield_now().await; // get() is now queued on the read lock
        drop(write);
        cache.set("k", b"new".to_vec(), None).await.unwrap();

        let result = get_task.await.unwrap().unwrap();
        // The expired path was exercised (get saw the expired entry)...
        assert_eq!(result, None);
        // ...and the fresh value set in between was not deleted.
        assert_eq!(cache.get("k").await.unwrap(), Some(b"new".to_vec()));
    }

    #[tokio::test]
    async fn test_incr_expired_entry_keeps_ttl() {
        let cache = MemoryCache::new();
        cache.store.write().await.insert(
            "counter".to_string(),
            MemoryEntry {
                value: b"5".to_vec(),
                expires_at: Some(Instant::now() - Duration::from_secs(1)),
                ttl: Some(Duration::from_secs(1)),
            },
        );

        let v = cache.incr("counter").await.unwrap();
        assert_eq!(v, 1);

        // The refreshed entry must still expire: TTL preserved, not None/never.
        let store = cache.store.read().await;
        let entry = store.get("counter").unwrap();
        assert!(entry.expires_at.is_some());
        assert!(entry.expires_at.unwrap() > Instant::now());
    }
}
