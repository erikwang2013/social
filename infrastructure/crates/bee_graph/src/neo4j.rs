// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
use async_trait::async_trait;
use reqwest::Client;

use crate::{
    Edge, GraphDB, GraphError, Params, PathResult, Properties, QueryResult, Traversal,
    TraversalDirection, Vertex, VertexId,
};

/// Neo4j driver backed by the Cypher HTTP API (`/db/neo4j/tx/commit`).
pub struct Neo4j {
    client: Client,
    base_url: String,
}

impl Neo4j {
    pub fn new(base_url: impl Into<String>) -> Self {
        Self { client: Client::new(), base_url: base_url.into() }
    }

    async fn run(
        &self,
        statement: &str,
        parameters: Properties,
    ) -> Result<serde_json::Value, GraphError> {
        let res = self
            .client
            .post(format!("{}/db/neo4j/tx/commit", self.base_url))
            .json(&serde_json::json!({
                "statements": [{ "statement": statement, "parameters": parameters }]
            }))
            .send()
            .await
            .map_err(conn_err)?;
        if !res.status().is_success() {
            return Err(http_error(res, "cypher").await);
        }
        let payload: serde_json::Value = res.json().await.map_err(query_err)?;
        if let Some(err) = payload["errors"].as_array().and_then(|e| e.first()) {
            return Err(GraphError::QueryError(
                err["message"].as_str().unwrap_or("neo4j error").to_string(),
            ));
        }
        Ok(payload)
    }
}

#[async_trait]
impl GraphDB for Neo4j {
    async fn add_vertex(&self, vertex: Vertex) -> Result<Vertex, GraphError> {
        let mut p = Properties::new();
        p.insert("id".into(), serde_json::json!(vertex.id));
        p.insert("props".into(), serde_json::Value::Object(vertex.properties.clone()));
        let stmt = format!("CREATE (n:`{}` {{id: $id}}) SET n += $props", backtick(&vertex.label));
        self.run(&stmt, p).await?;
        Ok(vertex)
    }

    async fn get_vertex(&self, id: &VertexId) -> Result<Option<Vertex>, GraphError> {
        let mut p = Properties::new();
        p.insert("id".into(), serde_json::json!(id));
        let payload = self.run("MATCH (n {id: $id}) RETURN n", p).await?;
        let node = payload["results"][0]["data"][0]["row"][0].clone();
        if node.is_null() { Ok(None) } else { Ok(Some(node_to_vertex(&node))) }
    }

    async fn update_vertex(
        &self,
        id: &VertexId,
        properties: Properties,
    ) -> Result<Vertex, GraphError> {
        let mut p = Properties::new();
        p.insert("id".into(), serde_json::json!(id));
        p.insert("props".into(), serde_json::Value::Object(properties.clone()));
        let payload = self.run("MATCH (n {id: $id}) SET n += $props RETURN n", p).await?;
        let node = payload["results"][0]["data"][0]["row"][0].clone();
        if node.is_null() {
            return Err(GraphError::VertexNotFound(id.clone()));
        }
        Ok(node_to_vertex(&node))
    }

    async fn delete_vertex(&self, id: &VertexId) -> Result<(), GraphError> {
        let mut p = Properties::new();
        p.insert("id".into(), serde_json::json!(id));
        let payload = self.run("MATCH (n {id: $id}) DETACH DELETE n", p).await?;
        let deleted = payload["results"][0]["stats"]["nodes-deleted"].as_u64().unwrap_or(0);
        if deleted == 0 {
            return Err(GraphError::VertexNotFound(id.clone()));
        }
        Ok(())
    }

    async fn add_edge(&self, edge: Edge) -> Result<Edge, GraphError> {
        let mut p = Properties::new();
        p.insert("from".into(), serde_json::json!(edge.from));
        p.insert("to".into(), serde_json::json!(edge.to));
        p.insert("id".into(), serde_json::json!(edge.id));
        p.insert("props".into(), serde_json::Value::Object(edge.properties.clone()));
        let stmt = format!(
            "MATCH (a {{id: $from}}), (b {{id: $to}}) \
             CREATE (a)-[r:`{}` {{id: $id}}]->(b) SET r += $props",
            backtick(&edge.label)
        );
        let payload = self.run(&stmt, p).await?;
        let matched = payload["results"][0]["stats"]["nodes-matched"].as_u64().unwrap_or(0);
        if matched < 2 {
            return Err(GraphError::VertexNotFound(edge.from.clone()));
        }
        Ok(edge)
    }

    async fn traverse(&self, traversal: Traversal) -> Result<Vec<PathResult>, GraphError> {
        let label =
            traversal.edge_labels.first().map(|l| format!("`{}`", backtick(l))).unwrap_or_default();
        let arrow = match traversal.direction {
            TraversalDirection::Outgoing => "->",
            TraversalDirection::Incoming => "<-",
            TraversalDirection::Both => "-",
        };
        let stmt = format!(
            "MATCH (s {{id: $start}})-[r:{label}*1..{}]{arrow}(n) RETURN n, r",
            traversal.max_depth
        );
        let mut p = Properties::new();
        p.insert("start".into(), serde_json::json!(traversal.start));
        let payload = self.run(&stmt, p).await?;
        let mut out = Vec::new();
        for row in payload["results"][0]["data"].as_array().into_iter().flatten() {
            out.push(PathResult {
                vertices: vec![node_to_vertex(&row["row"][0])],
                edges: vec![rel_to_edge(&row["row"][1])],
            });
        }
        Ok(out)
    }

    async fn query(&self, query: &str, params: Option<Params>) -> Result<QueryResult, GraphError> {
        let payload = self.run(query, params.unwrap_or_default()).await?;
        let result = &payload["results"][0];
        let columns = result["columns"]
            .as_array()
            .map(|c| c.iter().filter_map(|x| x.as_str().map(String::from)).collect())
            .unwrap_or_default();
        let rows = result["data"]
            .as_array()
            .map(|d| d.iter().map(|r| r["row"].clone()).collect())
            .unwrap_or_default();
        Ok(QueryResult { columns, rows })
    }
}

fn backtick(s: &str) -> String {
    s.replace('`', "``")
}

fn node_to_vertex(v: &serde_json::Value) -> Vertex {
    let props = v.get("properties").cloned().unwrap_or_else(|| serde_json::json!({}));
    Vertex {
        id: v["properties"]["id"].as_str().unwrap_or("").to_string(),
        label: v["labels"]
            .as_array()
            .and_then(|a| a.first().and_then(|x| x.as_str()))
            .unwrap_or("")
            .to_string(),
        properties: props.as_object().cloned().unwrap_or_default(),
    }
}

fn rel_to_edge(r: &serde_json::Value) -> Edge {
    let props = r.get("properties").cloned().unwrap_or_else(|| serde_json::json!({}));
    Edge {
        id: r["properties"]["id"].as_str().unwrap_or("").to_string(),
        label: r["type"].as_str().unwrap_or("").to_string(),
        from: r["start"].as_str().unwrap_or("").to_string(),
        to: r["end"].as_str().unwrap_or("").to_string(),
        properties: props.as_object().cloned().unwrap_or_default(),
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
    GraphError::QueryError(format!("neo4j {op} failed with {status}: {body}"))
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
    async fn add_vertex_round_trips() {
        let base = mock(vec![(
            "/db/neo4j/tx/commit",
            post(|req: Request<Body>| async move {
                let body = axum::body::to_bytes(req.into_body(), 4096).await.unwrap();
                let v: serde_json::Value = serde_json::from_slice(&body).unwrap();
                assert_eq!(
                    v["statements"][0]["statement"],
                    "CREATE (n:`Person` {id: $id}) SET n += $props"
                );
                assert_eq!(v["statements"][0]["parameters"]["id"], "v1");
                (StatusCode::OK, axum::Json(serde_json::json!({"results": [], "errors": []})))
            }),
        )])
        .await;
        let db = Neo4j::new(base);
        let v = Vertex { id: "v1".into(), label: "Person".into(), properties: Properties::new() };
        assert_eq!(db.add_vertex(v).await.unwrap().id, "v1");
    }

    #[tokio::test]
    async fn get_vertex_parses_node() {
        let base = mock(vec![(
            "/db/neo4j/tx/commit",
            post(|| async {
                (
                    StatusCode::OK,
                    axum::Json(serde_json::json!({
                        "results": [{
                            "columns": ["n"],
                            "data": [{"row": [{
                                "identity": 1,
                                "labels": ["Person"],
                                "properties": {"id": "v1", "name": "Alice"}
                            }], "meta": [null]}]
                        }],
                        "errors": []
                    })),
                )
            }),
        )])
        .await;
        let db = Neo4j::new(base);
        let v = db.get_vertex(&"v1".into()).await.unwrap().unwrap();
        assert_eq!(v.id, "v1");
        assert_eq!(v.label, "Person");
        assert_eq!(v.properties["name"], "Alice");
    }

    #[tokio::test]
    async fn get_vertex_missing_returns_none() {
        let base = mock(vec![(
            "/db/neo4j/tx/commit",
            post(|| async {
                (StatusCode::OK, axum::Json(serde_json::json!({"results": [{"columns": ["n"], "data": []}], "errors": []})))
            }),
        )])
        .await;
        let db = Neo4j::new(base);
        assert!(db.get_vertex(&"nope".into()).await.unwrap().is_none());
    }

    #[tokio::test]
    async fn query_maps_columns_and_rows() {
        let base = mock(vec![(
            "/db/neo4j/tx/commit",
            post(|| async {
                (
                    StatusCode::OK,
                    axum::Json(serde_json::json!({
                        "results": [{
                            "columns": ["name", "age"],
                            "data": [{"row": ["Alice", 30], "meta": [null, null]}]
                        }],
                        "errors": []
                    })),
                )
            }),
        )])
        .await;
        let db = Neo4j::new(base);
        let res = db.query("MATCH (n) RETURN n.name, n.age", None).await.unwrap();
        assert_eq!(res.columns, vec!["name", "age"]);
        assert_eq!(res.rows[0], serde_json::json!(["Alice", 30]));
    }
}
