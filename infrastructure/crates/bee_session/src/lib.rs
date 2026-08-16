// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
use std::collections::HashMap;
use std::sync::Arc;
use std::time::Duration;

use bee_cache::{Cache, CacheError};
use serde::{Serialize, de::DeserializeOwned};

#[derive(Debug, thiserror::Error)]
pub enum SessionError {
    #[error("serialization error: {0}")]
    SerializeError(String),
    #[error("deserialization error: {0}")]
    DeserializeError(String),
    #[error("cache error: {0}")]
    CacheError(#[from] CacheError),
}

pub struct Session {
    id: String,
    data: HashMap<String, String>,
    cache: Arc<dyn Cache>,
    ttl: Duration,
}

impl Session {
    /// Create a new session with a random UUID v4.
    pub fn new(cache: Arc<dyn Cache>, ttl: Duration) -> Self {
        Self { id: uuid::Uuid::new_v4().to_string(), data: HashMap::new(), cache, ttl }
    }

    /// Load an existing session from cache by id.
    pub async fn load(
        cache: Arc<dyn Cache>,
        id: &str,
        ttl: Duration,
    ) -> Result<Self, SessionError> {
        let bytes = cache.get(id).await?.ok_or(CacheError::NotFound)?;

        let data: HashMap<String, String> = serde_json::from_slice(&bytes)
            .map_err(|e| SessionError::DeserializeError(e.to_string()))?;

        Ok(Self { id: id.to_string(), data, cache, ttl })
    }

    /// Return the session ID.
    pub fn id(&self) -> &str {
        &self.id
    }

    /// Set a value in the session, encoded as JSON.
    pub fn set<T: Serialize>(&mut self, key: &str, value: &T) -> Result<(), SessionError> {
        let json = serde_json::to_string(value)
            .map_err(|e| SessionError::SerializeError(e.to_string()))?;
        self.data.insert(key.to_string(), json);
        Ok(())
    }

    /// Get a value from the session, decoded from JSON.
    /// Returns `None` if the key is not present.
    pub fn get<T: DeserializeOwned>(&self, key: &str) -> Result<Option<T>, SessionError> {
        match self.data.get(key) {
            Some(json) => {
                let value = serde_json::from_str(json)
                    .map_err(|e| SessionError::DeserializeError(e.to_string()))?;
                Ok(Some(value))
            }
            None => Ok(None),
        }
    }

    /// Delete a key from the session.
    pub fn delete(&mut self, key: &str) {
        self.data.remove(key);
    }

    /// Whether the session holds no data yet.
    pub fn is_empty(&self) -> bool {
        self.data.is_empty()
    }

    /// Persist the session data to the cache backend.
    pub async fn save(&self) -> Result<(), SessionError> {
        let json = serde_json::to_vec(&self.data)
            .map_err(|e| SessionError::SerializeError(e.to_string()))?;
        // Sub-second TTLs would truncate to 0 and expire immediately.
        let ttl_secs = self.ttl.as_secs().max(1);
        self.cache.set(&self.id, json, Some(ttl_secs)).await.map_err(SessionError::from)
    }

    /// Refresh session data from the cache backend.
    pub async fn refresh(&mut self) -> Result<(), SessionError> {
        let bytes = self.cache.get(&self.id).await?.ok_or(CacheError::NotFound)?;

        self.data = serde_json::from_slice(&bytes)
            .map_err(|e| SessionError::DeserializeError(e.to_string()))?;
        Ok(())
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use bee_cache::MemoryCache;

    #[tokio::test]
    async fn test_session_set_get() {
        let cache: Arc<dyn Cache> = Arc::new(MemoryCache::new());
        let mut session = Session::new(cache, Duration::from_secs(3600));

        session.set("username", &"alice").unwrap();
        session.set("age", &30_i32).unwrap();

        let username: Option<String> = session.get("username").unwrap();
        assert_eq!(username, Some("alice".to_string()));

        let age: Option<i32> = session.get("age").unwrap();
        assert_eq!(age, Some(30));

        // Non-existent key
        let missing: Option<String> = session.get("missing").unwrap();
        assert!(missing.is_none());
    }

    #[tokio::test]
    async fn test_session_save_load() {
        let cache: Arc<dyn Cache> = Arc::new(MemoryCache::new());

        // Create and populate a session, then save it
        let session_id = {
            let mut session = Session::new(cache.clone(), Duration::from_secs(3600));
            session.set("theme", &"dark").unwrap();
            session.save().await.unwrap();
            session.id().to_string()
        };

        // Load the session from cache using the same id
        let loaded =
            Session::load(cache.clone(), &session_id, Duration::from_secs(3600)).await.unwrap();

        let theme: Option<String> = loaded.get("theme").unwrap();
        assert_eq!(theme, Some("dark".to_string()));
    }
}
