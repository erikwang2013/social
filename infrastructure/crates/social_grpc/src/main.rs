tonic::include_proto!("social");
mod live;

use live::{LiveSrvServer, LiveSvc, VoiceSrvServer, VoiceSvc};
use social::common::v1::Pong;
use social::infra::v1::{
    PingRequest,
    infra_service_server::{InfraService, InfraServiceServer},
};
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
    // 安装脚本写入的共享密钥/数据库配置；从仓库根或 infrastructure/ 启动均可加载
    let _ = dotenvy::from_filename("infrastructure/.env").or_else(|_| dotenvy::dotenv());
    let addr = "127.0.0.1:50051".parse()?;
    println!("infra gRPC listening on {addr}");

    let (mut health_reporter, health_service) = tonic_health::server::health_reporter();
    health_reporter.set_serving::<InfraServiceServer<InfraSvc>>().await;
    let mut builder = Server::builder()
        .add_service(health_service)
        .add_service(InfraServiceServer::new(InfraSvc));
    // M6: live/voice 服务依赖 MySQL+Redis，就绪才注册；否则 health 报 UNKNOWN → PHP 端降级
    if let Some(svc) = LiveSvc::from_env() {
        health_reporter.set_serving::<LiveSrvServer<LiveSvc>>().await;
        builder = builder.add_service(LiveSrvServer::new(svc));
    }
    if let Some(svc) = VoiceSvc::from_env().await {
        health_reporter.set_serving::<VoiceSrvServer<VoiceSvc>>().await;
        builder = builder.add_service(VoiceSrvServer::new(svc));
    }
    // HealthReporter drop 时会把所有服务标记 NOT_SERVING，forget 保持存活至进程退出
    std::mem::forget(health_reporter);

    builder.serve(addr).await?;
    Ok(())
}
