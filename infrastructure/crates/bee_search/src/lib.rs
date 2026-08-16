// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
use async_trait::async_trait;
use serde::{Deserialize, Serialize};
use thiserror::Error;

#[cfg(feature = "clickhouse")]
pub mod clickhouse;
#[cfg(feature = "elasticsearch")]
pub mod elasticsearch;
#[cfg(feature = "opensearch")]
pub mod opensearch;

// ---------------------------------------------------------------------------
// Public types
// ---------------------------------------------------------------------------

/// A document stored in or returned by a search engine.
pub type Document = serde_json::Value;

/// A unique identifier for a stored document.
pub type DocumentId = String;

/// Index mapping / schema definition (backend-specific JSON).
pub type Mapping = serde_json::Value;

/// A free-form search query accepted by a search engine.
pub type SearchQuery = serde_json::Value;

/// A collection of aggregation definitions.
pub type Aggregations = serde_json::Value;

/// A single aggregation result bucket.
pub type AggResult = serde_json::Value;

/// A handle for scrolling through large result sets.
pub type ScrollHandle = String;

/// The result payload returned by a search or scroll operation.
#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct SearchResult {
    /// Total number of matching documents.
    pub total: u64,

    /// Individual hits in this page.
    pub hits: Vec<SearchHit>,

    /// Aggregation results, if any were requested.
    pub aggregations: Option<Aggregations>,
}

/// A single search hit.
#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct SearchHit {
    /// Document ID.
    pub id: DocumentId,

    /// Relevance score assigned by the engine.
    pub score: f64,

    /// The full document source.
    pub source: Document,
}

/// Result of a bulk-indexing operation.
#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct BulkResult {
    /// Number of items that were successfully indexed.
    pub indexed: u64,

    /// Diagnostic messages or per-item errors (if any).
    pub errors: Vec<String>,
}

// ---------------------------------------------------------------------------
// Errors
// ---------------------------------------------------------------------------

/// Errors that can occur when interacting with a search engine.
#[derive(Error, Debug)]
pub enum SearchError {
    /// The index does not exist or cannot be created.
    #[error("index error: {0}")]
    IndexError(String),

    /// The query is malformed or cannot be executed.
    #[error("query error: {0}")]
    QueryError(String),

    /// The connection to the search backend failed.
    #[error("connection error: {0}")]
    ConnectionError(String),
}

// ---------------------------------------------------------------------------
// Trait
// ---------------------------------------------------------------------------

/// A generic async full-text search engine trait.
///
/// Backends such as Elasticsearch, OpenSearch, or ClickHouse can implement
/// this trait to provide CRUD, search, scroll, and aggregation operations.
#[async_trait]
pub trait SearchEngine: Send + Sync {
    /// Create a new index with the given `name` and optional `mapping`.
    async fn create_index(&self, name: &str, mapping: Option<Mapping>) -> Result<(), SearchError>;

    /// Delete the index identified by `name`.
    async fn delete_index(&self, name: &str) -> Result<(), SearchError>;

    /// Index (or re-index) a single document into `index`.
    async fn index(&self, index: &str, id: DocumentId, doc: Document) -> Result<(), SearchError>;

    /// Bulk-index multiple documents into `index`.
    /// Each tuple is `(DocumentId, Document)`.
    async fn bulk_index(
        &self,
        index: &str,
        docs: &[(DocumentId, Document)],
    ) -> Result<BulkResult, SearchError>;

    /// Retrieve a single document by `id` from `index`.
    async fn get(&self, index: &str, id: &DocumentId) -> Result<Option<Document>, SearchError>;

    /// Delete the document identified by `id` from `index`.
    async fn delete(&self, index: &str, id: &DocumentId) -> Result<(), SearchError>;

    /// Execute a search `query` against `index`.
    async fn search(&self, index: &str, query: SearchQuery) -> Result<SearchResult, SearchError>;

    /// Open a scroll handle for iterating through results.
    async fn scroll(&self, handle: ScrollHandle) -> Result<SearchResult, SearchError>;

    /// Run aggregation queries against `index`.
    async fn aggregate(&self, index: &str, aggs: Aggregations) -> Result<AggResult, SearchError>;
}

// ---------------------------------------------------------------------------
// Tests — in-memory stub engine
// ---------------------------------------------------------------------------

#[cfg(test)]
mod tests {
    use super::*;
    use std::collections::HashMap;
    use std::sync::Mutex;

    /// An in-memory stub that satisfies [`SearchEngine`] for testing.
    pub struct StubEngine {
        data: Mutex<HashMap<String, HashMap<DocumentId, Document>>>,
    }

    impl StubEngine {
        pub fn new() -> Self {
            Self { data: Mutex::new(HashMap::new()) }
        }
    }

    #[allow(clippy::needless_lifetimes)]
    #[async_trait]
    impl SearchEngine for StubEngine {
        async fn create_index(
            &self,
            name: &str,
            _mapping: Option<Mapping>,
        ) -> Result<(), SearchError> {
            let mut map = self.data.lock().unwrap_or_else(|e| e.into_inner());
            map.entry(name.to_string()).or_default();
            Ok(())
        }

        async fn delete_index(&self, name: &str) -> Result<(), SearchError> {
            let mut map = self.data.lock().unwrap_or_else(|e| e.into_inner());
            map.remove(name);
            Ok(())
        }

        async fn index(
            &self,
            index: &str,
            id: DocumentId,
            doc: Document,
        ) -> Result<(), SearchError> {
            let mut map = self.data.lock().unwrap_or_else(|e| e.into_inner());
            let store = map.entry(index.to_string()).or_default();
            store.insert(id, doc);
            Ok(())
        }

        async fn bulk_index(
            &self,
            index: &str,
            docs: &[(DocumentId, Document)],
        ) -> Result<BulkResult, SearchError> {
            let mut map = self.data.lock().unwrap_or_else(|e| e.into_inner());
            let store = map.entry(index.to_string()).or_default();
            let count = docs.len() as u64;
            for (id, doc) in docs {
                store.insert(id.clone(), doc.clone());
            }
            Ok(BulkResult { indexed: count, errors: Vec::new() })
        }

        async fn get(&self, index: &str, id: &DocumentId) -> Result<Option<Document>, SearchError> {
            let map = self.data.lock().unwrap_or_else(|e| e.into_inner());
            let doc = map.get(index).and_then(|store| store.get(id)).cloned();
            Ok(doc)
        }

        async fn delete(&self, index: &str, id: &DocumentId) -> Result<(), SearchError> {
            let mut map = self.data.lock().unwrap_or_else(|e| e.into_inner());
            if let Some(store) = map.get_mut(index) {
                store.remove(id);
            }
            Ok(())
        }

        async fn search(
            &self,
            index: &str,
            _query: SearchQuery,
        ) -> Result<SearchResult, SearchError> {
            let map = self.data.lock().unwrap_or_else(|e| e.into_inner());
            let hits: Vec<SearchHit> = map
                .get(index)
                .map(|store| {
                    store
                        .iter()
                        .enumerate()
                        .map(|(i, (id, doc))| SearchHit {
                            id: id.clone(),
                            score: 1.0 / (i as f64 + 1.0),
                            source: doc.clone(),
                        })
                        .collect()
                })
                .unwrap_or_default();
            let total = hits.len() as u64;
            Ok(SearchResult { total, hits, aggregations: None })
        }

        async fn scroll(&self, _handle: ScrollHandle) -> Result<SearchResult, SearchError> {
            Ok(SearchResult { total: 0, hits: Vec::new(), aggregations: None })
        }

        async fn aggregate(
            &self,
            _index: &str,
            _aggs: Aggregations,
        ) -> Result<AggResult, SearchError> {
            Ok(serde_json::Value::Object(serde_json::Map::new()))
        }
    }

    // ------------------------------------------------------------------
    // Tests
    // ------------------------------------------------------------------

    #[tokio::test]
    async fn test_stub_create_and_index() {
        let engine = StubEngine::new();
        engine.create_index("posts", None).await.unwrap();
        engine.index("posts", "1".into(), serde_json::json!({"title": "hello"})).await.unwrap();
        let doc = engine.get("posts", &"1".into()).await.unwrap();
        assert!(doc.is_some());
    }

    #[tokio::test]
    async fn test_stub_search() {
        let engine = StubEngine::new();
        engine.create_index("items", None).await.unwrap();
        engine.index("items", "a".into(), serde_json::json!({"val": 1})).await.unwrap();
        engine.index("items", "b".into(), serde_json::json!({"val": 2})).await.unwrap();
        let result = engine.search("items", serde_json::json!({"match_all": {}})).await.unwrap();
        assert_eq!(result.total, 2);
    }

    #[tokio::test]
    async fn test_stub_aggregate() {
        let engine = StubEngine::new();
        engine.create_index("aggs", None).await.unwrap();
        let res = engine
            .aggregate("aggs", serde_json::json!({"avg_score": {"avg": {"field": "score"}}}))
            .await
            .unwrap();
        assert!(res.is_object());
    }

    #[tokio::test]
    async fn test_stub_scroll() {
        let engine = StubEngine::new();
        let result = engine.scroll("scroll-1".into()).await.unwrap();
        assert_eq!(result.total, 0);
    }

    #[tokio::test]
    async fn test_stub_delete() {
        let engine = StubEngine::new();
        engine.create_index("tmp", None).await.unwrap();
        engine.index("tmp", "x".into(), serde_json::json!({})).await.unwrap();
        engine.delete("tmp", &"x".into()).await.unwrap();
        assert!(engine.get("tmp", &"x".into()).await.unwrap().is_none());
    }

    #[tokio::test]
    async fn test_stub_bulk() {
        let engine = StubEngine::new();
        engine.create_index("bulk", None).await.unwrap();
        let res = engine
            .bulk_index(
                "bulk",
                &[
                    ("1".into(), serde_json::json!({"a": 1})),
                    ("2".into(), serde_json::json!({"a": 2})),
                    ("3".into(), serde_json::json!({"a": 3})),
                ],
            )
            .await
            .unwrap();
        assert_eq!(res.indexed, 3);
    }
}
