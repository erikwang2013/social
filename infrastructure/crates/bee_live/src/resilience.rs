// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
//! 弹性三件套：熔断（CircuitBreaker）/ 限流（RateLimiter）/ 降级（fallback）。
//! 语义对齐 PHP 侧点状降级：短路期返回可预期错误，由上层 fallback 决定降级值。

use std::collections::HashMap;
use std::sync::Mutex;
use std::time::{Duration, Instant};

/// 熔断错误：`Open` = 短路期直接拒绝；`Inner` = 业务调用自身失败。
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum BreakerError<E> {
    Open,
    Inner(E),
}

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
enum State {
    Closed,
    Open,
    HalfOpen,
}

struct Inner {
    state: State,
    failures: u32,
    opened_at: Option<Instant>,
}

/// 熔断器：连续 `threshold` 次失败 → Open（短路 `open_duration`）；到期后放行一个
/// 探测调用（HalfOpen），成功回 Closed、失败重开。
pub struct CircuitBreaker {
    threshold: u32,
    open_duration: Duration,
    inner: Mutex<Inner>,
}

impl CircuitBreaker {
    pub fn new(threshold: u32, open_duration: Duration) -> Self {
        Self {
            threshold: threshold.max(1),
            open_duration,
            inner: Mutex::new(Inner {
                state: State::Closed,
                failures: 0,
                opened_at: None,
            }),
        }
    }

    pub fn call<T, E, F>(&self, f: F) -> Result<T, BreakerError<E>>
    where
        F: FnOnce() -> Result<T, E>,
    {
        let mut g = self.inner.lock().unwrap();
        match g.state {
            State::Open => {
                if g.opened_at.map(|t| t.elapsed() >= self.open_duration).unwrap_or(false) {
                    g.state = State::HalfOpen;
                } else {
                    return Err(BreakerError::Open);
                }
            }
            State::HalfOpen | State::Closed => {}
        }
        drop(g);

        match f() {
            Ok(v) => {
                let mut g = self.inner.lock().unwrap();
                g.failures = 0;
                g.opened_at = None;
                g.state = State::Closed;
                Ok(v)
            }
            Err(e) => {
                let mut g = self.inner.lock().unwrap();
                if g.state == State::HalfOpen {
                    g.state = State::Open;
                    g.opened_at = Some(Instant::now());
                } else {
                    g.failures += 1;
                    if g.failures >= self.threshold {
                        g.state = State::Open;
                        g.opened_at = Some(Instant::now());
                    }
                }
                Err(BreakerError::Inner(e))
            }
        }
    }
}

/// 固定窗口限流：key（如 uid+path）在 `window` 内最多 `limit` 次。
pub struct RateLimiter {
    limit: u32,
    window: Duration,
    buckets: Mutex<HashMap<String, Bucket>>,
}

struct Bucket {
    start: Instant,
    count: u32,
}

impl RateLimiter {
    pub fn new(limit: u32, window: Duration) -> Self {
        Self {
            limit: limit.max(1),
            window,
            buckets: Mutex::new(HashMap::new()),
        }
    }

    pub fn allow(&self, key: &str) -> bool {
        let mut g = self.buckets.lock().unwrap();
        let now = Instant::now();
        let b = g.entry(key.to_string()).or_insert(Bucket { start: now, count: 0 });
        if b.start.elapsed() >= self.window {
            b.start = now;
            b.count = 0;
        }
        if b.count >= self.limit {
            return false;
        }
        b.count += 1;
        true
    }
}

/// 降级组合子：主调用失败时返回降级值（缓存/提示），不向上抛错。
pub fn fallback<T, E, F, G>(primary: F, fb: G) -> T
where
    F: FnOnce() -> Result<T, E>,
    G: FnOnce() -> T,
{
    primary().unwrap_or_else(|_| fb())
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn breaker_opens_after_threshold_and_short_circuits() {
        let b = CircuitBreaker::new(3, Duration::from_secs(60));
        let r: Result<(), BreakerError<&str>> = b.call(|| Err("boom"));
        assert_eq!(r, Err(BreakerError::Inner("boom")));
        let r: Result<(), BreakerError<&str>> = b.call(|| Err("boom"));
        assert_eq!(r, Err(BreakerError::Inner("boom")));
        let r: Result<(), BreakerError<&str>> = b.call(|| Err("boom"));
        assert_eq!(r, Err(BreakerError::Inner("boom")));
        // 第 4 次：已 Open → 短路，闭包不执行
        let r: Result<(), BreakerError<&str>> = b.call(|| Err("should not run"));
        assert_eq!(r, Err(BreakerError::Open));
    }

    #[test]
    fn breaker_recovers_in_half_open() {
        let b = CircuitBreaker::new(1, Duration::from_millis(20));
        let _ = b.call::<(), &str, _>(|| Err("boom"));
        std::thread::sleep(Duration::from_millis(30));
        let r: Result<(), BreakerError<&str>> = b.call(|| Ok(()));
        assert_eq!(r, Ok(()));
        let r: Result<(), BreakerError<&str>> = b.call(|| Err("boom2"));
        assert_eq!(r, Err(BreakerError::Inner("boom2")));
        let r: Result<(), BreakerError<&str>> = b.call(|| Err("not run"));
        assert_eq!(r, Err(BreakerError::Open));
    }

    #[test]
    fn rate_limiter_blocks_after_limit() {
        let l = RateLimiter::new(3, Duration::from_secs(60));
        assert!(l.allow("u1:/api"));
        assert!(l.allow("u1:/api"));
        assert!(l.allow("u1:/api"));
        assert!(!l.allow("u1:/api"));
        assert!(l.allow("u2:/api"));
    }

    #[test]
    fn fallback_returns_degraded_value() {
        let v = fallback(|| Err::<i32, &str>("main down"), || 42);
        assert_eq!(v, 42);
        let v = fallback(|| Ok::<i32, &str>(7), || 42);
        assert_eq!(v, 7);
    }
}
