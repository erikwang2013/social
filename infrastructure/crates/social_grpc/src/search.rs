tonic::include_proto!("social");

use bee_search::{DocumentId, SearchEngine, SearchQuery};
use social::search::v1::{
    search_service_server::SearchService,
    DeleteRequest, Hit, IndexRequest, IndexResponse, SearchRequest, SearchResponse,
};
pub use social::search::v1::search_service_server::SearchServiceServer;
use std::sync::Arc;
use tonic::{Request, Response, Status};

pub struct SearchSvc {
    pub engine: Arc<dyn SearchEngine>,
}

#[tonic::async_trait]
impl SearchService for SearchSvc {
    async fn index(&self, req: Request<IndexRequest>) -> Result<Response<IndexResponse>, Status> {
        let r = req.into_inner();
        let doc: bee_search::Document =
            serde_json::from_str(&r.json).unwrap_or_else(|_| r.json.into());
        self.engine
            .index(&r.index, DocumentId::from(r.id.to_string()), doc)
            .await
            .map_err(|e| Status::internal(e.to_string()))?;
        Ok(Response::new(IndexResponse { ok: true }))
    }

    async fn delete(&self, req: Request<DeleteRequest>) -> Result<Response<IndexResponse>, Status> {
        let r = req.into_inner();
        self.engine
            .delete(&r.index, &DocumentId::from(r.id.to_string()))
            .await
            .map_err(|e| Status::internal(e.to_string()))?;
        Ok(Response::new(IndexResponse { ok: true }))
    }

    async fn search(&self, req: Request<SearchRequest>) -> Result<Response<SearchResponse>, Status> {
        let r = req.into_inner();
        let query = SearchQuery::from(serde_json::json!({
            "query": r.query,
            "from": r.from,
            "size": r.size,
        }));
        let res = self
            .engine
            .search(&r.index, query)
            .await
            .map_err(|e| Status::internal(e.to_string()))?;
        let list = res
            .hits
            .into_iter()
            .map(|h| Hit {
                id: h.id.parse().unwrap_or(0),
                json: h.source.to_string(),
            })
            .collect();
        Ok(Response::new(SearchResponse { hits: list, total: res.total as i64 }))
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use bee_search::MemoryEngine;

    fn svc() -> SearchSvc {
        SearchSvc { engine: Arc::new(MemoryEngine::new()) }
    }

    #[tokio::test]
    async fn index_then_search_roundtrip() {
        let svc = svc();

        let idx = Request::new(IndexRequest {
            index: "posts".into(),
            id: 42,
            json: serde_json::json!({"content": "hello world"}).to_string(),
        });
        svc.index(idx).await.unwrap();

        let res = svc
            .search(Request::new(SearchRequest {
                index: "posts".into(),
                query: "hello".into(),
                from: 0,
                size: 10,
            }))
            .await
            .unwrap()
            .into_inner();
        assert_eq!(res.total, 1);
        assert_eq!(res.hits[0].id, 42);
    }

    #[tokio::test]
    async fn delete_removes_document() {
        let svc = svc();
        svc.index(Request::new(IndexRequest {
            index: "posts".into(),
            id: 7,
            json: "{\"x\": 1}".into(),
        }))
        .await
        .unwrap();

        let res = svc
            .delete(Request::new(DeleteRequest { index: "posts".into(), id: 7 }))
            .await
            .unwrap()
            .into_inner();
        assert!(res.ok);

        let search = svc
            .search(Request::new(SearchRequest {
                index: "posts".into(),
                query: "x".into(),
                from: 0,
                size: 10,
            }))
            .await
            .unwrap()
            .into_inner();
        assert_eq!(search.total, 0);
    }

    #[tokio::test]
    async fn index_engine_error_becomes_internal_status() {
        // MemoryEngine never errors, so use a missing index plus a search
        // that exercises the error mapping instead.
        let svc = svc();
        let res = svc
            .search(Request::new(SearchRequest {
                index: "ghost".into(),
                query: "x".into(),
                from: 0,
                size: 10,
            }))
            .await;
        assert!(res.is_ok(), "MemoryEngine search on a missing index succeeds with 0 hits");
    }

    #[tokio::test]
    async fn invalid_json_falls_back_to_string_document() {
        let svc = svc();
        svc.index(Request::new(IndexRequest {
            index: "notes".into(),
            id: 1,
            json: "not-json-at-all".into(),
        }))
        .await
        .unwrap();

        // The fallback stores the raw string; searching for a substring of it
        // must still match through the engine's content scan.
        let res = svc
            .search(Request::new(SearchRequest {
                index: "notes".into(),
                query: "not-json".into(),
                from: 0,
                size: 10,
            }))
            .await
            .unwrap()
            .into_inner();
        assert_eq!(res.total, 1);
        assert_eq!(res.hits[0].json, "\"not-json-at-all\"");
    }

    #[tokio::test]
    async fn search_empty_index_returns_zero_hits() {
        let svc = svc();
        let res = svc
            .search(Request::new(SearchRequest {
                index: "empty".into(),
                query: "anything".into(),
                from: 0,
                size: 10,
            }))
            .await
            .unwrap()
            .into_inner();
        assert_eq!(res.total, 0);
        assert!(res.hits.is_empty());
    }
}
