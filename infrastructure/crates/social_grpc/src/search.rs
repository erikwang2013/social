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

    #[tokio::test]
    async fn index_then_search_roundtrip() {
        let engine = Arc::new(MemoryEngine::new());
        let svc = SearchSvc { engine };

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
}
