// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
use crate::context::{Context, RouterError};

/// Middleware-like filter with `before` and `after` hooks that run around
/// controller execution, orchestrated by [`Context::dispatch`](crate::Context::dispatch).
/// Implementors can inspect or modify the [`Context`] and abort the request
/// early.
///
/// Session restore/persist is handled by [`Context::dispatch`]
/// (crate::Context::dispatch), not by a filter: `before` is synchronous while
/// session loading is async.
pub trait Filter: Send + Sync {
    fn before(&self, _ctx: &mut Context) -> Result<(), RouterError> {
        Ok(())
    }
    fn after(&self, _ctx: &mut Context) -> Result<(), RouterError> {
        Ok(())
    }
}
