// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
use axum::Router as AxumRouter;
use axum::routing::MethodRouter;

/// A declarative router that collects named routes and builds an
/// [`axum::Router`]. Routes are organised into [`RouteGroup`]s via
/// the [`ns`](Router::ns) method.
///
/// The state type `S` is the router's shared state, passed to handlers
/// through axum extractors such as [`axum::extract::State`]. Stateless
/// usage keeps the default `S = ()`.
pub struct Router<S = ()> {
    routes: Vec<(String, MethodRouter<S>)>,
}

impl<S> Router<S> {
    pub fn new() -> Self {
        Self { routes: Vec::new() }
    }

    pub fn ns<F>(mut self, prefix: &str, f: F) -> Self
    where
        F: FnOnce(RouteGroup<S>) -> RouteGroup<S>,
    {
        let group = RouteGroup::new(prefix.to_string());
        let group = f(group);
        self.routes.extend(group.routes);
        self
    }

    /// Build the router without supplying state. Handlers using
    /// [`axum::extract::State`] require [`Router::with_state`] instead.
    pub fn build(self) -> AxumRouter<S>
    where
        S: Clone + Send + Sync + 'static,
    {
        let mut router = AxumRouter::new();
        for (path, method_router) in self.routes {
            router = router.route(&path, method_router);
        }
        router
    }

    /// Build the router and attach `state`, yielding a fully constructed
    /// [`AxumRouter<()>`](axum::Router) ready to serve.
    pub fn with_state(self, state: S) -> AxumRouter<()>
    where
        S: Clone + Send + Sync + 'static,
    {
        self.build().with_state(state)
    }
}

impl<S> Default for Router<S> {
    fn default() -> Self {
        Self::new()
    }
}

/// A grouping of routes sharing a common URL prefix, created via
/// [`Router::ns`]. Supports get, post, put, and delete.
pub struct RouteGroup<S = ()> {
    prefix: String,
    routes: Vec<(String, MethodRouter<S>)>,
}

impl<S> RouteGroup<S> {
    pub fn new(prefix: String) -> Self {
        Self { prefix, routes: Vec::new() }
    }

    pub fn get<H, T>(mut self, path: &str, handler: H) -> Self
    where
        H: axum::handler::Handler<T, S>,
        T: 'static,
        S: Clone + Send + Sync + 'static,
    {
        let full = format!("{}{}", self.prefix, path);
        self.routes.push((full, axum::routing::get(handler)));
        self
    }

    pub fn post<H, T>(mut self, path: &str, handler: H) -> Self
    where
        H: axum::handler::Handler<T, S>,
        T: 'static,
        S: Clone + Send + Sync + 'static,
    {
        let full = format!("{}{}", self.prefix, path);
        self.routes.push((full, axum::routing::post(handler)));
        self
    }

    pub fn put<H, T>(mut self, path: &str, handler: H) -> Self
    where
        H: axum::handler::Handler<T, S>,
        T: 'static,
        S: Clone + Send + Sync + 'static,
    {
        let full = format!("{}{}", self.prefix, path);
        self.routes.push((full, axum::routing::put(handler)));
        self
    }

    pub fn delete<H, T>(mut self, path: &str, handler: H) -> Self
    where
        H: axum::handler::Handler<T, S>,
        T: 'static,
        S: Clone + Send + Sync + 'static,
    {
        let full = format!("{}{}", self.prefix, path);
        self.routes.push((full, axum::routing::delete(handler)));
        self
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use axum::body::Body;
    use axum::extract::{Query, State};
    use axum::http::{Request, StatusCode};
    use serde::Deserialize;
    use tower::ServiceExt;

    #[derive(Deserialize)]
    struct HelloParams {
        name: Option<String>,
    }

    async fn hello(Query(params): Query<HelloParams>) -> String {
        format!("hello {}", params.name.unwrap_or_else(|| "world".into()))
    }

    #[tokio::test]
    async fn stateless_handler_with_query_extractor() {
        let app = Router::new().ns("/api", |ns| ns.get("/hello", hello));
        let res = app
            .build()
            .oneshot(Request::builder().uri("/api/hello?name=rust").body(Body::empty()).unwrap())
            .await
            .unwrap();
        assert_eq!(res.status(), StatusCode::OK);
        let body = axum::body::to_bytes(res.into_body(), 1024).await.unwrap();
        assert_eq!(&body[..], b"hello rust");
    }

    #[derive(Clone)]
    struct AppState {
        counter: usize,
    }

    async fn count(State(state): State<AppState>) -> String {
        format!("count={}", state.counter)
    }

    #[tokio::test]
    async fn handler_with_state_extractor() {
        let app = Router::new()
            .ns("/api", |ns| ns.get("/count", count))
            .with_state(AppState { counter: 42 });
        let res = app
            .oneshot(Request::builder().uri("/api/count").body(Body::empty()).unwrap())
            .await
            .unwrap();
        assert_eq!(res.status(), StatusCode::OK);
        let body = axum::body::to_bytes(res.into_body(), 1024).await.unwrap();
        assert_eq!(&body[..], b"count=42");
    }

    async fn create() -> &'static str {
        "created"
    }

    async fn update() -> &'static str {
        "updated"
    }

    async fn remove() -> &'static str {
        "removed"
    }

    #[tokio::test]
    async fn post_put_delete_routes_are_registered() {
        let app = Router::new()
            .ns(
                "/api",
                |ns| ns.post("/create", create).put("/update", update).delete("/remove", remove),
            )
            .build();
        for (method, uri, expected) in [
            ("POST", "/api/create", "created"),
            ("PUT", "/api/update", "updated"),
            ("DELETE", "/api/remove", "removed"),
        ] {
            let res = app
                .clone()
                .oneshot(
                    Request::builder()
                        .method(method)
                        .uri(uri)
                        .body(Body::empty())
                        .unwrap(),
                )
                .await
                .unwrap();
            assert_eq!(res.status(), StatusCode::OK, "{method} {uri}");
            let body = axum::body::to_bytes(res.into_body(), 1024).await.unwrap();
            assert_eq!(&body[..], expected.as_bytes(), "{method} {uri}");
        }
    }

    #[tokio::test]
    async fn unregistered_path_returns_404() {
        let app = Router::new().ns("/api", |ns| ns.get("/hello", hello));
        let res = app
            .build()
            .oneshot(Request::builder().uri("/nope").body(Body::empty()).unwrap())
            .await
            .unwrap();
        assert_eq!(res.status(), StatusCode::NOT_FOUND);
    }

    #[tokio::test]
    async fn multiple_namespaces_do_not_collide() {
        let app = Router::new()
            .ns("/a", |ns| ns.get("/x", hello))
            .ns("/b", |ns| ns.get("/x", hello));
        let res = app
            .build()
            .oneshot(
                Request::builder()
                    .uri("/b/x?name=bee")
                    .body(Body::empty())
                    .unwrap(),
            )
            .await
            .unwrap();
        assert_eq!(res.status(), StatusCode::OK);
        let body = axum::body::to_bytes(res.into_body(), 1024).await.unwrap();
        assert_eq!(&body[..], b"hello bee");
    }
}
