// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
use axum::body::Body;
use axum::http::header::HeaderValue;
use axum::http::{Request, StatusCode};
use bee_session::Session;
use bee_template::TemplateEngine;
use std::collections::HashMap;
use std::sync::Arc;

#[derive(Debug, thiserror::Error)]
pub enum RouterError {
    #[error("serialize error: {0}")]
    SerializeError(String),
    #[error("template error: {0}")]
    TemplateError(String),
    #[error("internal error: {0}")]
    Internal(String),
}

pub struct Context {
    pub request: Request<Body>,
    params: HashMap<String, String>,
    session: Session,
    pub templates: Arc<TemplateEngine>,
    response_status: StatusCode,
    response_headers: HashMap<String, String>,
    response_body: Vec<u8>,
    aborted: bool,
}

impl Context {
    pub fn new(request: Request<Body>, session: Session, templates: Arc<TemplateEngine>) -> Self {
        Self {
            request,
            params: HashMap::new(),
            session,
            templates,
            response_status: StatusCode::OK,
            response_headers: HashMap::new(),
            response_body: Vec::new(),
            aborted: false,
        }
    }

    pub fn set_params(&mut self, params: HashMap<String, String>) {
        self.params = params;
    }

    pub fn param(&self, key: &str) -> Option<&str> {
        self.params.get(key).map(|s| s.as_str())
    }

    pub fn json<T: serde::Serialize>(&mut self, data: &T) -> Result<(), RouterError> {
        if self.aborted {
            return Ok(());
        }
        self.response_headers.insert("Content-Type".into(), "application/json".into());
        self.response_body =
            serde_json::to_vec(data).map_err(|e| RouterError::SerializeError(e.to_string()))?;
        Ok(())
    }

    pub fn text(&mut self, body: &str) -> Result<(), RouterError> {
        if self.aborted {
            return Ok(());
        }
        self.response_headers.insert("Content-Type".into(), "text/plain; charset=utf-8".into());
        self.response_body = body.as_bytes().to_vec();
        Ok(())
    }

    pub fn html(
        &mut self,
        template: &str,
        data: &HashMap<String, serde_json::Value>,
    ) -> Result<(), RouterError> {
        if self.aborted {
            return Ok(());
        }
        let rendered = self
            .templates
            .render(template, data)
            .map_err(|e| RouterError::TemplateError(e.to_string()))?;
        self.response_headers.insert("Content-Type".into(), "text/html; charset=utf-8".into());
        self.response_body = rendered.into_bytes();
        Ok(())
    }

    pub fn redirect(&mut self, url: &str) -> Result<(), RouterError> {
        if self.aborted {
            return Ok(());
        }
        // Validate before the user-controlled value reaches the Location
        // header: a bad HeaderValue (CRLF, control bytes) would otherwise
        // poison the response builder and panic in into_response().
        if !is_safe_redirect_url(url) {
            return Err(RouterError::Internal(format!("invalid redirect URL: {url:?}")));
        }
        HeaderValue::try_from(url)
            .map_err(|_| RouterError::Internal(format!("invalid redirect URL: {url:?}")))?;
        self.response_status = StatusCode::FOUND;
        self.response_headers.insert("Location".into(), url.to_string());
        Ok(())
    }

    pub fn abort(&mut self, status: StatusCode, msg: &str) {
        self.aborted = true;
        self.response_status = status;
        self.response_body = msg.as_bytes().to_vec();
    }

    pub fn is_aborted(&self) -> bool {
        self.aborted
    }

    /// Access the request session.
    pub fn session(&self) -> &Session {
        &self.session
    }

    /// Mutably access the request session.
    pub fn session_mut(&mut self) -> &mut Session {
        &mut self.session
    }

    /// Set a response header. The value is validated up front so the
    /// response builder in [`Context::into_response`] never panics.
    pub fn set_header(&mut self, name: &str, value: &str) -> Result<(), RouterError> {
        HeaderValue::from_str(value)
            .map_err(|_| RouterError::Internal(format!("invalid value for header {name}")))?;
        self.response_headers.insert(name.to_string(), value.to_string());
        Ok(())
    }

    pub fn into_response(self) -> axum::response::Response<Body> {
        let mut builder = axum::response::Response::builder().status(self.response_status);
        for (k, v) in &self.response_headers {
            builder = builder.header(k.as_str(), v.as_str());
        }
        // SAFETY: Content-Type values are constants; Location is validated in
        // redirect() and arbitrary headers are validated in set_header().
        builder
            .body(Body::from(self.response_body))
            .expect("response builder with validated headers should never fail")
    }
}

/// Accept only http(s) absolute URLs and same-origin relative paths
/// (protocol-relative `//host` is rejected to prevent open redirects).
fn is_safe_redirect_url(url: &str) -> bool {
    let lower = url.to_ascii_lowercase();
    let absolute = lower.starts_with("http://") || lower.starts_with("https://");
    let relative = url.starts_with('/') && !url.starts_with("//");
    absolute || relative
}

#[cfg(test)]
mod tests {
    use super::*;
    use axum::http::StatusCode;
    use bee_cache::MemoryCache;
    use std::path::Path;
    use std::sync::Arc;
    use std::time::Duration;

    fn make_context(templates: Arc<TemplateEngine>) -> Context {
        let cache: Arc<dyn bee_cache::Cache> = Arc::new(MemoryCache::new());
        let session = Session::new(cache, Duration::from_secs(3600));
        let req = Request::builder().body(Body::empty()).unwrap();
        Context::new(req, session, templates)
    }

    #[test]
    fn test_text_response() {
        let engine = TemplateEngine::new(Path::new("tests/fixtures/templates")).unwrap();
        let mut ctx = make_context(Arc::new(engine));
        ctx.text("hello").unwrap();
        assert!(!ctx.is_aborted());
    }

    #[test]
    fn test_json_response() {
        let engine = TemplateEngine::new(Path::new("tests/fixtures/templates")).unwrap();
        let mut ctx = make_context(Arc::new(engine));
        ctx.json(&serde_json::json!({"key": "value"})).unwrap();
        assert!(!ctx.is_aborted());
    }

    #[test]
    fn test_abort() {
        let engine = TemplateEngine::new(Path::new("tests/fixtures/templates")).unwrap();
        let mut ctx = make_context(Arc::new(engine));
        ctx.abort(StatusCode::NOT_FOUND, "not found");
        assert!(ctx.is_aborted());
        // abort should not panic when called multiple times
        ctx.json(&serde_json::json!({})).unwrap(); // should be no-op
    }

    #[test]
    fn test_redirect_valid_urls() {
        let engine = TemplateEngine::new(Path::new("tests/fixtures/templates")).unwrap();
        let mut ctx = make_context(Arc::new(engine));
        ctx.redirect("/dashboard").unwrap();
        ctx.redirect("https://example.com/path").unwrap();
        ctx.redirect("HTTP://example.com").unwrap();
        // into_response must not panic with a validated Location value
        let _ = ctx.into_response();
    }

    #[test]
    fn test_redirect_rejects_crlf() {
        let engine = TemplateEngine::new(Path::new("tests/fixtures/templates")).unwrap();
        let mut ctx = make_context(Arc::new(engine));
        assert!(ctx.redirect("http://evil.com/\r\nX-Injected: 1").is_err());
        assert!(ctx.redirect("/path\r\nSet-Cookie: admin=1").is_err());
    }

    #[test]
    fn test_redirect_rejects_unsafe_schemes() {
        let engine = TemplateEngine::new(Path::new("tests/fixtures/templates")).unwrap();
        let mut ctx = make_context(Arc::new(engine));
        assert!(ctx.redirect("ftp://example.com").is_err());
        assert!(ctx.redirect("javascript:alert(1)").is_err());
        // protocol-relative open redirect
        assert!(ctx.redirect("//evil.com").is_err());
        // relative paths must start with '/'
        assert!(ctx.redirect("dashboard").is_err());
    }

    #[test]
    fn test_params_set_and_get() {
        let engine = TemplateEngine::new(Path::new("tests/fixtures/templates")).unwrap();
        let mut ctx = make_context(Arc::new(engine));
        let mut params = std::collections::HashMap::new();
        params.insert("id".to_string(), "42".to_string());
        ctx.set_params(params);
        assert_eq!(ctx.param("id"), Some("42"));
        assert_eq!(ctx.param("missing"), None);
    }

    #[tokio::test]
    async fn test_text_sets_content_type_and_body() {
        let engine = TemplateEngine::new(Path::new("tests/fixtures/templates")).unwrap();
        let mut ctx = make_context(Arc::new(engine));
        ctx.text("plain").unwrap();
        let res = ctx.into_response();
        assert_eq!(res.headers()["content-type"], "text/plain; charset=utf-8");
        let body = axum::body::to_bytes(res.into_body(), 1024).await.unwrap();
        assert_eq!(&body[..], b"plain");
    }

    #[tokio::test]
    async fn test_html_renders_template() {
        let engine = TemplateEngine::new(Path::new("tests/fixtures/templates")).unwrap();
        let mut ctx = make_context(Arc::new(engine));
        let data = bee_template::context! { "title": &"Hello Page" };
        ctx.html("test.html", &data).unwrap();
        let res = ctx.into_response();
        assert_eq!(res.headers()["content-type"], "text/html; charset=utf-8");
        let body = axum::body::to_bytes(res.into_body(), 1024).await.unwrap();
        assert!(String::from_utf8_lossy(&body).contains("Hello Page"));
    }

    #[test]
    fn test_html_missing_template_errors() {
        let engine = TemplateEngine::new(Path::new("tests/fixtures/templates")).unwrap();
        let mut ctx = make_context(Arc::new(engine));
        let err = ctx.html("nope.html", &std::collections::HashMap::new()).unwrap_err();
        assert!(matches!(err, RouterError::TemplateError(_)));
    }

    #[tokio::test]
    async fn test_abort_sets_status_and_body() {
        let engine = TemplateEngine::new(Path::new("tests/fixtures/templates")).unwrap();
        let mut ctx = make_context(Arc::new(engine));
        ctx.abort(StatusCode::FORBIDDEN, "denied");
        let res = ctx.into_response();
        assert_eq!(res.status(), StatusCode::FORBIDDEN);
        let body = axum::body::to_bytes(res.into_body(), 1024).await.unwrap();
        assert_eq!(&body[..], b"denied");
    }
}
