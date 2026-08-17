tonic::include_proto!("social");
use social::common::v1::Pong;
use social::infra::v1::{
    infra_service_server::{InfraService, InfraServiceServer},
    PingRequest,
};
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
    println!("infra gRPC listening on {addr}");
    Server::builder()
        .add_service(InfraServiceServer::new(InfraSvc))
        .serve(addr)
        .await?;
    Ok(())
}
