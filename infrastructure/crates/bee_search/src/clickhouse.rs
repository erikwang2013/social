// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
use async_trait::async_trait;
use reqwest::Client;
use serde_json::{Value, json};

use crate::{
    AggResult, Aggregations, BulkResult, Document, DocumentId, Mapping, ScrollHandle, SearchEngine,
    SearchError, SearchHit, SearchQuery, SearchResult,
};

/// ClickHouse driver backed by its HTTP interface (SQL-over-HTTP with
/// `JSONEachRow` response format). Tables are created on demand; queries are
/// executed as raw SQL via the HTTP `query` parameter.
pub struct ClickHouse {
    client: Client,
    base_url: String,
}

impl ClickHouse {
    pub fn new(base_url: impl Into<String>) -> Self {
        Self { client: Client::new(), base_url: base_url.into() }
    }

    async fn exec(&self, sql: &str) -> Result<(), SearchError> {
        let res = self
            .client
            .post(&self.base_url)
            .query(&[("query", sql)])
            .send()
            .await
            .map_err(conn_err)?;
        check_status(res, "exec").await
    }
}

/// Escapes a string literal for SQL: single quotes are doubled.
fn escape(s: &str) -> String {
    s.replace('\'', "''")
}

/// Extracts the row's `id` column and removes it from the document, so
/// returned documents never expose the storage-side key (matches the
/// `_source` semantics of the ES/OS drivers).
fn take_id(row: &mut Value) -> DocumentId {
    let id = row.get("id").and_then(|v| v.as_str()).unwrap_or_default().to_string();
    if let Some(obj) = row.as_object_mut() {
        obj.remove("id");
    }
    id
}

/// Builds a `(name, escaped-value)` pair list for INSERT, treating all values
/// as strings (ClickHouse accepts quoted strings for any column).
fn insert_pairs(doc: &Value) -> Vec<(String, String)> {
    doc.as_object()
        .map(|obj| {
            obj.iter()
                .map(|(k, v)| {
                    let val = match v {
                        Value::Null => "NULL".to_string(),
                        Value::Bool(b) => {
                            if *b {
                                "1".into()
                            } else {
                                "0".into()
                            }
                        }
                        Value::Number(n) => n.to_string(),
                        _ => format!("'{}'", escape(v.as_str().unwrap_or(&v.to_string()))),
                    };
                    (k.clone(), val)
                })
                .collect()
        })
        .unwrap_or_default()
}

#[async_trait]
impl SearchEngine for ClickHouse {
    async fn create_index(&self, name: &str, mapping: Option<Mapping>) -> Result<(), SearchError> {
        let Some(mapping) = mapping else {
            return Err(SearchError::IndexError(
                "clickhouse create_index requires a mapping object of column -> type".into(),
            ));
        };
        let defs: Vec<String> = mapping
            .as_object()
            .map(|obj| {
                obj.iter()
                    .map(|(k, v)| {
                        format!("`{}` {}", k.replace('`', ""), v.as_str().unwrap_or("String"))
                    })
                    .collect()
            })
            .unwrap_or_default();
        if defs.is_empty() {
            return Err(SearchError::IndexError(
                "clickhouse create_index requires a mapping object of column -> type".into(),
            ));
        }
        let sql = format!(
            "CREATE TABLE IF NOT EXISTS `{name}` (`id` String, {}) ENGINE = MergeTree ORDER BY `id`",
            defs.join(", ")
        );
        self.exec(&sql).await
    }

    async fn delete_index(&self, name: &str) -> Result<(), SearchError> {
        self.exec(&format!("DROP TABLE IF EXISTS `{name}`")).await
    }

    async fn index(&self, index: &str, id: DocumentId, doc: Document) -> Result<(), SearchError> {
        let mut pairs = insert_pairs(&doc);
        pairs.push(("id".to_string(), format!("'{}'", escape(&id))));
        let cols = pairs.iter().map(|(k, _)| format!("`{k}`")).collect::<Vec<_>>().join(", ");
        let vals = pairs.iter().map(|(_, v)| v.clone()).collect::<Vec<_>>().join(", ");
        let sql = format!("INSERT INTO `{index}` ({cols}) VALUES ({vals})");
        self.exec(&sql).await
    }

    async fn bulk_index(
        &self,
        index: &str,
        docs: &[(DocumentId, Document)],
    ) -> Result<BulkResult, SearchError> {
        if docs.is_empty() {
            return Ok(BulkResult { indexed: 0, errors: Vec::new() });
        }
        // All rows share the first document's column shape (sorted key order
        // preserved by serde_json's Map, which is BTreeMap-backed).
        let mut pairs = insert_pairs(&docs[0].1);
        pairs.push(("id".to_string(), format!("'{}'", escape(&docs[0].0))));
        let cols = pairs.iter().map(|(k, _)| format!("`{k}`")).collect::<Vec<_>>().join(", ");
        let vals: Vec<String> = docs
            .iter()
            .map(|(id, doc)| {
                let mut p = insert_pairs(doc);
                p.push(("id".to_string(), format!("'{}'", escape(id))));
                format!("({})", p.iter().map(|(_, v)| v.clone()).collect::<Vec<_>>().join(", "))
            })
            .collect();
        let sql = format!("INSERT INTO `{index}` ({cols}) VALUES {}", vals.join(", "));
        self.exec(&sql).await?;
        Ok(BulkResult { indexed: docs.len() as u64, errors: Vec::new() })
    }

    async fn get(&self, index: &str, id: &DocumentId) -> Result<Option<Document>, SearchError> {
        let sql = format!("SELECT * FROM `{index}` WHERE `id` = '{}' LIMIT 1", escape(id));
        let mut row = self.query_rows(&sql).await?.into_iter().next();
        if let Some(doc) = row.as_mut() {
            take_id(doc);
        }
        Ok(row)
    }

    async fn delete(&self, index: &str, id: &DocumentId) -> Result<(), SearchError> {
        self.exec(&format!("ALTER TABLE `{index}` DELETE WHERE `id` = '{}'", escape(id))).await
    }

    async fn search(&self, index: &str, query: SearchQuery) -> Result<SearchResult, SearchError> {
        let sql = query
            .get("sql")
            .and_then(|s| s.as_str())
            .map(str::to_string)
            .unwrap_or_else(|| format!("SELECT * FROM `{index}`"));
        let mut rows = self.query_rows(&sql).await?;
        let hits: Vec<SearchHit> = rows
            .drain(..)
            .map(|mut row| {
                let id = take_id(&mut row);
                SearchHit { id, score: 1.0, source: row }
            })
            .collect();
        let total = hits.len() as u64;
        Ok(SearchResult { total, hits, aggregations: None })
    }

    async fn scroll(&self, _handle: ScrollHandle) -> Result<SearchResult, SearchError> {
        // ClickHouse has no scroll API; callers should re-run search instead.
        Ok(SearchResult { total: 0, hits: Vec::new(), aggregations: None })
    }

    async fn aggregate(&self, index: &str, aggs: Aggregations) -> Result<AggResult, SearchError> {
        let sql = aggs
            .get("sql")
            .and_then(|s| s.as_str())
            .map(str::to_string)
            .unwrap_or_else(|| format!("SELECT count() AS count FROM `{index}`"));
        let rows = self.query_rows(&sql).await?;
        Ok(json!({ "rows": rows }))
    }
}

impl ClickHouse {
    /// Runs `sql` and returns the parsed `JSONEachRow` result set.
    async fn query_rows(&self, sql: &str) -> Result<Vec<Value>, SearchError> {
        let res = self
            .client
            .post(&self.base_url)
            .query(&[("query", sql)])
            .send()
            .await
            .map_err(conn_err)?;
        if !res.status().is_success() {
            return Err(http_error(res, "query").await);
        }
        let text = res.text().await.map_err(query_err)?;
        if text.trim().is_empty() {
            return Ok(Vec::new());
        }
        text.lines()
            .map(|line| {
                serde_json::from_str(line).map_err(|e| SearchError::QueryError(e.to_string()))
            })
            .collect()
    }
}

async fn check_status(res: reqwest::Response, op: &str) -> Result<(), SearchError> {
    if res.status().is_success() { Ok(()) } else { Err(http_error(res, op).await) }
}

async fn http_error(res: reqwest::Response, op: &str) -> SearchError {
    let status = res.status();
    let body = res.text().await.unwrap_or_default();
    SearchError::QueryError(format!("clickhouse {op} failed with {status}: {body}"))
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
    use axum::routing::post;

    async fn mock() -> String {
        let app = axum::Router::new().route(
            "/",
            post(|req: Request<Body>| async move {
                let uri = req.uri().to_string();
                if uri.contains("SELECT") {
                    (
                        StatusCode::OK,
                        "{\"id\":\"1\",\"title\":\"hello\"}\n{\"id\":\"2\",\"title\":\"world\"}\n",
                    )
                } else {
                    (StatusCode::OK, "")
                }
            }),
        );
        let listener = tokio::net::TcpListener::bind("127.0.0.1:0").await.unwrap();
        let addr = listener.local_addr().unwrap();
        tokio::spawn(async move { axum::serve(listener, app).await.unwrap() });
        format!("http://{addr}")
    }

    #[tokio::test]
    async fn get_returns_document() {
        let engine = ClickHouse::new(mock().await);
        let doc = engine.get("posts", &"1".into()).await.unwrap().unwrap();
        assert_eq!(doc["title"], "hello");
        assert!(doc.get("id").is_none(), "get() must strip the storage-side id");
    }

    #[tokio::test]
    async fn search_parses_json_each_row() {
        let engine = ClickHouse::new(mock().await);
        let res = engine.search("posts", json!({"sql": "SELECT * FROM posts"})).await.unwrap();
        assert_eq!(res.total, 2);
        assert_eq!(res.hits[0].id, "1");
        assert_eq!(res.hits[0].source["title"], "hello");
    }

    #[tokio::test]
    async fn index_sends_insert() {
        let engine = ClickHouse::new(mock().await);
        engine.index("posts", "1".into(), json!({"title": "hello"})).await.unwrap();
    }

    #[tokio::test]
    async fn aggregate_returns_rows() {
        let engine = ClickHouse::new(mock().await);
        let res = engine
            .aggregate("posts", json!({"sql": "SELECT count() AS count FROM posts"}))
            .await
            .unwrap();
        assert_eq!(res["rows"][0]["id"], "1");
    }
}
