// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
use async_trait::async_trait;
use serde::{Deserialize, Serialize};
use std::collections::HashMap;
use std::sync::Mutex;
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
// In-memory engine
// ---------------------------------------------------------------------------

// ponytail: 内存实现，生产走 Elasticsearch feature；无持久化，重启丢数据
pub struct MemoryEngine {
    data: Mutex<HashMap<String, HashMap<DocumentId, Document>>>,
}

impl MemoryEngine {
    pub fn new() -> Self {
        Self { data: Mutex::new(HashMap::new()) }
    }
}

#[allow(clippy::needless_lifetimes)]
#[async_trait]
impl SearchEngine for MemoryEngine {
    async fn create_index(&self, name: &str, _mapping: Option<Mapping>) -> Result<(), SearchError> {
        let mut map = self.data.lock().unwrap_or_else(|e| e.into_inner());
        map.entry(name.to_string()).or_default();
        Ok(())
    }

    async fn delete_index(&self, name: &str) -> Result<(), SearchError> {
        let mut map = self.data.lock().unwrap_or_else(|e| e.into_inner());
        map.remove(name);
        Ok(())
    }

    async fn index(&self, index: &str, id: DocumentId, doc: Document) -> Result<(), SearchError> {
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

    async fn search(&self, index: &str, query: SearchQuery) -> Result<SearchResult, SearchError> {
        let needle = query.get("query").and_then(|v| v.as_str()).unwrap_or("");
        // Optional pagination, honored as `from`/`size` in the query JSON
        // (the gRPC layer passes these through from SearchRequest).
        let from = query.get("from").and_then(|v| v.as_u64()).unwrap_or(0) as usize;
        let size = query.get("size").and_then(|v| v.as_u64()).unwrap_or(u64::MAX) as usize;
        let map = self.data.lock().unwrap_or_else(|e| e.into_inner());
        let mut hits: Vec<SearchHit> = map
            .get(index)
            .map(|store| {
                store
                    .iter()
                    .filter(|(id, doc)| {
                        needle.is_empty() || id.contains(needle) || doc.to_string().contains(needle)
                    })
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
        hits.drain(..from.min(hits.len()));
        hits.truncate(size);
        Ok(SearchResult { total, hits, aggregations: None })
    }

    async fn scroll(&self, _handle: ScrollHandle) -> Result<SearchResult, SearchError> {
        Ok(SearchResult { total: 0, hits: Vec::new(), aggregations: None })
    }

    async fn aggregate(&self, _index: &str, _aggs: Aggregations) -> Result<AggResult, SearchError> {
        Ok(serde_json::Value::Object(serde_json::Map::new()))
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    // ------------------------------------------------------------------
    // Tests
    // ------------------------------------------------------------------

    #[tokio::test]
    async fn test_stub_create_and_index() {
        let engine = MemoryEngine::new();
        engine.create_index("posts", None).await.unwrap();
        engine.index("posts", "1".into(), serde_json::json!({"title": "hello"})).await.unwrap();
        let doc = engine.get("posts", &"1".into()).await.unwrap();
        assert!(doc.is_some());
    }

    #[tokio::test]
    async fn test_stub_search() {
        let engine = MemoryEngine::new();
        engine.create_index("items", None).await.unwrap();
        engine.index("items", "a".into(), serde_json::json!({"val": 1})).await.unwrap();
        engine.index("items", "b".into(), serde_json::json!({"val": 2})).await.unwrap();
        let result = engine.search("items", serde_json::json!({"match_all": {}})).await.unwrap();
        assert_eq!(result.total, 2);
    }

    #[tokio::test]
    async fn test_stub_aggregate() {
        let engine = MemoryEngine::new();
        engine.create_index("aggs", None).await.unwrap();
        let res = engine
            .aggregate("aggs", serde_json::json!({"avg_score": {"avg": {"field": "score"}}}))
            .await
            .unwrap();
        assert!(res.is_object());
    }

    #[tokio::test]
    async fn test_stub_scroll() {
        let engine = MemoryEngine::new();
        let result = engine.scroll("scroll-1".into()).await.unwrap();
        assert_eq!(result.total, 0);
    }

    #[tokio::test]
    async fn test_stub_delete() {
        let engine = MemoryEngine::new();
        engine.create_index("tmp", None).await.unwrap();
        engine.index("tmp", "x".into(), serde_json::json!({})).await.unwrap();
        engine.delete("tmp", &"x".into()).await.unwrap();
        assert!(engine.get("tmp", &"x".into()).await.unwrap().is_none());
    }

    #[tokio::test]
    async fn test_stub_bulk() {
        let engine = MemoryEngine::new();
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

    #[tokio::test]
    async fn test_delete_index_removes_docs() {
        let engine = MemoryEngine::new();
        engine.create_index("tmp", None).await.unwrap();
        engine.index("tmp", "1".into(), serde_json::json!({"a": 1})).await.unwrap();
        engine.delete_index("tmp").await.unwrap();
        let result = engine.search("tmp", serde_json::json!({"match_all": {}})).await.unwrap();
        assert_eq!(result.total, 0);
    }

    #[tokio::test]
    async fn test_search_missing_index_returns_zero() {
        let engine = MemoryEngine::new();
        let result = engine.search("ghost", serde_json::json!({"match_all": {}})).await.unwrap();
        assert_eq!(result.total, 0);
        assert!(result.hits.is_empty());
    }

    #[tokio::test]
    async fn test_search_matches_document_content() {
        let engine = MemoryEngine::new();
        engine.create_index("posts", None).await.unwrap();
        engine
            .index("posts", "1".into(), serde_json::json!({"title": "hello world"}))
            .await
            .unwrap();
        engine.index("posts", "2".into(), serde_json::json!({"title": "goodbye"})).await.unwrap();
        let result = engine.search("posts", serde_json::json!({"query": "hello"})).await.unwrap();
        assert_eq!(result.total, 1);
        assert_eq!(result.hits[0].id, "1");
        assert_eq!(result.hits[0].score, 1.0);
    }

    #[tokio::test]
    async fn test_search_empty_query_matches_all() {
        let engine = MemoryEngine::new();
        engine.create_index("items", None).await.unwrap();
        engine.index("items", "a".into(), serde_json::json!({"v": 1})).await.unwrap();
        engine.index("items", "b".into(), serde_json::json!({"v": 2})).await.unwrap();
        let result = engine.search("items", serde_json::json!({})).await.unwrap();
        assert_eq!(result.total, 2);
    }

    #[tokio::test]
    async fn test_get_missing_document_returns_none() {
        let engine = MemoryEngine::new();
        engine.create_index("idx", None).await.unwrap();
        assert!(engine.get("idx", &"nope".into()).await.unwrap().is_none());
    }

    #[tokio::test]
    async fn test_index_overwrites_existing_document() {
        let engine = MemoryEngine::new();
        engine.create_index("idx", None).await.unwrap();
        engine.index("idx", "1".into(), serde_json::json!({"v": 1})).await.unwrap();
        engine.index("idx", "1".into(), serde_json::json!({"v": 2})).await.unwrap();
        let doc = engine.get("idx", &"1".into()).await.unwrap().unwrap();
        assert_eq!(doc["v"], 2);
    }

    #[tokio::test]
    async fn test_search_honors_from_size_pagination() {
        let engine = MemoryEngine::new();
        engine.create_index("items", None).await.unwrap();
        for i in 1..=5 {
            engine.index("items", i.to_string(), serde_json::json!({"v": i})).await.unwrap();
        }
        // HashMap iteration order is unspecified, so compare against the
        // engine's own full result rather than assuming insertion order.
        let full = engine.search("items", serde_json::json!({"query": ""})).await.unwrap();
        let page = engine
            .search("items", serde_json::json!({"query": "", "from": 1, "size": 2}))
            .await
            .unwrap();
        // total reflects all matches; hits are the requested page only.
        assert_eq!(page.total, 5);
        assert_eq!(page.hits.len(), 2);
        let full_ids: Vec<&str> = full.hits.iter().map(|h| h.id.as_str()).collect();
        let page_ids: Vec<&str> = page.hits.iter().map(|h| h.id.as_str()).collect();
        assert_eq!(page_ids, full_ids[1..3]);
        // A page past the end yields empty hits while preserving total.
        let tail = engine
            .search("items", serde_json::json!({"query": "", "from": 10, "size": 2}))
            .await
            .unwrap();
        assert_eq!(tail.total, 5);
        assert!(tail.hits.is_empty());
    }

    #[tokio::test]
    async fn test_search_result_serde_roundtrip() {
        let mut result = SearchResult { total: 1, hits: Vec::new(), aggregations: None };
        result.hits.push(SearchHit {
            id: "1".into(),
            score: 0.9,
            source: serde_json::json!({"t": "x"}),
        });
        let json = serde_json::to_string(&result).unwrap();
        let back: SearchResult = serde_json::from_str(&json).unwrap();
        assert_eq!(back.total, 1);
        assert_eq!(back.hits[0].id, "1");
        assert_eq!(back.hits[0].score, 0.9);
    }
}
