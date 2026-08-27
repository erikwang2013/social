// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
use async_trait::async_trait;

use crate::{KvError, KvStore};

/// A [`KvStore`] backed by a Redis server over an async multiplexed
/// connection.
#[cfg(feature = "redis")]
pub struct RedisStore {
    conn: redis::aio::MultiplexedConnection,
}

#[cfg(feature = "redis")]
impl RedisStore {
    /// Create a new `RedisStore` by connecting to the given `addr` (e.g.
    /// `"redis://127.0.0.1:6379"`).
    pub async fn new(addr: &str) -> Result<Self, KvError> {
        let client = redis::Client::open(addr)
            .map_err(|e| KvError::ConnectionError(format!("failed to create client: {e}")))?;
        let conn = client
            .get_multiplexed_async_connection()
            .await
            .map_err(|e| KvError::ConnectionError(format!("failed to connect: {e}")))?;
        Ok(Self { conn })
    }
}

#[cfg(feature = "redis")]
#[async_trait]
impl KvStore for RedisStore {
    async fn get(&self, key: &str) -> Result<Option<String>, KvError> {
        redis::cmd("GET")
            .arg(key)
            .query_async(&mut self.conn.clone())
            .await
            .map_err(|e| KvError::OperationFailed(e.to_string()))
    }

    async fn set(&self, key: &str, value: &str) -> Result<(), KvError> {
        redis::cmd("SET")
            .arg(key)
            .arg(value)
            .query_async(&mut self.conn.clone())
            .await
            .map_err(|e| KvError::OperationFailed(e.to_string()))
    }

    async fn del(&self, key: &str) -> Result<(), KvError> {
        redis::cmd("DEL")
            .arg(key)
            .query_async(&mut self.conn.clone())
            .await
            .map_err(|e| KvError::OperationFailed(e.to_string()))
    }

    async fn exists(&self, key: &str) -> Result<bool, KvError> {
        redis::cmd("EXISTS")
            .arg(key)
            .query_async(&mut self.conn.clone())
            .await
            .map_err(|e| KvError::OperationFailed(e.to_string()))
    }

    async fn incr(&self, key: &str, amount: i64) -> Result<i64, KvError> {
        if amount == 1 {
            redis::cmd("INCR")
                .arg(key)
                .query_async(&mut self.conn.clone())
                .await
                .map_err(|e| KvError::OperationFailed(e.to_string()))
        } else {
            redis::cmd("INCRBY")
                .arg(key)
                .arg(amount)
                .query_async(&mut self.conn.clone())
                .await
                .map_err(|e| KvError::OperationFailed(e.to_string()))
        }
    }

    async fn expire(&self, key: &str, seconds: i64) -> Result<(), KvError> {
        redis::cmd("EXPIRE")
            .arg(key)
            .arg(seconds)
            .query_async(&mut self.conn.clone())
            .await
            .map_err(|e| KvError::OperationFailed(e.to_string()))
    }

    async fn mget(&self, keys: &[&str]) -> Result<Vec<Option<String>>, KvError> {
        if keys.is_empty() {
            return Ok(Vec::new());
        }
        redis::cmd("MGET")
            .arg(keys)
            .query_async(&mut self.conn.clone())
            .await
            .map_err(|e| KvError::OperationFailed(e.to_string()))
    }

    async fn mset(&self, pairs: &[(&str, &str)]) -> Result<(), KvError> {
        if pairs.is_empty() {
            return Ok(());
        }
        let mut cmd = redis::cmd("MSET");
        for (k, v) in pairs {
            cmd.arg(k).arg(v);
        }
        cmd.query_async(&mut self.conn.clone())
            .await
            .map_err(|e| KvError::OperationFailed(e.to_string()))
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    /// Unique key prefix per run so parallel test runs never collide.
    fn key(name: &str) -> String {
        let nanos =
            std::time::SystemTime::now().duration_since(std::time::UNIX_EPOCH).unwrap().as_nanos();
        format!("bee_kv_redis_test:{pid}:{nanos}:{name}", pid = std::process::id())
    }

    /// Connect to a local Redis; return None (test is skipped) when
    /// unavailable so the suite still passes on machines without Redis.
    async fn store() -> Option<RedisStore> {
        match RedisStore::new("redis://127.0.0.1:6379").await {
            Ok(s) => Some(s),
            Err(e) => {
                eprintln!("SKIP: local Redis unavailable: {e}");
                None
            }
        }
    }

    #[tokio::test]
    async fn redis_set_get_del_roundtrip() {
        let Some(store) = store().await else { return };
        let k = key("roundtrip");
        assert_eq!(store.get(&k).await.unwrap(), None);
        store.set(&k, "v1").await.unwrap();
        assert!(store.exists(&k).await.unwrap());
        assert_eq!(store.get(&k).await.unwrap(), Some("v1".into()));
        store.del(&k).await.unwrap();
        assert!(!store.exists(&k).await.unwrap());
    }

    #[tokio::test]
    async fn redis_incr_and_expire() {
        let Some(store) = store().await else { return };
        let k = key("incr");
        store.del(&k).await.ok();
        assert_eq!(store.incr(&k, 1).await.unwrap(), 1);
        assert_eq!(store.incr(&k, 4).await.unwrap(), 5);
        assert_eq!(store.incr(&k, -2).await.unwrap(), 3);
        store.expire(&k, 1).await.unwrap();
        store.del(&k).await.ok();
    }

    #[tokio::test]
    async fn redis_mset_mget() {
        let Some(store) = store().await else { return };
        let (a, b) = (key("mset-a"), key("mset-b"));
        store.mset(&[(&a, "1"), (&b, "2")]).await.unwrap();
        let vals = store.mget(&[&a, &b, "bee_kv_no_such_key"]).await.unwrap();
        assert_eq!(vals, vec![Some("1".into()), Some("2".into()), None]);
        store.del(&a).await.unwrap();
        store.del(&b).await.unwrap();
    }
}
