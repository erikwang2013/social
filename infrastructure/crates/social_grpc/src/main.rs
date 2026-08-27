tonic::include_proto!("social");
mod search;

use bee_search::SearchEngine;
use bee_search::elasticsearch::Elasticsearch;
use search::{SearchServiceServer as SearchServer2, SearchSvc};
use social::common::v1::Pong;
use social::infra::v1::{
    PingRequest,
    infra_service_server::{InfraService, InfraServiceServer},
};
use std::sync::Arc;
use tonic::{Request, Response, Status, transport::Server};

#[derive(Default)]
pub struct InfraSvc;

#[tonic::async_trait]
impl InfraService for InfraSvc {
    async fn ping(&self, req: Request<PingRequest>) -> Result<Response<Pong>, Status> {
        Ok(Response::new(Pong { message: format!("pong from {}", req.get_ref().client) }))
    }
}

#[tokio::main]
async fn main() -> Result<(), Box<dyn std::error::Error>> {
    let addr = "127.0.0.1:50051".parse()?;
    let es_url = std::env::var("SEARCH_ES_URL").unwrap_or_else(|_| "http://127.0.0.1:9200".into());
    let engine: Arc<dyn SearchEngine> = Arc::new(Elasticsearch::new(es_url.clone()));
    println!("infra gRPC listening on {addr}, search using ES {es_url}");

    let (mut health_reporter, health_service) = tonic_health::server::health_reporter();
    health_reporter.set_serving::<InfraServiceServer<InfraSvc>>().await;
    health_reporter.set_serving::<SearchServer2<SearchSvc>>().await;
    // HealthReporter drop 时会把所有服务标记 NOT_SERVING，forget 保持存活至进程退出
    std::mem::forget(health_reporter);

    Server::builder()
        .add_service(health_service)
        .add_service(InfraServiceServer::new(InfraSvc))
        .add_service(SearchServer2::new(SearchSvc { engine }))
        .serve(addr)
        .await?;
    Ok(())
}
