tonic::include_proto!("social");
mod search;

use bee_search::elasticsearch::Elasticsearch;
use bee_search::SearchEngine;
use search::{SearchServiceServer as SearchServer2, SearchSvc};
use social::common::v1::Pong;
use social::infra::v1::{
    infra_service_server::{InfraService, InfraServiceServer},
    PingRequest,
};
use std::sync::Arc;
use tonic::{transport::Server, Request, Response, Status};

#[derive(Default)]
pub struct InfraSvc;

#[tonic::async_trait]
impl InfraService for InfraSvc {
    async fn ping(&self, req: Request<PingRequest>) -> Result<Response<Pong>, Status> {
        Ok(Response::new(Pong {
            message: format!("pong from {}", req.get_ref().client),
        }))
    }
}

#[tokio::main]
async fn main() -> Result<(), Box<dyn std::error::Error>> {
    let addr = "127.0.0.1:50051".parse()?;
    let es_url =
        std::env::var("SEARCH_ES_URL").unwrap_or_else(|_| "http://127.0.0.1:9200".into());
    let engine: Arc<dyn SearchEngine> = Arc::new(Elasticsearch::new(es_url.clone()));
    println!("infra gRPC listening on {addr}, search using ES {es_url}");
    Server::builder()
        .add_service(InfraServiceServer::new(InfraSvc))
        .add_service(SearchServer2::new(SearchSvc { engine }))
        .serve(addr)
        .await?;
    Ok(())
}
