// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
pub mod context;
pub mod filter;
pub mod router;

#[cfg(feature = "security")]
pub mod security;
#[cfg(feature = "security")]
pub use security::SecurityFilter;

use async_trait::async_trait;
use axum::http::HeaderMap;
use bee_cache::Cache;
use bee_session::Session;
use context::RouterError;
use std::sync::Arc;
use std::time::Duration;

#[async_trait]
pub trait Controller: Send + Sync + 'static {
    async fn handle(&self, ctx: &mut Context) -> Result<(), RouterError>;
    async fn prepare(&self, _ctx: &mut Context) -> Result<(), RouterError> {
        Ok(())
    }
    async fn finish(&self, _ctx: &mut Context) -> Result<(), RouterError> {
        Ok(())
    }
}

/// Name of the session cookie read and written by [`Context::dispatch`].
pub const SESSION_COOKIE: &str = "bee_session";

impl Context {
    /// Execute the request pipeline: restore the session from the
    /// `bee_session` cookie, run each filter's `before` hook, the controller
    /// (`prepare` → `handle`), each filter's `after` hook and `finish`, then
    /// persist the session.
    ///
    /// # Pipeline semantics
    /// - A stage returning [`Err`] propagates the error.
    /// - [`Context::abort`] short-circuits all remaining stages and
    ///   `dispatch` returns `Ok(())`; `finish` does not run for aborted
    ///   requests (it finalizes successful handling only).
    /// - Filter `after` hooks wrap controller execution: they run after
    ///   `handle` and before `finish`.
    ///
    /// # Session lifecycle
    /// A session is restored from the `bee_session` cookie when present; a
    /// missing, stale or invalid cookie keeps the fresh session created for
    /// [`Context::new`]. When the pipeline completes without abort, the
    /// session is saved and a `Set-Cookie` header is written if it was
    /// restored from a cookie (rolling TTL refresh) or holds data written by
    /// the controller. The session is managed by `dispatch`; read and write
    /// it through [`Context::session`] / [`Context::session_mut`].
    pub async fn dispatch(
        &mut self,
        cache: Arc<dyn Cache>,
        ttl: Duration,
        filters: &[&dyn Filter],
        controller: &dyn Controller,
    ) -> Result<(), RouterError> {
        let had_cookie = self.restore_session(cache, ttl).await;

        for filter in filters {
            filter.before(self)?;
            if self.is_aborted() {
                return Ok(());
            }
        }
        controller.prepare(self).await?;
        if self.is_aborted() {
            return Ok(());
        }
        controller.handle(self).await?;
        if self.is_aborted() {
            return Ok(());
        }
        for filter in filters {
            filter.after(self)?;
        }
        if self.is_aborted() {
            return Ok(());
        }
        controller.finish(self).await?;
        if self.is_aborted() {
            return Ok(());
        }

        // `Session` has no dirty tracking, so save unconditionally when the
        // session was restored from a cookie (rolling TTL) or carries data;
        // untouched sessions on cookie-less requests are skipped to avoid
        // cache churn and a Set-Cookie on every visit.
        if had_cookie || !self.session().is_empty() {
            self.session_mut()
                .save()
                .await
                .map_err(|e| RouterError::Internal(format!("session save failed: {e}")))?;
            let id = self.session().id().to_string();
            let cookie = format!("{SESSION_COOKIE}={id}; Path=/; HttpOnly");
            self.set_header("Set-Cookie", &cookie)?;
        }
        Ok(())
    }

    /// Restore the session from the `bee_session` cookie, keeping the fresh
    /// session when the cookie is absent or stale. Returns whether the cookie
    /// was present, so the persist step knows a cookie must be issued.
    async fn restore_session(&mut self, cache: Arc<dyn Cache>, ttl: Duration) -> bool {
        let Some(id) = cookie_value(self.request.headers(), SESSION_COOKIE) else {
            return false;
        };
        match Session::load(cache, id, ttl).await {
            Ok(session) => {
                *self.session_mut() = session;
                true
            }
            // Stale or invalid cookie: keep the fresh session; the persist
            // step re-issues a cookie with a new id.
            Err(_) => true,
        }
    }
}

/// Extract the value of `name` from a `Cookie` request header.
fn cookie_value<'a>(headers: &'a HeaderMap, name: &str) -> Option<&'a str> {
    let cookie = headers.get(axum::http::header::COOKIE)?.to_str().ok()?;
    cookie.split(';').find_map(|pair| {
        let (key, value) = pair.split_once('=')?;
        (key.trim() == name).then_some(value.trim())
    })
}

pub use context::Context;
pub use filter::Filter;
pub use router::Router;

#[cfg(test)]
mod tests {
    use super::*;
    use axum::body::Body;
    use axum::http::{Request, StatusCode};
    use bee_cache::MemoryCache;
    use bee_template::TemplateEngine;
    use std::path::Path;
    use std::sync::atomic::{AtomicUsize, Ordering};

    fn make_context(request: Request<Body>) -> Context {
        let cache: Arc<dyn Cache> = Arc::new(MemoryCache::new());
        let session = Session::new(cache, Duration::from_secs(3600));
        let templates =
            Arc::new(TemplateEngine::new(Path::new("tests/fixtures/templates")).unwrap());
        Context::new(request, session, templates)
    }

    fn cache() -> Arc<dyn Cache> {
        Arc::new(MemoryCache::new())
    }

    struct TrackController {
        calls: Arc<AtomicUsize>,
    }

    #[async_trait]
    impl Controller for TrackController {
        async fn prepare(&self, _ctx: &mut Context) -> Result<(), RouterError> {
            self.calls.fetch_add(1, Ordering::SeqCst);
            Ok(())
        }
        async fn handle(&self, _ctx: &mut Context) -> Result<(), RouterError> {
            self.calls.fetch_add(1, Ordering::SeqCst);
            Ok(())
        }
        async fn finish(&self, _ctx: &mut Context) -> Result<(), RouterError> {
            self.calls.fetch_add(1, Ordering::SeqCst);
            Ok(())
        }
    }

    struct AbortFilter;

    impl Filter for AbortFilter {
        fn before(&self, ctx: &mut Context) -> Result<(), RouterError> {
            ctx.abort(StatusCode::BAD_REQUEST, "blocked");
            Ok(())
        }
    }

    struct ErrorFilter;

    impl Filter for ErrorFilter {
        fn before(&self, _ctx: &mut Context) -> Result<(), RouterError> {
            Err(RouterError::Internal("blocked".into()))
        }
    }

    struct NoopController;

    #[async_trait]
    impl Controller for NoopController {
        async fn handle(&self, _ctx: &mut Context) -> Result<(), RouterError> {
            Ok(())
        }
    }

    #[tokio::test]
    async fn abort_in_filter_skips_controller() {
        let mut ctx = make_context(Request::builder().body(Body::empty()).unwrap());
        let calls = Arc::new(AtomicUsize::new(0));
        let result = ctx
            .dispatch(
                cache(),
                Duration::from_secs(3600),
                &[&AbortFilter],
                &TrackController { calls: calls.clone() },
            )
            .await;
        assert!(result.is_ok());
        assert!(ctx.is_aborted());
        assert_eq!(calls.load(Ordering::SeqCst), 0);
    }

    #[tokio::test]
    async fn filter_error_propagates_without_running_controller() {
        let mut ctx = make_context(Request::builder().body(Body::empty()).unwrap());
        let calls = Arc::new(AtomicUsize::new(0));
        let result = ctx
            .dispatch(
                cache(),
                Duration::from_secs(3600),
                &[&ErrorFilter],
                &TrackController { calls: calls.clone() },
            )
            .await;
        assert!(result.is_err());
        assert_eq!(calls.load(Ordering::SeqCst), 0);
    }

    #[tokio::test]
    async fn abort_in_prepare_skips_handle_and_finish() {
        struct AbortInPrepare;

        #[async_trait]
        impl Controller for AbortInPrepare {
            async fn prepare(&self, ctx: &mut Context) -> Result<(), RouterError> {
                ctx.abort(StatusCode::FORBIDDEN, "denied");
                Ok(())
            }
            async fn handle(&self, ctx: &mut Context) -> Result<(), RouterError> {
                ctx.abort(StatusCode::INTERNAL_SERVER_ERROR, "must not run");
                Ok(())
            }
        }

        let mut ctx = make_context(Request::builder().body(Body::empty()).unwrap());
        ctx.dispatch(cache(), Duration::from_secs(3600), &[], &AbortInPrepare).await.unwrap();
        assert_eq!(ctx.into_response().status(), StatusCode::FORBIDDEN);
    }

    #[tokio::test]
    async fn session_restored_from_cookie_and_persisted() {
        let cache: Arc<dyn Cache> = Arc::new(MemoryCache::new());
        let sid = {
            let mut session = Session::new(cache.clone(), Duration::from_secs(3600));
            session.set("user", &"alice").unwrap();
            session.save().await.unwrap();
            session.id().to_string()
        };

        let request = Request::builder()
            .header("cookie", format!("{SESSION_COOKIE}={sid}; other=1"))
            .body(Body::empty())
            .unwrap();
        let mut ctx = make_context(request);

        struct ReadUser(Arc<std::sync::Mutex<Option<String>>>);

        #[async_trait]
        impl Controller for ReadUser {
            async fn handle(&self, ctx: &mut Context) -> Result<(), RouterError> {
                let user: Option<String> = ctx.session().get("user").unwrap();
                *self.0.lock().unwrap() = user;
                Ok(())
            }
        }

        let seen = Arc::new(std::sync::Mutex::new(None));
        ctx.dispatch(cache.clone(), Duration::from_secs(3600), &[], &ReadUser(seen.clone()))
            .await
            .unwrap();

        // session restored: the handler observed the stored value
        assert_eq!(*seen.lock().unwrap(), Some("alice".to_string()));
        assert_eq!(ctx.session().id(), sid.as_str());

        // persisted and a Set-Cookie with the same id was issued
        let response = ctx.into_response();
        let cookie = response.headers().get("set-cookie").unwrap().to_str().unwrap();
        assert!(cookie.starts_with(&format!("{SESSION_COOKIE}={sid};")));
    }

    #[tokio::test]
    async fn session_written_by_handler_gets_cookie_and_is_saved() {
        let cache: Arc<dyn Cache> = Arc::new(MemoryCache::new());
        let templates =
            Arc::new(TemplateEngine::new(Path::new("tests/fixtures/templates")).unwrap());
        let mut ctx = Context::new(
            Request::builder().body(Body::empty()).unwrap(),
            Session::new(cache.clone(), Duration::from_secs(3600)),
            templates,
        );

        struct WriteUser;

        #[async_trait]
        impl Controller for WriteUser {
            async fn handle(&self, ctx: &mut Context) -> Result<(), RouterError> {
                ctx.session_mut().set("user", &"bob").unwrap();
                Ok(())
            }
        }

        ctx.dispatch(cache.clone(), Duration::from_secs(3600), &[], &WriteUser).await.unwrap();

        let sid = ctx.session().id().to_string();
        let response = ctx.into_response();
        let cookie = response.headers().get("set-cookie").unwrap().to_str().unwrap();
        assert!(cookie.starts_with(&format!("{SESSION_COOKIE}={sid};")));

        // the session was persisted: reloadable from the cache
        let loaded = Session::load(cache, &sid, Duration::from_secs(3600)).await.unwrap();
        let user: Option<String> = loaded.get("user").unwrap();
        assert_eq!(user, Some("bob".to_string()));
    }

    #[tokio::test]
    async fn untouched_cookie_less_session_gets_no_cookie() {
        let mut ctx = make_context(Request::builder().body(Body::empty()).unwrap());
        ctx.dispatch(cache(), Duration::from_secs(3600), &[], &NoopController).await.unwrap();
        assert!(ctx.into_response().headers().get("set-cookie").is_none());
    }

    #[tokio::test]
    async fn stale_cookie_gets_fresh_session_and_new_cookie() {
        let cache: Arc<dyn Cache> = Arc::new(MemoryCache::new());
        let request = Request::builder()
            .header("cookie", format!("{SESSION_COOKIE}=nonexistent-id"))
            .body(Body::empty())
            .unwrap();
        let mut ctx = make_context(request);
        ctx.dispatch(cache.clone(), Duration::from_secs(3600), &[], &NoopController).await.unwrap();
        assert_ne!(ctx.session().id(), "nonexistent-id");
        assert!(ctx.into_response().headers().contains_key("set-cookie"));
    }

    #[test]
    fn set_header_validates_value() {
        let mut ctx = make_context(Request::builder().body(Body::empty()).unwrap());
        assert!(ctx.set_header("X-Frame-Options", "DENY").is_ok());
        assert!(ctx.set_header("X-Test", "bad\r\nInjected: 1").is_err());
    }

    struct OrderRecorder {
        events: Arc<std::sync::Mutex<Vec<&'static str>>>,
    }

    impl Filter for OrderRecorder {
        fn before(&self, _ctx: &mut Context) -> Result<(), RouterError> {
            self.events.lock().unwrap().push("before");
            Ok(())
        }
        fn after(&self, _ctx: &mut Context) -> Result<(), RouterError> {
            self.events.lock().unwrap().push("after");
            Ok(())
        }
    }

    struct OrderedController {
        events: Arc<std::sync::Mutex<Vec<&'static str>>>,
    }

    #[async_trait]
    impl Controller for OrderedController {
        async fn prepare(&self, _ctx: &mut Context) -> Result<(), RouterError> {
            self.events.lock().unwrap().push("prepare");
            Ok(())
        }
        async fn handle(&self, _ctx: &mut Context) -> Result<(), RouterError> {
            self.events.lock().unwrap().push("handle");
            Ok(())
        }
        async fn finish(&self, _ctx: &mut Context) -> Result<(), RouterError> {
            self.events.lock().unwrap().push("finish");
            Ok(())
        }
    }

    #[tokio::test]
    async fn pipeline_runs_before_handle_after_finish_in_order() {
        let events = Arc::new(std::sync::Mutex::new(Vec::new()));
        let mut ctx = make_context(Request::builder().body(Body::empty()).unwrap());
        ctx.dispatch(
            cache(),
            Duration::from_secs(3600),
            &[&OrderRecorder { events: events.clone() }],
            &OrderedController { events: events.clone() },
        )
        .await
        .unwrap();
        assert_eq!(*events.lock().unwrap(), vec!["before", "prepare", "handle", "after", "finish"]);
    }
}
