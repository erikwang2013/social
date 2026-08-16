// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
use async_trait::async_trait;
use reqwest::{Client, StatusCode};

use crate::{
    Edge, GraphDB, GraphError, Params, PathResult, Properties, QueryResult, Traversal,
    TraversalDirection, Vertex, VertexId,
};

/// ArangoDB driver backed by its REST API.
///
/// Vertices live in a single configurable collection (default `vertices`);
/// edges live in collections named after the edge label. `_from`/`_to` are
/// rendered as `{vertex_collection}/{id}`.
pub struct ArangoDB {
    client: Client,
    base_url: String,
    vertex_collection: String,
}

impl ArangoDB {
    pub fn new(base_url: impl Into<String>) -> Self {
        Self {
            client: Client::new(),
            base_url: base_url.into(),
            vertex_collection: "vertices".into(),
        }
    }

    pub fn with_vertex_collection(mut self, name: &str) -> Self {
        self.vertex_collection = name.to_string();
        self
    }

    fn vertex_url(&self, id: &str) -> String {
        self.doc_url(&self.vertex_collection, id)
    }

    fn doc_url(&self, collection: &str, id: &str) -> String {
        reqwest::Url::parse(&format!("{}/_api/document/{collection}/{id}", self.base_url))
            .map(|u| u.to_string())
            .unwrap_or_else(|_| format!("{}/_api/document/{collection}/{id}", self.base_url))
    }

    async fn send(
        &self,
        req: reqwest::RequestBuilder,
        op: &str,
    ) -> Result<serde_json::Value, GraphError> {
        let res = req.send().await.map_err(conn_err)?;
        if !res.status().is_success() {
            return Err(http_error(res, op).await);
        }
        res.json().await.map_err(query_err)
    }
}

#[async_trait]
impl GraphDB for ArangoDB {
    async fn add_vertex(&self, vertex: Vertex) -> Result<Vertex, GraphError> {
        let mut doc = vertex.properties.clone();
        doc.insert("_key".into(), serde_json::json!(vertex.id));
        doc.insert("_label".into(), serde_json::json!(vertex.label));
        self.send(
            self.client
                .post(format!(
                    "{}/_api/document/{collection}",
                    self.base_url,
                    collection = self.vertex_collection
                ))
                .json(&doc),
            "add_vertex",
        )
        .await?;
        Ok(vertex)
    }

    async fn get_vertex(&self, id: &VertexId) -> Result<Option<Vertex>, GraphError> {
        let res = self.client.get(self.vertex_url(id)).send().await.map_err(conn_err)?;
        if res.status() == StatusCode::NOT_FOUND {
            return Ok(None);
        }
        if !res.status().is_success() {
            return Err(http_error(res, "get_vertex").await);
        }
        let doc: serde_json::Value = res.json().await.map_err(query_err)?;
        Ok(Some(doc_to_vertex(&doc)))
    }

    async fn update_vertex(
        &self,
        id: &VertexId,
        properties: Properties,
    ) -> Result<Vertex, GraphError> {
        let res = self
            .client
            .patch(self.vertex_url(id))
            .query(&[("returnNew", "true")])
            .json(&properties)
            .send()
            .await
            .map_err(conn_err)?;
        if res.status() == StatusCode::NOT_FOUND {
            return Err(GraphError::VertexNotFound(id.clone()));
        }
        if !res.status().is_success() {
            return Err(http_error(res, "update_vertex").await);
        }
        let payload: serde_json::Value = res.json().await.map_err(query_err)?;
        Ok(doc_to_vertex(payload.get("new").unwrap_or(&payload)))
    }

    async fn delete_vertex(&self, id: &VertexId) -> Result<(), GraphError> {
        let res = self.client.delete(self.vertex_url(id)).send().await.map_err(conn_err)?;
        if res.status() == StatusCode::NOT_FOUND {
            return Err(GraphError::VertexNotFound(id.clone()));
        }
        if !res.status().is_success() {
            return Err(http_error(res, "delete_vertex").await);
        }
        Ok(())
    }

    async fn add_edge(&self, edge: Edge) -> Result<Edge, GraphError> {
        let mut doc = edge.properties.clone();
        doc.insert("_key".into(), serde_json::json!(edge.id));
        doc.insert(
            "_from".into(),
            serde_json::json!(format!("{}/{from}", self.vertex_collection, from = edge.from)),
        );
        doc.insert(
            "_to".into(),
            serde_json::json!(format!("{}/{to}", self.vertex_collection, to = edge.to)),
        );
        self.send(
            self.client.post(format!("{}/_api/document/{}", self.base_url, edge.label)).json(&doc),
            "add_edge",
        )
        .await?;
        Ok(edge)
    }

    async fn traverse(&self, traversal: Traversal) -> Result<Vec<PathResult>, GraphError> {
        let direction = match traversal.direction {
            TraversalDirection::Outgoing => "OUTBOUND",
            TraversalDirection::Incoming => "INBOUND",
            TraversalDirection::Both => "ANY",
        };
        let label =
            traversal.edge_labels.first().map(|l| format!(" `{}`", aql_esc(l))).unwrap_or_default();
        let query = format!(
            "FOR v, e IN 1..{} {direction} \"{}/{}\"{label} RETURN {{v: v, e: e}}",
            traversal.max_depth,
            self.vertex_collection,
            aql_esc(&traversal.start)
        );
        let payload = self
            .send(
                self.client
                    .post(format!("{}/_api/cursor", self.base_url))
                    .json(&serde_json::json!({"query": query, "bindVars": {}})),
                "traverse",
            )
            .await?;
        let mut out = Vec::new();
        for row in payload["result"].as_array().into_iter().flatten() {
            out.push(PathResult {
                vertices: vec![doc_to_vertex(&row["v"])],
                edges: vec![edge_doc_to_edge(&row["e"])],
            });
        }
        Ok(out)
    }

    async fn query(&self, query: &str, params: Option<Params>) -> Result<QueryResult, GraphError> {
        let payload = self
            .send(
                self.client.post(format!("{}/_api/cursor", self.base_url)).json(
                    &serde_json::json!({"query": query, "bindVars": params.unwrap_or_default()}),
                ),
                "query",
            )
            .await?;
        let rows: Vec<serde_json::Value> =
            payload["result"].as_array().cloned().unwrap_or_default();
        let columns = rows
            .first()
            .and_then(|r| r.as_object())
            .map(|o| o.keys().cloned().collect())
            .unwrap_or_default();
        Ok(QueryResult { columns, rows })
    }
}

fn aql_esc(s: &str) -> String {
    s.replace('\\', "\\\\").replace('"', "\\\"")
}

fn doc_to_vertex(doc: &serde_json::Value) -> Vertex {
    let props: Properties = doc
        .as_object()
        .map(|o| {
            o.iter()
                .filter(|(k, _)| !["_key", "_id", "_rev", "_label"].contains(&k.as_str()))
                .map(|(k, v)| (k.clone(), v.clone()))
                .collect()
        })
        .unwrap_or_default();
    Vertex {
        id: doc["_key"].as_str().unwrap_or("").to_string(),
        label: doc["_label"].as_str().unwrap_or("").to_string(),
        properties: props,
    }
}

fn edge_doc_to_edge(doc: &serde_json::Value) -> Edge {
    let props: Properties = doc
        .as_object()
        .map(|o| {
            o.iter()
                .filter(|(k, _)| !["_key", "_id", "_rev", "_from", "_to"].contains(&k.as_str()))
                .map(|(k, v)| (k.clone(), v.clone()))
                .collect()
        })
        .unwrap_or_default();
    Edge {
        id: doc["_key"].as_str().unwrap_or("").to_string(),
        label: String::new(),
        from: doc["_from"].as_str().unwrap_or("").to_string(),
        to: doc["_to"].as_str().unwrap_or("").to_string(),
        properties: props,
    }
}

fn conn_err(e: reqwest::Error) -> GraphError {
    GraphError::ConnectionError(e.to_string())
}

fn query_err(e: reqwest::Error) -> GraphError {
    GraphError::QueryError(e.to_string())
}

async fn http_error(res: reqwest::Response, op: &str) -> GraphError {
    let status = res.status();
    let body = res.text().await.unwrap_or_default();
    GraphError::QueryError(format!("arangodb {op} failed with {status}: {body}"))
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

    #[tokio::test]
    async fn add_vertex_posts_doc_with_key() {
        let base = mock(vec![(
            "/_api/document/vertices",
            post(|req: Request<Body>| async move {
                let body = axum::body::to_bytes(req.into_body(), 4096).await.unwrap();
                let doc: serde_json::Value = serde_json::from_slice(&body).unwrap();
                assert_eq!(doc["_key"], "v1");
                assert_eq!(doc["_label"], "Person");
                assert_eq!(doc["name"], "Alice");
                (
                    StatusCode::CREATED,
                    axum::Json(serde_json::json!({"_key": "v1", "_id": "vertices/v1"})),
                )
            }),
        )])
        .await;
        let db = ArangoDB::new(base);
        let mut props = Properties::new();
        props.insert("name".into(), serde_json::json!("Alice"));
        let v = Vertex { id: "v1".into(), label: "Person".into(), properties: props };
        assert_eq!(db.add_vertex(v).await.unwrap().id, "v1");
    }

    #[tokio::test]
    async fn get_vertex_missing_returns_none() {
        let base =
            mock(vec![("/_api/document/vertices/nope", get(|| async { StatusCode::NOT_FOUND }))])
                .await;
        let db = ArangoDB::new(base);
        assert!(db.get_vertex(&"nope".into()).await.unwrap().is_none());
    }

    #[tokio::test]
    async fn get_vertex_parses_doc() {
        let base = mock(vec![(
            "/_api/document/vertices/v1",
            get(|| async {
                (
                    StatusCode::OK,
                    axum::Json(serde_json::json!({"_key": "v1", "_id": "vertices/v1", "_label": "Person", "name": "Alice"})),
                )
            }),
        )])
        .await;
        let db = ArangoDB::new(base);
        let v = db.get_vertex(&"v1".into()).await.unwrap().unwrap();
        assert_eq!(v.id, "v1");
        assert_eq!(v.label, "Person");
        assert_eq!(v.properties["name"], "Alice");
    }

    #[tokio::test]
    async fn traverse_parses_cursor_result() {
        let base = mock(vec![(
            "/_api/cursor",
            post(|| async {
                (
                    StatusCode::CREATED,
                    axum::Json(serde_json::json!({
                        "result": [{
                            "v": {"_key": "v2", "_label": "Person", "name": "Bob"},
                            "e": {"_key": "e1", "_from": "vertices/v1", "_to": "vertices/v2", "since": 2020}
                        }],
                        "extra": {"stats": {}}
                    })),
                )
            }),
        )])
        .await;
        let db = ArangoDB::new(base);
        let paths = db
            .traverse(Traversal {
                start: "v1".into(),
                edge_labels: vec!["KNOWS".into()],
                max_depth: 1,
                direction: TraversalDirection::Outgoing,
            })
            .await
            .unwrap();
        assert_eq!(paths.len(), 1);
        assert_eq!(paths[0].vertices[0].id, "v2");
        assert_eq!(paths[0].edges[0].from, "vertices/v1");
        assert_eq!(paths[0].edges[0].properties["since"], 2020);
    }

    #[tokio::test]
    async fn update_missing_vertex_errors() {
        let base = mock(vec![(
            "/_api/document/vertices/nope",
            axum::routing::patch(|| async { StatusCode::NOT_FOUND }),
        )])
        .await;
        let db = ArangoDB::new(base);
        let err = db.update_vertex(&"nope".into(), Properties::new()).await.unwrap_err();
        assert!(matches!(err, GraphError::VertexNotFound(_)));
    }
}
