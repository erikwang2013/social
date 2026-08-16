// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
use axum::http::StatusCode;
use security_rust::{DetectionResult, Scanner};

use crate::context::Context;
use crate::context::RouterError;
use crate::filter::Filter;

/// A [`Filter`] that scans incoming requests for malicious payloads using
/// the `security-rust` detection engine.
///
/// By default, 27 detectors cover XSS, SQL injection, command injection,
/// SSRF, path traversal, and more.  If any attack is detected, the request
/// is aborted with HTTP 400 and a message listing each matched attack type.
///
/// # Body scanning
///
/// The current implementation scans the URI query string and the `Cookie`,
/// `User-Agent`, and `Referer` headers.  Full request-body scanning requires
/// buffering the body before the controller runs and is planned for a future
/// release.
pub struct SecurityFilter {
    scanner: Scanner,
}

impl SecurityFilter {
    /// Create a filter with all 27 detectors enabled.
    pub fn new() -> Self {
        Self { scanner: Scanner::default() }
    }

    /// Create a filter backed by a custom-configured [`Scanner`] (e.g. one
    /// built via [`Scanner::builder()`]).
    pub fn with_scanner(scanner: Scanner) -> Self {
        Self { scanner }
    }

    /// Access the underlying scanner for direct use (e.g. scanning
    /// individual strings in a controller).
    pub fn scanner(&self) -> &Scanner {
        &self.scanner
    }
}

impl Default for SecurityFilter {
    fn default() -> Self {
        Self::new()
    }
}

impl Filter for SecurityFilter {
    fn before(&self, ctx: &mut Context) -> Result<(), RouterError> {
        // ── Scan the URI query string (percent-decoded) ────────────
        // The raw query is decoded first, otherwise encoded payloads
        // like %3Cscript%3E or %2e%2e%2f bypass the scan entirely.
        if let Some(query) = ctx.request.uri().query() {
            let decoded = percent_decode(query);
            let results = self.scanner.scan(&decoded);
            if !results.is_empty() {
                ctx.abort(
                    StatusCode::BAD_REQUEST,
                    &format_attack_message("query string", &results),
                );
                return Ok(());
            }
        }

        // ── Scan relevant request headers ──────────────────────────
        let headers = ctx.request.headers();
        let header_names = ["cookie", "user-agent", "referer"];

        for name in header_names {
            let value = match headers.get(name) {
                Some(v) => match v.to_str() {
                    Ok(s) => s,
                    // A non-UTF-8 header is suspicious in itself (binary
                    // payloads never pass to the scanners in a lossless
                    // form), so abort instead of skipping it.
                    Err(_) => {
                        ctx.abort(
                            StatusCode::BAD_REQUEST,
                            &format!("header `{name}` contains non-UTF-8 bytes"),
                        );
                        return Ok(());
                    }
                },
                None => continue,
            };
            let results = self.scanner.scan(value);
            if !results.is_empty() {
                ctx.abort(
                    StatusCode::BAD_REQUEST,
                    &format_attack_message(&format!("header `{name}`"), &results),
                );
                return Ok(());
            }
        }

        Ok(())
    }

    fn after(&self, _ctx: &mut Context) -> Result<(), RouterError> {
        Ok(())
    }
}

/// RFC 3986 percent-decode a string (`%20` → space, `%2B` → `+`).
/// Malformed sequences (e.g. `%ZZ`) are left as-is.
fn percent_decode(s: &str) -> String {
    let bytes = s.as_bytes();
    let mut out = Vec::with_capacity(bytes.len());
    let mut i = 0;
    while i < bytes.len() {
        if bytes[i] == b'%'
            && i + 2 < bytes.len()
            && let (Some(hi), Some(lo)) = (hex_val(bytes[i + 1]), hex_val(bytes[i + 2]))
        {
            out.push((hi << 4) | lo);
            i += 3;
            continue;
        }
        out.push(bytes[i]);
        i += 1;
    }
    String::from_utf8_lossy(&out).into_owned()
}

fn hex_val(b: u8) -> Option<u8> {
    match b {
        b'0'..=b'9' => Some(b - b'0'),
        b'a'..=b'f' => Some(b - b'a' + 10),
        b'A'..=b'F' => Some(b - b'A' + 10),
        _ => None,
    }
}

/// Format a human-readable abort message from a list of detection results.
fn format_attack_message(source: &str, results: &[DetectionResult]) -> String {
    let attacks: Vec<String> =
        results.iter().map(|r| format!("{} ({})", r.attack_type, r.severity)).collect();
    format!("attack detected in {}: {}", source, attacks.join(", "))
}

#[cfg(test)]
mod tests {
    use super::*;
    use axum::body::Body;
    use axum::http::{HeaderValue, Request};
    use bee_cache::MemoryCache;
    use std::path::Path;
    use std::sync::Arc;
    use std::time::Duration;

    fn make_context(uri: &str) -> Context {
        let cache: Arc<dyn bee_cache::Cache> = Arc::new(MemoryCache::new());
        let session = bee_session::Session::new(cache, Duration::from_secs(3600));
        let req = Request::builder().uri(uri).body(Body::empty()).unwrap();
        let engine =
            bee_template::TemplateEngine::new(Path::new("tests/fixtures/templates")).unwrap();
        Context::new(req, session, Arc::new(engine))
    }

    fn is_blocked(filter: &SecurityFilter, ctx: &mut Context) -> bool {
        filter.before(ctx).is_ok() && ctx.is_aborted()
    }

    #[test]
    fn test_percent_decode() {
        assert_eq!(
            percent_decode("%3Cscript%3Ealert(1)%3C/script%3E"),
            "<script>alert(1)</script>"
        );
        assert_eq!(percent_decode("%27 OR 1%3D1--"), "' OR 1=1--");
        assert_eq!(percent_decode("%2e%2e%2f"), "../");
        assert_eq!(percent_decode("%20"), " ");
        assert_eq!(percent_decode("%2B"), "+");
        assert_eq!(percent_decode("%ZZ"), "%ZZ");
        assert_eq!(percent_decode("plain"), "plain");
    }

    #[test]
    fn test_percent_encoded_xss_is_blocked() {
        let filter = SecurityFilter::new();
        let mut ctx = make_context("/search?q=%3Cscript%3Ealert(1)%3C/script%3E");
        assert!(is_blocked(&filter, &mut ctx));
    }

    #[test]
    fn test_percent_encoded_sql_injection_is_blocked() {
        let filter = SecurityFilter::new();
        let mut ctx = make_context("/login?user=%27%20OR%201%3D1--");
        assert!(is_blocked(&filter, &mut ctx));
    }

    #[test]
    fn test_percent_encoded_path_traversal_is_blocked() {
        let filter = SecurityFilter::new();
        let mut ctx = make_context("/download?file=%2e%2e%2fetc%2fpasswd");
        assert!(is_blocked(&filter, &mut ctx));
    }

    #[test]
    fn test_non_utf8_header_is_blocked() {
        let filter = SecurityFilter::new();
        let mut ctx = make_context("/");
        ctx.request.headers_mut().insert("cookie", HeaderValue::from_bytes(&[0xff, 0xfe]).unwrap());
        assert!(is_blocked(&filter, &mut ctx));
    }

    #[test]
    fn test_scanner_detects_xss() {
        let filter = SecurityFilter::new();
        let results = filter.scanner.scan("<script>alert(1)</script>");
        assert!(!results.is_empty());
        assert!(results.iter().any(|r| r.attack_type == "xss"));
    }

    #[test]
    fn test_scanner_detects_sql_injection() {
        let filter = SecurityFilter::new();
        let results = filter.scanner.scan("' OR '1'='1");
        assert!(!results.is_empty());
        assert!(results.iter().any(|r| r.attack_type == "sql_injection"));
    }

    #[test]
    fn test_scanner_detects_command_injection() {
        let filter = SecurityFilter::new();
        let results = filter.scanner.scan("$(rm -rf /)");
        assert!(!results.is_empty());
        assert!(results.iter().any(|r| r.attack_type == "command_injection"));
    }

    #[test]
    fn test_clean_input_passes() {
        let filter = SecurityFilter::new();
        let results = filter.scanner.scan("hello world");
        assert!(results.is_empty());
    }

    #[test]
    fn test_format_attack_message() {
        let results = vec![DetectionResult {
            attack_type: "xss".into(),
            category: security_rust::AttackCategory::Injection,
            severity: security_rust::Severity::Critical,
            matched_pattern: "<script>".into(),
            offset: 0,
            message: "XSS attack detected".into(),
        }];
        let msg = format_attack_message("query string", &results);
        assert!(msg.contains("xss"));
        assert!(msg.contains("CRITICAL"));
        assert!(msg.contains("query string"));
    }

    #[test]
    fn test_scanner_detects_path_traversal() {
        let filter = SecurityFilter::new();
        let results = filter.scanner.scan("../../../etc/passwd");
        assert!(!results.is_empty());
        assert!(results.iter().any(|r| r.attack_type == "path_traversal"));
    }
}
