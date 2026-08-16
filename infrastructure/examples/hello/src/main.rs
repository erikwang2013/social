// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
async fn health() -> &'static str {
    "OK"
}

#[tokio::main]
async fn main() {
    let _log_handle = bee_rust::init().unwrap();
    let router = bee_rust::bee_router::Router::new().ns("/api/v1", |ns| ns.get("/health", health));
    let port = std::env::var("PORT").unwrap_or_else(|_| "8080".to_string());
    let addr = format!("0.0.0.0:{port}");
    tracing::info!("Starting bee-rust on http://localhost:{port}");
    let app = router.build();
    let listener = tokio::net::TcpListener::bind(&addr).await.unwrap();
    axum::serve(listener, app).await.unwrap();
}
