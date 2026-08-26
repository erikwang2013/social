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

    #[tokio::test]
    async fn test_session_delete_and_is_empty() {
        let cache: Arc<dyn Cache> = Arc::new(MemoryCache::new());
        let mut session = Session::new(cache, Duration::from_secs(3600));
        assert!(session.is_empty());
        session.set("k", &1_i32).unwrap();
        assert!(!session.is_empty());
        session.delete("k");
        assert!(session.is_empty());
        assert!(session.get::<i32>("k").unwrap().is_none());
    }

    #[tokio::test]
    async fn test_session_load_missing_errors() {
        let cache: Arc<dyn Cache> = Arc::new(MemoryCache::new());
        let err = match Session::load(cache, "does-not-exist", Duration::from_secs(3600)).await {
            Ok(_) => panic!("loading a missing session must fail"),
            Err(e) => e,
        };
        assert!(matches!(err, SessionError::CacheError(CacheError::NotFound)));
    }

    #[tokio::test]
    async fn test_session_refresh_picks_up_external_changes() {
        let cache: Arc<dyn Cache> = Arc::new(MemoryCache::new());
        let mut session = Session::new(cache.clone(), Duration::from_secs(3600));
        session.set("user", &"alice").unwrap();
        session.save().await.unwrap();
        let id = session.id().to_string();

        // Another handle modifies the persisted session.
        let mut other = Session::load(cache.clone(), &id, Duration::from_secs(3600)).await.unwrap();
        other.set("user", &"bob").unwrap();
        other.save().await.unwrap();

        session.refresh().await.unwrap();
        let user: Option<String> = session.get("user").unwrap();
        assert_eq!(user, Some("bob".to_string()));
    }

    #[tokio::test]
    async fn test_session_sub_second_ttl_floor_is_one_second() {
        let cache: Arc<dyn Cache> = Arc::new(MemoryCache::new());
        let mut session = Session::new(cache.clone(), Duration::from_millis(500));
        session.set("k", &"v").unwrap();
        session.save().await.unwrap();
        // A 0-second TTL would expire immediately; the floor keeps it alive.
        let loaded = Session::load(cache, session.id(), Duration::from_secs(3600)).await.unwrap();
        assert_eq!(loaded.get::<String>("k").unwrap(), Some("v".to_string()));
    }

    #[test]
    fn test_session_get_wrong_type_errors() {
        let cache: Arc<dyn Cache> = Arc::new(MemoryCache::new());
        let mut session = Session::new(cache, Duration::from_secs(3600));
        session.set("num", &"not-a-number").unwrap();
        let err = session.get::<i32>("num").unwrap_err();
        assert!(matches!(err, SessionError::DeserializeError(_)));
    }

    #[test]
    fn test_session_ids_are_unique() {
        let cache: Arc<dyn Cache> = Arc::new(MemoryCache::new());
        let a = Session::new(cache.clone(), Duration::from_secs(3600));
        let b = Session::new(cache, Duration::from_secs(3600));
        assert_ne!(a.id(), b.id());
        assert_eq!(a.id().len(), 36); // UUID v4
    }
}
