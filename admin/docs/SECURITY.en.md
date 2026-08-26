# Security Architecture Design Document

**语言 / Languages:** [中文](SECURITY.md) · [English](SECURITY.en.md) · [한국어](SECURITY.ko.md) · [Русский](SECURITY.ru.md) · [Deutsch](SECURITY.de.md) · [Français](SECURITY.fr.md) · [Español](SECURITY.es.md) · [Português](SECURITY.pt.md) · [हिन्दी](SECURITY.hi.md) · [العربية](SECURITY.ar.md) · [বাংলা](SECURITY.bn.md) · [Bahasa Indonesia](SECURITY.id.md) · [日本語](SECURITY.ja.md)

> Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz

## 1. Defense-in-Depth Overview

The system adopts a 7-layer defense-in-depth model. Malicious requests are filtered layer by layer from the outside in, so that even if any single layer fails, subsequent defense lines still provide coverage.

The entire middleware chain executes in the following order (see `config/middleware.php`):

```
请求 → Cors → Locale(Accept-Language) → SecurityMiddleware (erikwang2013/security-php, 31种检测器) → RateLimit → [路由组中间件: AdminAuth → AdminPermission → OperationLog] → Controller
```

| Layer | Middleware / Mechanism | Protection Target |
|----|--------|---------|
| 1 | SecurityMiddleware (erikwang2013/security-php) | 31 attack detections + HTTP method validation + request body size limit + Content-Type validation + CSRF + IP attack escalation blacklist |
| 2 | Cors | Cross-origin security + security response header injection |
| 3 | RateLimit | Redis sliding-window rate limiting, prevents brute force |
| 4 | AdminAuth | JWT authentication + blacklist logout |
| 5 | AdminPermission | RBAC method.path granularity authorization |
| 6 | OperationLog | Operation audit + source client tracking |
| 7 | Data encryption | Hashids ID obfuscation + Encryptable DB encryption + EncryptionService transport encryption |

The frontend layers (Flutter) perform their own independent input validation; the backend trusts nothing, and each layer defends independently.

---

## 2. Attack Detection Engine

## 2. 攻击检测引擎 (erikwang2013/security-php)

Attack detection has been migrated from the in-house SecurityMiddleware to the dedicated security package `erikwang2013/security-php` v1.1+, which provides **31 detectors** covering 5 major attack categories.

### 2.1 Detector Categories

**Injection attacks (11):** XSS, SQL injection, command injection, NoSQL injection, LDAP injection, XPath injection, JNDI/Log4Shell, SSI server-side includes, GraphQL injection, SSTI template injection

**Protocol and request attacks (9):** SSRF, XXE, HTTP response header injection, Host header attack, Request Smuggling, Open Redirect, CORS bypass, WebSocket hijacking, DNS Rebinding

**HTTP protocol layer validation (6):** HTTP method validation (405), request body size limit (413), Content-Type validation (415), CSRF Origin check, IP attack escalation blacklist, sensitive data leak detection

**Data and serialization attacks (5):** PHP deserialization, CSV formula injection, email header injection, JWT attacks (structured analysis), JS Prototype Pollution

**File and path attacks (2):** path traversal, malicious file upload

### 2.2 Handling Modes

Each detector independently supports two modes:
- `block` — intercept when an attack is detected, return the configured status code
- `log` — only log, do not intercept (`header_injection`, `ssti`, `nosql_injection` default to log mode to prevent false positives)

### 2.3 IP Attack Escalation Blacklist

If the same IP triggers 5 attack detections within 60 seconds, it is automatically banned for 15 minutes. The storage backend can be Redis (distributed), File (single-node JSON), or Cache (independent files for high concurrency); the current configuration uses Redis storage.

### 2.4 Security Logs

File location: `runtime/logs/security.log` (auto-rotating, 10MB per file)

---

## 4. Security Response Headers

All headers are injected in the `Cors` middleware and appended to every response via `$response->withHeaders()`.

| Header | Value | Purpose |
|----|-----|------|
| Access-Control-Allow-Origin | `*` | Allow cross-origin requests from any source (intranet admin console scenario) |
| Access-Control-Allow-Methods | `GET,POST,PUT,DELETE,OPTIONS` | Allowed method set |
| Access-Control-Allow-Headers | `Authorization,Content-Type,API-Version` | Allowed custom headers |
| Access-Control-Max-Age | `86400` | Preflight response cached for 24 hours |
| X-Content-Type-Options | `nosniff` | Prevent browser MIME sniffing |
| X-Frame-Options | `DENY` | Deny all iframe embedding, prevent clickjacking |
| X-XSS-Protection | `1; mode=block` | Enable the built-in browser XSS filter and block page rendering |
| Referrer-Policy | `strict-origin-when-cross-origin` | Same-origin sends full URL, cross-origin sends domain only |
| Permissions-Policy | `camera=(), microphone=(), geolocation=()` | Disable camera/microphone/geolocation APIs site-wide |

OPTIONS preflight requests directly return an empty 204 response and do not enter the subsequent middleware chain.

### 4.2 Content-Security-Policy (CSP)

Injected together with the other security headers in the Cors middleware, providing defense in depth by restricting the resource origins the browser may load and execute.

| Header | Value | Purpose |
|----|-----|------|
| Content-Security-Policy | `default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'` | Restrict the origins of scripts/styles/images/connections/frames/forms and other resources |
| X-Permitted-Cross-Domain-Policies | `none` | Prevent cross-domain policy files such as Adobe Flash/PDF from loading |

CSP policy highlights:
- `default-src 'self'`: only same-origin resources allowed by default
- `script-src 'self' 'unsafe-inline' 'unsafe-eval'`: allows same-origin scripts + inline scripts (required by Flutter Web) + eval (required for Flutter Web debugging)
- `frame-ancestors 'none'`: forbid embedding in any page's iframe, double protection with X-Frame-Options: DENY
- `base-uri 'self'`: restrict `<base>` tags to same-origin only
- `form-action 'self'`: restrict forms to submit only to same-origin

---

## 5. Rate Limiting Strategy

### Algorithm

Redis Sorted Set sliding window + atomic Lua script, for critical operations:

```lua
-- 1. 清理窗口外的旧记录
redis.call('ZREMRANGEBYSCORE', KEYS[1], 0, windowStart)
-- 2. 检查当前窗口计数
local count = redis.call('ZCARD', KEYS[1])
-- 3. 超限则返回 {0, count}，未超限则 ZADD 并返回 {1, count+1}
if count >= limit then return {0, count} end
redis.call('ZADD', KEYS[1], now, now . '.' . random)  -- 随机后缀避免同毫秒覆盖
redis.call('EXPIRE', KEYS[1], window + 10)
```

The Lua script executes single-threaded on the Redis server, **naturally atomic**, eliminating TOCTOU (Time-of-check to Time-of-use) race conditions.

### Rate Limit Configuration

| Route | Limit | Window | Scenario |
|------|------|------|------|
| Default (all routes) | 60 times/minute | 60s | General API |
| `/api/auth/login` | 10 times/minute | 60s | Login (anti brute force) |
| `/api/auth/register` | 5 times/minute | 60s | Registration (anti mass registration) |

### Response Headers

When rate limiting is triggered, HTTP 429 with a JSON body is returned:
```json
{"code": 429, "message": "请求过于频繁，请稍后再试", "data": []}
```

All responses (including normal ones) carry the following headers:

| Header | Description |
|----|------|
| X-RateLimit-Limit | Maximum number of requests allowed in the current window |
| X-RateLimit-Remaining | Remaining requests available in the current window |
| X-RateLimit-Reset | Unix timestamp when the window resets |
| Retry-After | Only present when rate limited; suggested wait seconds |

### Degradation Strategy

When Redis is abnormal (connection timeout, unavailable, etc.), **fail-open**:

```php
try {
    $result = Redis::eval($lua, ...);
} catch (\Throwable $e) {
    return $handler($request); // Redis down, 放行所有请求
}
```

Better to temporarily lose rate-limit protection than to block normal business requests.

### 5.4 Account Lockout Mechanism

On top of rate limiting, the login endpoint adds an **account lockout** mechanism to prevent targeted brute-force attacks against specific users.

**Lockout flow**:

```
登录失败 → Redis INCR account_lockout:{userId} TTL=900s
连续 5 次失败 → Redis SETEX account_locked:{userId} 900 1
            → 返回 429 "账号已被锁定，请15分钟后再试"
            → 清除计数器 DEL account_lockout:{userId}
```

**Behavior during lockout**:

During the lockout period, all login requests directly return 429 without password verification, completely blocking brute-force attempts.

**Configuration constants**:

| Constant | Value | Meaning |
|------|-----|------|
| MAX_LOGIN_ATTEMPTS | 5 | Maximum consecutive failures |
| LOCKOUT_DURATION | 900 | Lockout duration in seconds, i.e. 15 minutes |

Note: account lockout is based on `userId` rather than IP, so attackers cannot bypass it by changing IP. Combined with IP rate limiting (10 times/minute), this forms dual protection:
- IP level: 10 times/minute rate limiting prevents distributed brute force
- Account level: lockout after 5 failures prevents targeted brute force

---

## 6. Authentication and Authorization

### 6.1 JWT Authentication

Implemented by the AdminAuth middleware, mounted on route groups requiring authentication.

**Parameter configuration** (`config/plugin/erikwang2013/jwt/jwt`, injected via `.env`):

| Parameter | Value | Description |
|------|-----|------|
| Algorithm | HS256 | HMAC-SHA256 symmetric signing |
| Secret | `JWT_SECRET` | Injected via environment variable; must be changed in production |
| access_token TTL | 7200s (2h) | `JWT_TTL` |
| refresh_token TTL | 1209600s (14d) | `JWT_REFRESH_TTL` |
| Issuer | `open-admin` | `JWT_ISSUER` |
| Audience | `open-admin` | `JWT_AUDIENCE` |

**Token extraction**: extracted from the `Authorization: Bearer <token>` header; strip the `Bearer ` prefix to obtain the raw JWT.

**Authentication flow**:
1. Empty token → direct 401 `{"code": 401, "message": "未登录"}`
2. Check Redis blacklist `jwt_blacklist:{md5(token)}` → hit → 401 `Token已失效，请重新登录`
3. JWT decode → failure (expired/signature mismatch) → 401 `Token已过期或无效`
4. Success → inject `$request->adminId` and `$request->adminUsername`

**Blacklist mechanism**: on logout, `md5(token)` is written to Redis with TTL set to the JWT's remaining validity. When Redis fails, the blacklist check is skipped (fail-open); at this point a logged-out token can still be used for a short time, but the JWT's own short validity (2h) serves as fallback protection.

### 6.2 Concurrent Session Limit

To prevent a leaked token from being abused across multiple devices, the system limits the number of valid tokens a single user can hold concurrently.

**Limit logic**:

```
登录成功 → 签发新 Token
         → 查询当前用户有效 Token 数量: Redis SCARD user_tokens:{userId}
         → 若数量 >= 3（MAX_CONCURRENT_SESSIONS）:
            → 按创建时间升序排列，移除最旧的 Token:
              Redis SREM user_tokens:{userId} <oldest_token_id>
              Redis SETEX jwt_blacklist:{md5(oldest_token)} TTL remaining
         → 将新 Token 加入集合: Redis SADD user_tokens:{userId} <new_token_id>
            Redis SET user_token:{token_id} {userId} EX {TTL}
```

**Configuration constant**:

| Constant | Value | Meaning |
|------|-----|------|
| MAX_CONCURRENT_SESSIONS | 3 | Maximum concurrent tokens per user |

**Forced logout scenario**: when the user logs in on a 4th device, the token on the 1st device is forcibly added to the blacklist, and subsequent requests return 401 "Token已失效，请重新登录".

On logout, the current token is removed from the set. When a token expires naturally, the Redis key expires automatically and the set membership shrinks accordingly.

### 6.3 RBAC Permission Model

Implemented by the AdminPermission middleware.

**Data model**: User -> Role -> Permission three-level association

- `erik_admin_user` (user table)
- `erik_admin_user_role` (user-role association table)
- `erik_admin_role` (role table)
- `erik_admin_role_permission` (role-permission association table)
- `erik_admin_permission` (permission table)

**Permission types**:
| type | Meaning | Example |
|------|------|------|
| 1 | Menu permission | Controls left navigation visibility |
| 2 | Button permission | Controls in-page action buttons (create/edit/delete) |
| 3 | API permission | Controls backend API calls |

API permission identifier format: `{method}.{path}`

For example:
- `post.admin/user` — create user
- `put.admin/user` — edit user
- `delete.admin/user` — delete user
- `get.admin/user` — view user list

**Authorization flow**:
1. `$request->adminId` empty → allow (route has no authentication prerequisite configured)
2. Get user → roles (skip disabled roles with `status=0`) → permission list
3. Super admin (`slug = '*'`) → allow directly
4. Build `strtolower(method) . '.' . trim(path, '/')` → compare against permission list
5. No match → 403 `{"code": 403, "message": "无权限访问"}`

**Second confirmation**: BaseController provides the `confirmPassword()` method; sensitive operations (delete user, data export, etc.) additionally require entering the current password at the Controller layer, preventing unauthorized operations after session hijacking.

---

## 7. Audit Logs

### 7.1 Operation Logs

The OperationLog middleware automatically records operation logs for POST / PUT / DELETE requests. GET requests are not recorded.

**Recorded fields**:

| Field | Source | Description |
|------|------|------|
| id | SnowflakeService::generate() | Globally unique ID |
| user_id | `$request->adminId` | Operator ID, 0 if not logged in |
| action | `$request->method()` | Same as method |
| method | `$request->method()` | POST / PUT / DELETE |
| path | `$request->path()` | Request path |
| ip | `$request->getRealIp()` | Client real IP |
| source | detectSource() | Client source platform |
| input | Request body (masked JSON) | Submitted operation data |
| created_at | `date('Y-m-d H:i:s')` | Operation time |

**Sensitive field filtering**: recursively traverses the request body; values of the following fields are replaced with `***`:

`password`, `old_password`, `new_password`, `new_password_confirmation`, `token`, `secret`, `access_token`, `refresh_token`

**Source detection** (`detectSource()`): by priority:

1. First read the `X-Client-Platform` custom header (explicitly declared by native clients)
2. Fall back to User-Agent string inference (`detectSource()` method detection order):

| Platform | UA Keywords |
|------|----------|
| iPadOS | `iPad` |
| macOS | `Macintosh`, `Mac OS` |
| Windows | `Windows` |
| Linux | `Linux` |
| iOS | `iPhone`, `iOS`, `CFNetwork` |
| Android | `Android` |
| HarmonyOS | `HarmonyOS`, `OpenHarmony` |
| Web | Fallback default |

**Fault tolerance**: log write exceptions do not block business requests (`catch (\Throwable)` silently swallowed).

### 7.2 Security Logs

**File location**: `runtime/logs/security.log`

**Recorded content**:
- Attack interception logs: attack category, IP, path, field, source, payload snippet (first 200 characters)
- IP ban notifications: banned IP, trigger count

Log permission is `FILE_APPEND | LOCK_EX` to ensure concurrency-safe writes.

---

## 8. Data Protection

The system adopts a three-layer data protection strategy, corresponding to the three stages of data flow.

### 8.1 Transport Layer — EncryptionService

`EncryptionService` uses the `erikwang2013/encryption` package to encrypt/decrypt sensitive fields in API requests/responses.

**Technical details**:
- Algorithm: `aes-256-cbc-hmac` (built-in HMAC signature prevents tampering)
- Key: `ENCRYPTION_KEY` environment variable, automatically aligned to 32 bytes
- Used for: transporting fields such as phone numbers and ID card numbers between client and API

**Masking utility methods**:
- `maskPhone('13812341234')` → `138****1234`
- `maskEmail('abc@example.com')` → `a***@example.com` (username longer than 2 characters) or `a**@example.com`

### 8.2 Storage Layer — Encryptable Cast

The `AdminUser` model uses the `Erikwang2013\Encryptable\Encryptable` Eloquent cast, with the corresponding fields:

- `email` → cast as Encryptable, automatically encrypted/decrypted
- `phone` → cast as Encryptable, automatically encrypted/decrypted
- `id_card` → cast as Encryptable, automatically encrypted/decrypted

Written to the database as encrypted ciphertext automatically, and decrypted back to plaintext automatically when read. The database column type is `VARCHAR(500)`, and ciphertext is stored as base64.

**Key system**: uses `ENCRYPTABLE_KEY` independently from transport-layer encryption (`ENCRYPTION_KEY`); a leak of one key does not compromise the other layer.

Key rotation: the `ENCRYPTION_PREVIOUS_KEYS` environment variable supports a list of historical keys (comma-separated). When reading old data, historical keys are tried for decryption; on write-back, the current key re-encrypts.

### 8.3 Presentation Layer — ID Obfuscation and Masking

**Hashids ID obfuscation**: `HashidsService` uses the `erikwang2013/hashids` package.

- Database BIGINT IDs returned by external APIs are encoded as hash strings (e.g. `xK3mN9qR2pL7wV8b`)
- Clients pass the hash string in requests; the backend automatically decodes it back to the original ID
- Salt injected via the `HASHIDS_SALT` environment variable; different salts produce completely different encoding/decoding results
- Minimum hash length is 16 characters, using a 62-character alphanumeric charset
- BaseController provides convenience methods `encodeId()`, `decodeId()`, `encodeIds()`

**Export masking**: during Excel/PDF export (ExportController), sensitive fields are uniformly masked:
- Phone: `138****1234`
- Email: `a***@example.com`
- ID card: fully covered as `********`

---

## 9. Key Management

All keys are injected via `.env` environment variables; config files read them with `getenv()` and have built-in fallback defaults (safe for development environments only).

| Environment Variable | Purpose | Package | Production Requirement |
|----------|------|-----|---------|
| JWT_SECRET | JWT signing key | erikwang2013/jwt-webman | Random string of 64+ characters |
| JWT_ALGORITHM | JWT signing algorithm | same as above | Keep HS256 |
| HASHIDS_SALT | ID encoding salt | erikwang2013/hashids | Random string |
| SNOWFLAKE_DATACENTER_ID | Datacenter ID (0-31) | erikwang2013/snowflake-php | Keep default for single datacenter |
| ENCRYPTION_KEY | API transport layer encryption key | erikwang2013/encryption | 32-byte random string |
| ENCRYPTABLE_KEY | DB storage layer encryption key | erikwang2013/encryptable | 32-byte random string, different from the transport key |

**Security requirements**:
- `.env` is in `.gitignore` and must never be committed to the repository
- `.env.example` is a public template file containing no real keys
- In production, **must** replace all default keys with random strings
- Recommended: generate keys with `openssl rand -base64 32`

### Key Storage Isolation

| Layer | Config Key | Key Environment Variable |
|----|--------|-------------|
| Transport encryption | `config/encryption.php` → `key` | `ENCRYPTION_KEY` |
| Storage encryption | `config/encryptable.php` → `key` | `ENCRYPTABLE_KEY` |
| ID obfuscation | `config/hashids.php` → `connections.main.salt` | `HASHIDS_SALT` |
| JWT signing | `config/plugin/erikwang2013/jwt/jwt` | `JWT_SECRET` |

---

## 10. security.txt (RFC 9116)

The system provides an RFC 9116-compliant security contact endpoint at `/.well-known/security.txt`, making it easy for security researchers to find the reporting channel when they discover vulnerabilities.

**Access method**:

```
GET /.well-known/security.txt
```

**Response content**:

```text
Contact: mailto:security@erik.xyz
Expires: 2027-05-20T00:00:00.000Z
Preferred-Languages: zh, en
Canonical: https://erik.xyz/.well-known/security.txt
Policy: https://erik.xyz/security-policy
```

**Field descriptions**:

| Field | Description |
|------|------|
| Contact | Contact for reporting security vulnerabilities |
| Expires | File expiration time, needs regular updates |
| Preferred-Languages | Preferred communication languages |
| Canonical | Canonical URL of this file |
| Policy | Security policy / vulnerability disclosure policy link |

This endpoint is not subject to rate limiting, authentication, or other middleware; anyone can access it directly.

---

## 11. Nginx Security Configuration

The project provides `docs/nginx-security.conf` as a reference configuration for hardening the Nginx reverse proxy in production.

**Included security measures**:

| Config Item | Purpose |
|--------|------|
| `server_tokens off` | Hide the Nginx version number |
| `client_max_body_size 10m` | Limit request body size, coordinated with SecurityMiddleware (erikwang2013/security-php) |
| `limit_req_zone` | Request frequency limiting at the Nginx level |
| `limit_conn_zone` | Concurrent connection limiting |
| `add_header` security headers | Append security headers such as X-XSS-Protection at the Nginx level |
| `if ($request_method)` | Reject non-standard HTTP methods at the Nginx level |
| SSL/TLS configuration | Modern TLS 1.2/1.3 configuration, weak cipher suites disabled |
| Hide backend headers | `proxy_hide_header` removes sensitive headers such as the webman version |

**Usage**: merge the configuration in `docs/nginx-security.conf` into your Nginx server block, adjusting for your actual domain and certificate paths.

---

## 12. Threat Model

### 12.1 Protected Threats

| Threat Type | Attack Vector | Defense Layer |
|----------|---------|---------|
| HTTP method abuse | TRACE/TRACK XST attacks, CONNECT tunnel proxy, WebDAV method probing | SecurityMiddleware http_method detector 405 method whitelist |
| Targeted brute force | Repeated password attempts against a specific user | Account lockout (15 min lock after 5 failures) + RateLimit (login 10/min) + Captcha |
| Brute force | Distributed IPs repeatedly trying usernames/passwords | RateLimit (login 10/min) + Captcha |
| XSS cross-site scripting | `<script>`, onerror, javascript: | SecurityMiddleware (erikwang2013/security-php) (5 patterns) + X-XSS-Protection response header + CSP |
| SQL injection | UNION SELECT, OR 1=1, comment bypass | SecurityMiddleware (erikwang2013/security-php) (6 patterns) + Eloquent ORM parameterized queries |
| CSRF cross-site request forgery | Malicious websites forging requests | SecurityMiddleware (erikwang2013/security-php) Origin/Referer validation |
| Path traversal | `../../etc/passwd` | SecurityMiddleware (erikwang2013/security-php) path traversal pattern + UploadController extension whitelist |
| Command injection | `;ls`, `` `whoami` ``, `$(cat ...)` | SecurityMiddleware (erikwang2013/security-php) (4 patterns) |
| Session hijacking | Stealing JWT tokens | Short JWT validity (2h) + blacklist logout + second password confirmation for sensitive operations |
| ID enumeration | Guessing data volume by iterating numeric IDs | Hashids obfuscation into random strings |
| Data leakage | DB dump / man-in-the-middle / log leakage | Three-layer encryption/masking + OperationLog sensitive field filtering |
| DoS attacks | Oversized request bodies / high-frequency requests | 10MB request body limit + RateLimit 60/min + IP blacklist |
| Privilege escalation | Low-privilege users accessing admin interfaces | RBAC method.path granularity authorization |
| File upload attacks | shell.php.png double extension | SecurityMiddleware (erikwang2013/security-php) malicious file detection |

### 12.2 Known Limitations

| Limitation | Impact | Mitigation |
|------|---------|---------|
| CSRF protection only works for browsers | Non-browser clients (curl, Postman, mobile apps) can skip Origin/Referer checks | Non-browser clients are naturally immune to CSRF; rely on JWT authentication instead of cookies |
| Rate limiting and blacklist degrade to fail-open when Redis is unavailable | Attackers can bypass rate limiting and high-frequency interception | Monitor Redis availability with alerts; IP blacklist supports file/redis/cache backends for degradation |
| No standalone WAF engine | Regex-based detection, not a dedicated WAF rule engine | Recommend Nginx ModSecurity or Cloudflare WAF in front in production |
| Stateless JWT cannot be actively invalidated | Tokens cannot be revoked server-side before expiry (except via blacklist) | Blacklist + short 2h TTL reduces the risk window |
| Admin endpoints have no special rate limiting | Admin APIs share the default 60/min limit with regular APIs | Admin operation frequency is naturally low; no differentiation needed for now |
| PCRE backtracking limit | The package has a built-in 1,000,000 backtracking cap + finally recovery; extremely complex inputs still pose a performance risk | Request body size limit (10MB) as fallback |
