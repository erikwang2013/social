tonic::include_proto!("social");
mod live;
mod search;

use bee_search::SearchEngine;
use bee_search::elasticsearch::Elasticsearch;
use live::{LiveSrvServer, LiveSvc, VoiceSrvServer, VoiceSvc};
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
    let mut builder = Server::builder()
        .add_service(health_service)
        .add_service(InfraServiceServer::new(InfraSvc))
        .add_service(SearchServer2::new(SearchSvc { engine }));
    // M6: live/voice 服务依赖 MySQL+Redis，就绪才注册；否则 health 报 UNKNOWN → PHP 端降级
    if let Some(svc) = LiveSvc::from_env() {
        health_reporter.set_serving::<LiveSrvServer<LiveSvc>>().await;
        builder = builder.add_service(LiveSrvServer::new(svc));
    }
    if let Some(svc) = VoiceSvc::from_env() {
        health_reporter.set_serving::<VoiceSrvServer<VoiceSvc>>().await;
        builder = builder.add_service(VoiceSrvServer::new(svc));
    }
    // HealthReporter drop 时会把所有服务标记 NOT_SERVING，forget 保持存活至进程退出
    std::mem::forget(health_reporter);

    builder.serve(addr).await?;
    Ok(())
}
