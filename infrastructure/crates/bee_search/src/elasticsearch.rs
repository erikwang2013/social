// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
use async_trait::async_trait;
use reqwest::{Client, StatusCode};

use crate::{
    AggResult, Aggregations, BulkResult, Document, DocumentId, Mapping, ScrollHandle, SearchEngine,
    SearchError, SearchHit, SearchQuery, SearchResult,
};

/// Elasticsearch driver backed by its REST API.
pub struct Elasticsearch {
    client: Client,
    base_url: String,
}

impl Elasticsearch {
    pub fn new(base_url: impl Into<String>) -> Self {
        Self { client: Client::new(), base_url: base_url.into() }
    }
}

#[async_trait]
impl SearchEngine for Elasticsearch {
    async fn create_index(&self, name: &str, mapping: Option<Mapping>) -> Result<(), SearchError> {
        let res = self
            .client
            .put(format!("{}/{name}", self.base_url))
            .json(&mapping.unwrap_or_default())
            .send()
            .await
            .map_err(conn_err)?;
        check_status(res, "create_index").await
    }

    async fn delete_index(&self, name: &str) -> Result<(), SearchError> {
        let res = self
            .client
            .delete(format!("{}/{name}", self.base_url))
            .send()
            .await
            .map_err(conn_err)?;
        check_status(res, "delete_index").await
    }

    async fn index(&self, index: &str, id: DocumentId, doc: Document) -> Result<(), SearchError> {
        let res = self
            .client
            .put(format!("{}/{index}/_doc/{id}", self.base_url))
            .json(&doc)
            .send()
            .await
            .map_err(conn_err)?;
        check_status(res, "index").await
    }

    async fn bulk_index(
        &self,
        index: &str,
        docs: &[(DocumentId, Document)],
    ) -> Result<BulkResult, SearchError> {
        // NDJSON: one action line + one document line per item.
        let mut body = String::new();
        for (id, doc) in docs {
            body.push_str(&format!("{{\"index\":{{\"_index\":\"{index}\",\"_id\":\"{id}\"}}}}\n"));
            body.push_str(&doc.to_string());
            body.push('\n');
        }
        let res = self
            .client
            .post(format!("{}/_bulk", self.base_url))
            .header("content-type", "application/x-ndjson")
            .body(body)
            .send()
            .await
            .map_err(conn_err)?;
        if !res.status().is_success() {
            return Err(http_error(res, "bulk_index").await);
        }
        let payload: serde_json::Value = res.json().await.map_err(query_err)?;
        let indexed = payload["items"].as_array().map(|i| i.len() as u64).unwrap_or(0);
        Ok(BulkResult { indexed, errors: Vec::new() })
    }

    async fn get(&self, index: &str, id: &DocumentId) -> Result<Option<Document>, SearchError> {
        let res = self
            .client
            .get(format!("{}/{index}/_doc/{id}", self.base_url))
            .send()
            .await
            .map_err(conn_err)?;
        if res.status() == StatusCode::NOT_FOUND {
            return Ok(None);
        }
        if !res.status().is_success() {
            return Err(http_error(res, "get").await);
        }
        let payload: serde_json::Value = res.json().await.map_err(query_err)?;
        Ok(payload.get("_source").cloned().filter(|v| !v.is_null()))
    }

    async fn delete(&self, index: &str, id: &DocumentId) -> Result<(), SearchError> {
        let res = self
            .client
            .delete(format!("{}/{index}/_doc/{id}", self.base_url))
            .send()
            .await
            .map_err(conn_err)?;
        check_status(res, "delete").await
    }

    async fn search(&self, index: &str, query: SearchQuery) -> Result<SearchResult, SearchError> {
        let res = self
            .client
            .post(format!("{}/{index}/_search", self.base_url))
            .json(&query)
            .send()
            .await
            .map_err(conn_err)?;
        if !res.status().is_success() {
            return Err(http_error(res, "search").await);
        }
        let payload: serde_json::Value = res.json().await.map_err(query_err)?;
        Ok(parse_hits(&payload))
    }

    async fn scroll(&self, handle: ScrollHandle) -> Result<SearchResult, SearchError> {
        let res = self
            .client
            .post(format!("{}/_search/scroll", self.base_url))
            .json(&serde_json::json!({ "scroll_id": handle }))
            .send()
            .await
            .map_err(conn_err)?;
        if !res.status().is_success() {
            return Err(http_error(res, "scroll").await);
        }
        let payload: serde_json::Value = res.json().await.map_err(query_err)?;
        Ok(parse_hits(&payload))
    }

    async fn aggregate(&self, index: &str, aggs: Aggregations) -> Result<AggResult, SearchError> {
        let query = serde_json::json!({ "size": 0, "aggs": aggs });
        let res = self
            .client
            .post(format!("{}/{index}/_search", self.base_url))
            .json(&query)
            .send()
            .await
            .map_err(conn_err)?;
        if !res.status().is_success() {
            return Err(http_error(res, "aggregate").await);
        }
        let payload: serde_json::Value = res.json().await.map_err(query_err)?;
        Ok(payload.get("aggregations").cloned().unwrap_or_default())
    }
}

fn parse_hits(payload: &serde_json::Value) -> SearchResult {
    let total = payload["hits"]["total"]["value"].as_u64().unwrap_or(0);
    let hits: Vec<SearchHit> = payload["hits"]["hits"]
        .as_array()
        .map(|arr| {
            arr.iter()
                .map(|h| SearchHit {
                    id: h["_id"].as_str().unwrap_or_default().to_string(),
                    score: h["_score"].as_f64().unwrap_or(0.0),
                    source: h["_source"].clone(),
                })
                .collect()
        })
        .unwrap_or_default();
    let aggregations = payload.get("aggregations").cloned();
    SearchResult { total, hits, aggregations }
}

async fn check_status(res: reqwest::Response, op: &str) -> Result<(), SearchError> {
    if res.status().is_success() { Ok(()) } else { Err(http_error(res, op).await) }
}

async fn http_error(res: reqwest::Response, op: &str) -> SearchError {
    let status = res.status();
    let body = res.text().await.unwrap_or_default();
    SearchError::QueryError(format!("elasticsearch {op} failed with {status}: {body}"))
}

fn conn_err(e: reqwest::Error) -> SearchError {
    SearchError::ConnectionError(e.to_string())
}

fn query_err(e: reqwest::Error) -> SearchError {
    SearchError::QueryError(e.to_string())
}

#[cfg(test)]
mod tests {
    use super::*;
    use axum::body::Body;
    use axum::http::{Request, StatusCode};
    use axum::routing::{get, post};

    async fn mock(routes: Vec<(&str, axum::routing::MethodRouter)>) -> String {
        let mut app = axum::Router::new();
        for (path, router) in routes {
            app = app.route(path, router);
        }
        let listener = tokio::net::TcpListener::bind("127.0.0.1:0").await.unwrap();
        let addr = listener.local_addr().unwrap();
        tokio::spawn(async move { axum::serve(listener, app).await.unwrap() });
        format!("http://{addr}")
    }

    fn hit_payload() -> serde_json::Value {
        serde_json::json!({
            "hits": {
                "total": { "value": 1 },
                "hits": [{ "_id": "1", "_score": 0.5, "_source": { "title": "hello" } }]
            }
        })
    }

    #[tokio::test]
    async fn get_returns_document() {
        let base = mock(vec![(
            "/posts/_doc/1",
            get(|| async {
                (StatusCode::OK, axum::Json(serde_json::json!({"_source": {"title": "hello"}})))
            }),
        )])
        .await;
        let engine = Elasticsearch::new(base);
        let doc = engine.get("posts", &"1".into()).await.unwrap().unwrap();
        assert_eq!(doc["title"], "hello");
    }

    #[tokio::test]
    async fn get_missing_document_returns_none() {
        let base = mock(vec![("/posts/_doc/404", get(|| async { StatusCode::NOT_FOUND }))]).await;
        let engine = Elasticsearch::new(base);
        assert!(engine.get("posts", &"404".into()).await.unwrap().is_none());
    }

    #[tokio::test]
    async fn search_parses_hits_and_total() {
        let base = mock(vec![(
            "/posts/_search",
            post(|| async { (StatusCode::OK, axum::Json(hit_payload())) }),
        )])
        .await;
        let engine = Elasticsearch::new(base);
        let res = engine.search("posts", serde_json::json!({"match_all": {}})).await.unwrap();
        assert_eq!(res.total, 1);
        assert_eq!(res.hits[0].id, "1");
        assert_eq!(res.hits[0].source["title"], "hello");
    }

    #[tokio::test]
    async fn bulk_index_parses_item_count() {
        let base = mock(vec![(
            "/_bulk",
            post(|req: Request<Body>| async move {
                let body = axum::body::to_bytes(req.into_body(), 4096).await.unwrap();
                let text = String::from_utf8(body.to_vec()).unwrap();
                assert_eq!(text.lines().count(), 4, "2 docs = 2 action + 2 doc lines");
                (
                    StatusCode::OK,
                    axum::Json(serde_json::json!({"errors": false, "items": [{}, {}]})),
                )
            }),
        )])
        .await;
        let engine = Elasticsearch::new(base);
        let res = engine
            .bulk_index(
                "posts",
                &[
                    ("1".into(), serde_json::json!({"a": 1})),
                    ("2".into(), serde_json::json!({"a": 2})),
                ],
            )
            .await
            .unwrap();
        assert_eq!(res.indexed, 2);
    }

    #[tokio::test]
    async fn aggregate_extracts_aggs_payload() {
        let base = mock(vec![(
            "/posts/_search",
            post(|| async {
                (
                    StatusCode::OK,
                    axum::Json(serde_json::json!({"aggregations": {"avg_score": {"value": 42.0}}})),
                )
            }),
        )])
        .await;
        let engine = Elasticsearch::new(base);
        let res = engine
            .aggregate("posts", serde_json::json!({"avg_score": {"avg": {"field": "score"}}}))
            .await
            .unwrap();
        assert_eq!(res["avg_score"]["value"], 42.0);
    }
}
