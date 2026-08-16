// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
use async_trait::async_trait;
use reqwest::Client;

use crate::{
    Edge, GraphDB, GraphError, Params, PathResult, Properties, QueryResult, Traversal,
    TraversalDirection, Vertex, VertexId,
};

/// NebulaGraph driver backed by the HTTP gateway (`/api/v2/statement`).
pub struct NebulaGraph {
    client: Client,
    base_url: String,
}

impl NebulaGraph {
    pub fn new(base_url: impl Into<String>) -> Self {
        Self { client: Client::new(), base_url: base_url.into() }
    }

    async fn exec(&self, stmt: &str) -> Result<serde_json::Value, GraphError> {
        let res = self
            .client
            .post(format!("{}/api/v2/statement", self.base_url))
            .form(&[("stmt", stmt), ("format", "json")])
            .send()
            .await
            .map_err(conn_err)?;
        if !res.status().is_success() {
            return Err(http_error(res, "ngql").await);
        }
        let payload: serde_json::Value = res.json().await.map_err(query_err)?;
        if payload["code"].as_i64().unwrap_or(-1) != 0 {
            return Err(GraphError::QueryError(
                payload["message"].as_str().unwrap_or("nebulagraph error").to_string(),
            ));
        }
        Ok(payload)
    }
}

#[async_trait]
impl GraphDB for NebulaGraph {
    async fn add_vertex(&self, vertex: Vertex) -> Result<Vertex, GraphError> {
        let cols: Vec<String> = vertex.properties.keys().cloned().collect();
        let vals: Vec<String> = vertex.properties.values().map(lit).collect();
        let stmt = format!(
            "INSERT VERTEX `{}` ({}) VALUES \"{}\":({})",
            backtick(&vertex.label),
            cols.join(", "),
            esc(&vertex.id),
            vals.join(", ")
        );
        self.exec(&stmt).await?;
        Ok(vertex)
    }

    async fn get_vertex(&self, id: &VertexId) -> Result<Option<Vertex>, GraphError> {
        let stmt = format!("FETCH PROP ON * \"{}\" YIELD vertex AS v", esc(id));
        let payload = self.exec(&stmt).await?;
        let v = payload["data"][0]["rows"][0][0].clone();
        if v.is_null() {
            return Ok(None);
        }
        let props = v.get("properties").cloned().unwrap_or_else(|| serde_json::json!({}));
        Ok(Some(Vertex {
            id: v["id"].as_str().unwrap_or(id).to_string(),
            label: String::new(),
            properties: props.as_object().cloned().unwrap_or_default(),
        }))
    }

    async fn update_vertex(
        &self,
        id: &VertexId,
        properties: Properties,
    ) -> Result<Vertex, GraphError> {
        // ponytail: nGQL UPDATE requires a tag name, which the trait does not carry;
        // the wildcard tag `*` keeps the call site simple — if a backend rejects it,
        // give the driver a tag field and use it here.
        let assigns: Vec<String> =
            properties.iter().map(|(k, v)| format!("{k} = {}", lit(v))).collect();
        let stmt = format!("UPDATE VERTEX ON * \"{}\" SET {}", esc(id), assigns.join(", "));
        let payload = self.exec(&stmt).await?;
        if payload["data"][0]["rows"].as_array().map(|r| r.is_empty()).unwrap_or(true) {
            return Err(GraphError::VertexNotFound(id.clone()));
        }
        let mut merged = properties;
        merged.insert("id".into(), serde_json::json!(id));
        Ok(Vertex { id: id.clone(), label: String::new(), properties: merged })
    }

    async fn delete_vertex(&self, id: &VertexId) -> Result<(), GraphError> {
        let stmt = format!("DELETE VERTEX \"{}\"", esc(id));
        let payload = self.exec(&stmt).await?;
        if payload["data"][0]["rows"].as_array().map(|r| r.is_empty()).unwrap_or(true) {
            return Err(GraphError::VertexNotFound(id.clone()));
        }
        Ok(())
    }

    async fn add_edge(&self, edge: Edge) -> Result<Edge, GraphError> {
        let cols: Vec<String> = edge.properties.keys().cloned().collect();
        let vals: Vec<String> = edge.properties.values().map(lit).collect();
        let stmt = format!(
            "INSERT EDGE `{}` ({}) VALUES \"{}\"->\"{}\":({})",
            backtick(&edge.label),
            cols.join(", "),
            esc(&edge.from),
            esc(&edge.to),
            vals.join(", ")
        );
        self.exec(&stmt).await?;
        Ok(edge)
    }

    async fn traverse(&self, traversal: Traversal) -> Result<Vec<PathResult>, GraphError> {
        let label =
            traversal.edge_labels.first().map(|l| format!("`{}`", backtick(l))).unwrap_or_default();
        let dir = match traversal.direction {
            TraversalDirection::Outgoing => "",
            TraversalDirection::Incoming => " REVERSELY",
            TraversalDirection::Both => " BIDIRECT",
        };
        let stmt = format!(
            "GO 1 TO {} STEPS FROM \"{}\" OVER {}{} YIELD dst(edge) AS vid, edge AS e",
            traversal.max_depth,
            esc(&traversal.start),
            label,
            dir
        );
        let payload = self.exec(&stmt).await?;
        let mut out = Vec::new();
        for row in payload["data"][0]["rows"].as_array().into_iter().flatten() {
            let vid = row[0].as_str().unwrap_or("").to_string();
            let e = &row[1];
            let props = e
                .get("props")
                .or_else(|| e.get("properties"))
                .cloned()
                .unwrap_or_else(|| serde_json::json!({}));
            out.push(PathResult {
                vertices: vec![Vertex {
                    id: vid,
                    label: String::new(),
                    properties: Properties::new(),
                }],
                edges: vec![Edge {
                    id: String::new(),
                    label: e["name"].as_str().unwrap_or("").to_string(),
                    from: e["src"].as_str().unwrap_or("").to_string(),
                    to: e["dst"].as_str().unwrap_or("").to_string(),
                    properties: props.as_object().cloned().unwrap_or_default(),
                }],
            });
        }
        Ok(out)
    }

    async fn query(&self, query: &str, params: Option<Params>) -> Result<QueryResult, GraphError> {
        let _ = params;
        let payload = self.exec(query).await?;
        let result = &payload["data"][0];
        let columns = result["columns"]
            .as_array()
            .map(|c| c.iter().filter_map(|x| x.as_str().map(String::from)).collect())
            .unwrap_or_default();
        let rows = result["rows"].as_array().cloned().unwrap_or_default();
        Ok(QueryResult { columns, rows })
    }
}

fn backtick(s: &str) -> String {
    s.replace('`', "``")
}

/// Quote an ID or string value inside a double-quoted nGQL literal.
fn esc(s: &str) -> String {
    s.replace('\\', "\\\\").replace('"', "\\\"")
}

/// Render a JSON value as an nGQL literal.
fn lit(v: &serde_json::Value) -> String {
    match v {
        serde_json::Value::String(s) => format!("\"{}\"", esc(s)),
        serde_json::Value::Null => "null".into(),
        _ => v.to_string(),
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
    GraphError::QueryError(format!("nebulagraph {op} failed with {status}: {body}"))
}

#[cfg(test)]
mod tests {
    use super::*;
    use axum::body::Body;
    use axum::http::{Request, StatusCode};
    use axum::routing::post;

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
    async fn add_vertex_sends_insert() {
        let base = mock(vec![(
            "/api/v2/statement",
            post(|req: Request<Body>| async move {
                let body = axum::body::to_bytes(req.into_body(), 4096).await.unwrap();
                let text = String::from_utf8(body.to_vec()).unwrap();
                assert!(
                    text.contains(
                        "INSERT+VERTEX+%60Person%60+%28name%29+VALUES+%22v1%22%3A%28%22Alice%22%29"
                    ),
                    "unexpected body: {text}"
                );
                (StatusCode::OK, axum::Json(serde_json::json!({"code": 0, "data": []})))
            }),
        )])
        .await;
        let db = NebulaGraph::new(base);
        let mut props = Properties::new();
        props.insert("name".into(), serde_json::json!("Alice"));
        let v = Vertex { id: "v1".into(), label: "Person".into(), properties: props };
        assert_eq!(db.add_vertex(v).await.unwrap().id, "v1");
    }

    #[tokio::test]
    async fn get_vertex_parses_vertex() {
        let base = mock(vec![(
            "/api/v2/statement",
            post(|| async {
                (
                    StatusCode::OK,
                    axum::Json(serde_json::json!({
                        "code": 0,
                        "data": [{
                            "columns": ["v"],
                            "rows": [[{"id": "v1", "properties": {"name": "Alice"}}]]
                        }]
                    })),
                )
            }),
        )])
        .await;
        let db = NebulaGraph::new(base);
        let v = db.get_vertex(&"v1".into()).await.unwrap().unwrap();
        assert_eq!(v.id, "v1");
        assert_eq!(v.properties["name"], "Alice");
    }

    #[tokio::test]
    async fn get_vertex_missing_returns_none() {
        let base = mock(vec![(
            "/api/v2/statement",
            post(|| async {
                (
                    StatusCode::OK,
                    axum::Json(
                        serde_json::json!({"code": 0, "data": [{"columns": ["v"], "rows": []}]}),
                    ),
                )
            }),
        )])
        .await;
        let db = NebulaGraph::new(base);
        assert!(db.get_vertex(&"nope".into()).await.unwrap().is_none());
    }

    #[tokio::test]
    async fn query_maps_columns_and_rows() {
        let base = mock(vec![(
            "/api/v2/statement",
            post(|| async {
                (
                    StatusCode::OK,
                    axum::Json(serde_json::json!({"code": 0, "data": [{"columns": ["a"], "rows": [[1], [2]]}]})),
                )
            }),
        )])
        .await;
        let db = NebulaGraph::new(base);
        let res = db.query("SHOW TAGS", None).await.unwrap();
        assert_eq!(res.columns, vec!["a"]);
        assert_eq!(res.rows, vec![serde_json::json!([1]), serde_json::json!([2])]);
    }

    #[tokio::test]
    async fn error_code_maps_to_query_error() {
        let base = mock(vec![(
            "/api/v2/statement",
            post(|| async {
                (
                    StatusCode::OK,
                    axum::Json(serde_json::json!({"code": -1009, "message": "bad stmt"})),
                )
            }),
        )])
        .await;
        let db = NebulaGraph::new(base);
        let err = db.query("BAD", None).await.unwrap_err();
        assert!(matches!(err, GraphError::QueryError(m) if m.contains("bad stmt")));
    }
}
