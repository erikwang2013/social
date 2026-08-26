# API Reference Documentation
**语言 / Languages:** [中文](API.md) · [English](API.en.md) · [한국어](API.ko.md) · [Русский](API.ru.md) · [Deutsch](API.de.md) · [Français](API.fr.md) · [Español](API.es.md) · [Português](API.pt.md) · [हिन्दी](API.hi.md) · [العربية](API.ar.md) · [বাংলা](API.bn.md) · [Bahasa Indonesia](API.id.md) · [日本語](API.ja.md)

> Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz

## 1. Overview

The open-admin management console is built on webman v2 and provides a RESTful JSON API. All admin endpoints require JWT authentication and RBAC permission checks; public endpoints are routed to versioned controllers via the API version header.

- **Base URL**: `http://localhost:8787`
- **API version**: controlled via the `API-Version: v1` request header (defaults to v1 when missing)
- **Language**: switch via the `Accept-Language` header or the `?lang=zh_CN|en` parameter (default zh_CN), auto-detected by the Locale middleware

> **Endpoint overview**: Auth(5) | Dashboard(1) | Users(7) | Roles(4) | Permissions(4) | Config(4) | Logs(1) | Profile(3) | Import/Export(3) | Upload(1) | Ops(4: health/metrics/docs/security.txt) | 37 endpoints in total
- **Authentication**: `Authorization: Bearer <token>` (JWT)
- **Response format**: `{ "code": 0, "message": "success", "data": {...} }`
- **Docs endpoint**: `GET /api/docs` returns the OpenAPI 3.0 JSON specification

### Request requirements

- Only `GET` / `POST` / `PUT` / `DELETE` / `OPTIONS` / `HEAD` methods are allowed; other HTTP methods (e.g. TRACE, CONNECT, PATCH) return 405
- All `POST` / `PUT` requests must set `Content-Type: application/json` (except file uploads), otherwise 415 is returned
- The request body must not exceed 10MB, otherwise 413 is returned
- The security filter scans all request inputs for XSS, SQL injection, path traversal, and command injection; hits return 403
- 5 consecutive login failures trigger an account lockout (15 minutes); login requests during the lockout return 429
- A user can hold at most 3 valid tokens simultaneously; when exceeded, the oldest token is automatically blacklisted

## 2. Error codes

| code | Meaning | Trigger |
|------|------|---------|
| 0 | Success | |
| 400 | Bad request parameters | Request format is incorrect |
| 401 | Unauthenticated | Token missing / expired / blacklisted |
| 403 | No permission / security block | Insufficient RBAC permissions / SecurityFilter hit |
| 404 | Resource not found | The target of query/update/delete does not exist |
| 405 | Method not allowed | Only GET/POST/PUT/DELETE/OPTIONS/HEAD allowed; non-standard methods are rejected directly |
| 413 | Request body too large | Content-Length exceeds 10MB |
| 415 | Unsupported media type | POST/PUT Content-Type is not JSON and not a file upload |
| 422 | Parameter validation failed | Required fields missing, wrong format, or business validation failed |
| 429 | Too many requests | RateLimit triggered / account lockout (5 consecutive login failures lock for 15 minutes) |
| 500 | Internal server error | |

## 3. Public endpoints

All public endpoints are mounted under the `/api` group and dispatched by the `ApiVersion` middleware to the versioned controller corresponding to the `API-Version` header (e.g. `app\api\v1\controller\AuthController`).

### 3.1 Health check

```
GET /health
```

- **Authentication**: none required
- **Rate limit**: none

**Response example**:
```json
{
  "code": 0,
  "message": "success",
  "data": {
    "app": "open-admin",
    "version": "1.0",
    "php": "8.3.0",
    "database": "ok",
    "redis": "ok",
    "elasticsearch": "ok",
    "timestamp": 1716249600
  }
}
```

Values of `database`, `redis`, `elasticsearch`: `"ok"` | `"unavailable"`. `elasticsearch` returns `"unavailable"` when ES is unreachable; when the cluster health status is not green/yellow, the actual status value is returned (e.g. `"red"`).

### 3.2 API documentation

```
GET /api/docs
```

- **Authentication**: none required
- **Rate limit**: global default (60/min)
- **Response**: OpenAPI 3.0.3 JSON specification, including all endpoint definitions, parameters and schemas

### 3.3 Generate captcha

```
POST /api/captcha/generate
```

- **Authentication**: none required
- **Request header**: `API-Version: v1` (required)
- **Rate limit**: global default (60/min)

**Request body**:
```json
{
  "difficulty": "medium"
}
```

| Field | Type | Required | Description |
|------|------|------|------|
| difficulty | string | No | `easy` / `medium` / `hard`, default `medium` |

**Response example** — click type (`type: "click"`):
```json
{
  "code": 0,
  "message": "success",
  "data": {
    "key": "abc123def456",
    "type": "click",
    "image": "data:image/png;base64,iVBORw0KGgo...",
    "extra": {
      "targets": [
        { "order": 1, "text": "A", "x": 120, "y": 85 },
        { "order": 2, "text": "B", "x": 310, "y": 42 }
      ]
    }
  }
}
```

**Response example** — slider type (`type: "slider"`):
```json
{
  "code": 0,
  "message": "success",
  "data": {
    "key": "def456abc789",
    "type": "slider",
    "image": "data:image/png;base64,iVBORw0KGgo...",
    "extra": {
      "x": 120,
      "y": 60,
      "puzzle_w": 50,
      "puzzle_h": 50,
      "puzzle": "data:image/png;base64,iVBORw0KGgo..."
    }
  }
}
```

**Response example** — rotate type (`type: "rotate"`):
```json
{
  "code": 0,
  "message": "success",
  "data": {
    "key": "ghi789abc012",
    "type": "rotate",
    "image": "data:image/png;base64,iVBORw0KGgo...",
    "extra": {
      "angle": 45
    }
  }
}
```

| Field | Type | Description |
|------|------|------|
| key | string | Captcha identifier, passed back when verifying |
| type | string | Captcha type: `click` / `slider` / `rotate` |
| image | string | base64 data URI image |
| extra | object | Type-specific additional data (see below) |

**`extra` by type**:

| type | extra fields | Type | Description |
|------|-----------|------|------|
| click | targets | array | Click targets, containing `order` (sequence) `text` (prompt text) `x` `y` (coordinates) |
| slider | x, y | int | Coordinates of the top-left corner of the gap (based on a 300×200 canvas) |
| slider | puzzle_w, puzzle_h | int | Puzzle image width and height |
| slider | puzzle | string | Puzzle image base64 data URI |
| rotate | angle | int | Correct rotation angle (0-359); rotate by `360-angle` to right the image |

### 3.4 Verify captcha

```
POST /api/captcha/verify
```

- **Authentication**: none required
- **Request header**: `API-Version: v1` (required)
- **Rate limit**: global default (60/min)

**Request body** — click type (`type: "click"`):
```json
{
  "key": "abc123def456",
  "type": "click",
  "clicks": [
    { "x": 120, "y": 85 },
    { "x": 310, "y": 42 }
  ]
}
```

**Request body** — slider type (`type: "slider"`):
```json
{
  "key": "def456abc789",
  "type": "slider",
  "clicks": 120
}
```

**Request body** — rotate type (`type: "rotate"`):
```json
{
  "key": "ghi789abc012",
  "type": "rotate",
  "clicks": 315
}
```

| Field | Type | Required | Description |
|------|------|------|------|
| key | string | Yes | Captcha key, returned by generate |
| type | string | Yes | Captcha type, must match the `type` returned by generate |
| clicks | variant | Yes | Answer data, format varies by type (see below) |

**`clicks` by type**:

| type | clicks type | Description | Error tolerance |
|------|------------|------|---------|
| click | `[{x:int, y:int}]` | Array of click coordinates, in order order | 18px radius |
| slider | `int` | Slider X-axis offset | ±4px |
| rotate | `int` | Rotation angle (0-359) | ±5° |

**Response example**:
```json
{
  "code": 0,
  "message": "验证通过",
  "data": { "valid": true }
}
```

After successful verification, the backend writes `captcha_verified:{key}` to Redis (TTL 300s), which the login endpoint checks to allow the request through.
On verification failure, `code` is 422, `message` is `"验证失败，请重试"`, and `data.valid` is `false`.

### 3.5 Login

```
POST /api/auth/login
```

- **Authentication**: none required
- **Request header**: `API-Version: v1` (required)
- **Rate limit**: 10/min (per IP + path)

**Request body**:
```json
{
  "username": "admin",
  "password": "djGYscnyS5V6mW6KyDFjB8vGwjBBnB3Odpyxu8LY...",
  "captcha_key": "abc123def456"
}
```

| Field | Type | Required | Validation rule | Description |
|------|------|------|---------|------|
| username | string | Yes | min:3, max:50 | Username |
| password | string | Yes | min:6, max:32 (plaintext) | AES-256-CBC-HMAC encrypted then Base64 encoded (plaintext also compatible) |
| captcha_key | string | Yes | | Captcha key (must be verified via `/api/captcha/verify` first) |

### Password encryption protocol

Uses **RSA-2048 asymmetric encryption**; the public key lives in the frontend code (safe to expose), the private key is held only by the server.

```
Encryption flow (client):
  RSA public key (PEM) → PKCS1v1.5 encrypt → Base64 encode → transmit

Decryption flow (server, stepwise fallback):
  1. RSA private key decrypt → success and valid UTF-8 → use decrypted result
  2. AES-256-CBC-HMAC decrypt → success → use decrypted result (legacy client compatibility)
  3. Plaintext fallback → use the raw input directly
```

The public key is embedded in the frontend app and does not need to be transmitted over the network. The private key is stored only in `RSA_PRIVATE_KEY` in `.env` and must never be leaked.

> AES symmetric encryption is a legacy compatibility scheme and will be removed once all clients migrate to RSA.

**Response example**:
```json
{
  "code": 0,
  "message": "登录成功",
  "data": {
    "access_token": "eyJhbGciOiJIUzI1NiIs...",
    "refresh_token": "eyJhbGciOiJIUzI1NiIs...",
    "expires_in": 7200,
    "user": {
      "id": "a1b2c3d4",
      "username": "admin",
      "real_name": "管理员"
    }
  }
}
```

| Field | Type | Description |
|------|------|------|
| access_token | string | JWT access token |
| refresh_token | string | JWT refresh token |
| expires_in | int | Access token lifetime (seconds), default 7200 |
| user.id | string | hashid-encrypted user ID |
| user.username | string | Username |
| user.real_name | string | Real name |

**Possible errors**:
- 422: Parameter validation failed (required fields missing, wrong format)
- 422: Please complete captcha verification first (captcha_key has not passed `/api/captcha/verify`)
- 401: Incorrect username or password
- 403: Account is disabled
- 429: Account is locked, try again in 15 minutes (triggered by 5 consecutive login failures)

### 3.6 Register

```
POST /api/auth/register
```

- **Authentication**: none required
- **Request header**: `API-Version: v1` (required)
- **Rate limit**: 5/min (per IP + path)

**Request body**:
```json
{
  "username": "newuser",
  "password": "djGYscnyS5V6mW6KyDFjB8vGwjBBnB3Odpyxu8LY...",
  "real_name": "新用户",
  "captcha_key": "abc123def456"
}
```

| Field | Type | Required | Validation rule | Description |
|------|------|------|---------|------|
| username | string | Yes | min:3, max:50 | Username (unique) |
| password | string | Yes | min:6, max:32 (plaintext) | AES-256-CBC-HMAC encrypted then Base64 encoded |
| real_name | string | Yes | max:50 | Real name |
| captcha_key | string | Yes | | Captcha key (must be verified via `/api/captcha/verify` first) |

**Response example**:
```json
{
  "code": 0,
  "message": "注册成功",
  "data": {
    "access_token": "eyJhbGciOiJIUzI1NiIs...",
    "refresh_token": "eyJhbGciOiJIUzI1NiIs...",
    "expires_in": 7200,
    "user": {
      "id": "e5f6g7h8",
      "username": "newuser",
      "real_name": "新用户"
    }
  }
}
```

JWT tokens are returned directly after successful registration; the user status is enabled by default (status=1).

### 3.7 Refresh token

```
POST /api/auth/refresh
```

- **Authentication**: none required
- **Request header**: `API-Version: v1` (required)
- **Rate limit**: global default (60/min)

**Request body**:
```json
{
  "refresh_token": "eyJhbGciOiJIUzI1NiIs..."
}
```

| Field | Type | Required | Description |
|------|------|------|------|
| refresh_token | string | Yes | refresh_token obtained at login/registration |

**Response example**:
```json
{
  "code": 0,
  "message": "success",
  "data": {
    "access_token": "eyJhbGciOiJIUzI1NiIs...",
    "refresh_token": "eyJhbGciOiJIUzI1NiIs...",
    "expires_in": 7200
  }
}
```

A successful refresh returns both a new access_token and refresh_token; the old tokens are invalidated automatically. The user's last login time and IP are updated on refresh.

**Possible errors**:
- 422: Refresh token missing
- 401: Refresh token invalid or expired

### 3.8 Prometheus metrics

```
GET /metrics
```

- **Authentication**: none required
- **Rate limit**: none
- **Response format**: Prometheus text format (`text/plain; version=0.0.4`)

Public Prometheus metrics endpoint for scraping by Grafana/Prometheus.

**Response example**:
```
# HELP openadmin_http_requests_total Total number of HTTP requests.
# TYPE openadmin_http_requests_total gauge
openadmin_http_requests_total 15234

# HELP openadmin_active_users Number of active users.
# TYPE openadmin_active_users gauge
openadmin_active_users 156

# HELP openadmin_db_connection_status Database connection status (1=ok, 0=fail).
# TYPE openadmin_db_connection_status gauge
openadmin_db_connection_status 1

# HELP openadmin_redis_connection_status Redis connection status (1=ok, 0=fail).
# TYPE openadmin_redis_connection_status gauge
openadmin_redis_connection_status 1

# HELP openadmin_memory_usage_bytes Memory usage in bytes.
# TYPE openadmin_memory_usage_bytes gauge
openadmin_memory_usage_bytes 18874368
```

| Metric name | Type | Description |
|------|------|------|
| `openadmin_http_requests_total` | gauge | Cumulative total HTTP requests |
| `openadmin_active_users` | gauge | Current active users (logged in within 24 hours) |
| `openadmin_db_connection_status` | gauge | Database connection status, 1=ok, 0=error |
| `openadmin_redis_connection_status` | gauge | Redis connection status, 1=ok, 0=error |
| `openadmin_memory_usage_bytes` | gauge | Current memory usage of the PHP process (bytes) |

## 4. Dashboard

All admin endpoints are mounted under the `/admin` group and pass through three middlewares: `AdminAuth` (JWT auth), `AdminPermission` (RBAC permission check), and `OperationLog` (operation logging).

### 4.1 Dashboard data

```
GET /admin/dashboard
```

- **Authentication**: JWT + RBAC
- **Cache**: Redis 5 minutes

**Response example**:
```json
{
  "code": 0,
  "message": "success",
  "data": {
    "stats": [
      {
        "label": "用户总数",
        "value": "1280",
        "icon": "people",
        "color": "#1677FF",
        "trend": 12.5
      },
      {
        "label": "今日新增",
        "value": "23",
        "icon": "person_add",
        "color": "#52C41A"
      },
      {
        "label": "活跃用户",
        "value": "156",
        "icon": "bolt",
        "color": "#FA8C16"
      },
      {
        "label": "操作日志",
        "value": "432",
        "icon": "description",
        "color": "#722ED1"
      }
    ],
    "trends": {
      "dates": ["2026-04-22", "2026-04-23", "..."],
      "series": [
        { "name": "累计用户", "data": [1200, 1210, "..."], "color": "#1677FF" },
        { "name": "操作日志", "data": [45, 52, "..."], "color": "#52C41A" }
      ]
    },
    "distribution": {
      "user_status": [
        { "name": "启用", "value": 1250 },
        { "name": "禁用", "value": 30 }
      ]
    },
    "recent_logs": [
      {
        "id": "hashid...",
        "action": "用户登录",
        "method": "POST",
        "path": "/api/auth/login",
        "ip": "192.168.1.1",
        "user_name": "admin",
        "created_at": "2026-05-21 10:30:00"
      }
    ]
  }
}
```

| stats fields | Type | Description |
|------|------|------|
| label | string | Metric name |
| value | string | Metric value (string type) |
| icon | string | Material icon name |
| color | string | Card color value |
| trend | float? | Day-over-day growth rate (percentage); only "total users" has this field |

| trends fields | Type | Description |
|------|------|------|
| dates | array{string} | Date sequence of the last 30 days |
| series | array{object} | Trend line data, each entry contains name (name), data (value array), color (color) |

## 5. User management

The `id` returned by all user management endpoints is a hashid-encrypted string. Password fields are excluded from responses. Phone numbers and emails are masked in list endpoints and returned in plaintext in detail endpoints (encrypted database fields are decrypted automatically by the Encryptable trait).

### 5.1 User list

```
GET /admin/user
```

- **Authentication**: JWT + RBAC

**Query parameters**:

| Parameter | Type | Required | Default | Description |
|------|------|------|------|------|
| page | int | No | 1 | Page number |
| limit | int | No | 15 | Items per page |
| keyword | string | No | | Search keyword, matches username and real name |
| status | int | No | | Status filter, 0=disabled, 1=enabled |

**Response example**:
```json
{
  "code": 0,
  "message": "success",
  "data": {
    "list": [
      {
        "id": "a1b2c3d4",
        "username": "admin",
        "real_name": "管理员",
        "phone": "138****5678",
        "email": "a***@example.com",
        "status": 1,
        "last_login_at": "2026-05-21 10:30:00",
        "created_at": "2026-01-01 00:00:00"
      }
    ],
    "total": 100,
    "page": 1,
    "limit": 15
  }
}
```

| Field | Type | Description |
|------|------|------|
| id | string | hashid-encrypted user ID |
| username | string | Username |
| real_name | string | Real name |
| phone | string | Masked phone number (`138****5678` format) |
| email | string | Masked email (`a***@example.com` format) |
| status | int | 1=enabled, 0=disabled |
| last_login_at | string | Last login time (datetime) |
| created_at | string | Creation time (datetime) |

### 5.2 Create user

```
POST /admin/user
```

- **Authentication**: JWT + RBAC

**Request body**:
```json
{
  "username": "newuser",
  "password": "123456",
  "real_name": "新用户",
  "phone": "13800138000",
  "email": "newuser@example.com",
  "status": 1
}
```

| Field | Type | Required | Validation rule | Description |
|------|------|------|---------|------|
| username | string | Yes | min:3, max:50 | Username (unique) |
| password | string | Yes | min:6, max:32 | Password (stored with bcrypt) |
| real_name | string | Yes | max:50 | Real name |
| phone | string | No | | Phone number (encrypted with Encryptable) |
| email | string | No | | Email (encrypted with Encryptable) |
| status | int | No | in:0,1 | Status, default 1 (enabled) |

**Response example**:
```json
{
  "code": 0,
  "message": "创建成功",
  "data": {
    "id": "e5f6g7h8",
    "username": "newuser",
    "real_name": "新用户",
    "phone": "13800138000",
    "email": "newuser@example.com",
    "status": 1,
    "created_at": "2026-05-21 12:00:00"
  }
}
```

**Possible errors**:
- 422: Username already exists
- 422: Parameter validation failed (required fields missing)

### 5.3 User detail

```
GET /admin/user/{id}
```

- **Authentication**: JWT + RBAC
- **Path parameter**: `{id}` is the hashid-encrypted user ID

**Response example**:
```json
{
  "code": 0,
  "message": "success",
  "data": {
    "id": "a1b2c3d4",
    "username": "admin",
    "real_name": "管理员",
    "phone": "13800138000",
    "email": "admin@example.com",
    "status": 1,
    "last_login_at": "2026-05-21 10:30:00",
    "last_login_ip": "192.168.1.1",
    "created_at": "2026-01-01 00:00:00",
    "updated_at": "2026-05-20 08:00:00"
  }
}
```

In the detail endpoint, `phone` and `email` are returned in plaintext (stored encrypted in the database, decrypted automatically by the Encryptable cast) and are not masked. `password` and `id_card` are never included in the response.

**Possible errors**:
- 404: User not found

### 5.4 Update user

```
PUT /admin/user/{id}
```

- **Authentication**: JWT + RBAC
- **Path parameter**: `{id}` is the hashid-encrypted user ID

**Request body**:
```json
{
  "real_name": "新姓名",
  "password": "",
  "phone": "13900139000",
  "email": "new@example.com",
  "status": 1
}
```

| Field | Type | Required | Description |
|------|------|------|------|
| real_name | string | No | Real name; keep original value if not provided |
| password | string | No | New password; not changed if empty string or omitted |
| phone | string | No | Phone number |
| email | string | No | Email |
| status | int | No | 0=disabled, 1=enabled |

**Response example**:
```json
{
  "code": 0,
  "message": "更新成功",
  "data": {
    "id": "a1b2c3d4",
    "username": "admin",
    "real_name": "新姓名",
    "phone": "13900139000",
    "email": "new@example.com",
    "status": 1,
    "updated_at": "2026-05-21 12:30:00"
  }
}
```

**Possible errors**:
- 404: User not found

### 5.5 Delete user

```
DELETE /admin/user/{id}
```

- **Authentication**: JWT + RBAC
- **Path parameter**: `{id}` is the hashid-encrypted user ID
- **Sensitive operation**: requires password re-confirmation

**Request body**:
```json
{
  "password": "admin_password"
}
```

| Field | Type | Required | Description |
|------|------|------|------|
| password | string | Yes | Current logged-in user's password (re-confirmation) |

**Response example**:
```json
{
  "code": 0,
  "message": "删除成功",
  "data": []
}
```

Performs a soft delete (Eloquent SoftDeletes); data is marked with deleted_at and not physically deleted.

**Possible errors**:
- 404: User not found
- 422: Sensitive operations require password confirmation (password empty)
- 422: Password verification failed (password mismatch)

### 5.6 Batch delete users

```
POST /admin/user/batch/destroy
```

- **Authentication**: JWT + RBAC
- **Sensitive operation**: requires password re-confirmation

**Request body**:
```json
{
  "ids": ["a1b2c3d4", "e5f6g7h8"],
  "password": "admin_password"
}
```

| Field | Type | Required | Description |
|------|------|------|------|
| ids | array{string} | Yes | Array of hashid-encrypted user IDs |
| password | string | Yes | Current logged-in user's password (re-confirmation) |

**Response example**:
```json
{
  "code": 0,
  "message": "删除成功",
  "data": {
    "count": 2
  }
}
```

Performs a soft delete; `data.count` is the number actually deleted.

**Possible errors**:
- 422: Please select users to delete (ids empty)
- 422: Invalid ID (hashid decode failed)
- 422: Password verification failed

### 5.7 Batch enable/disable users

```
POST /admin/user/batch/status
```

- **Authentication**: JWT + RBAC

**Request body**:
```json
{
  "ids": ["a1b2c3d4", "e5f6g7h8"],
  "status": 1
}
```

| Field | Type | Required | Description |
|------|------|------|------|
| ids | array{string} | Yes | Array of hashid-encrypted user IDs |
| status | int | Yes | 0=disabled, 1=enabled |

**Response example**:
```json
{
  "code": 0,
  "message": "批量启用成功",
  "data": {
    "count": 2
  }
}
```

The message changes dynamically based on status: `"批量启用成功"` or `"批量禁用成功"`.

**Possible errors**:
- 422: Please select users (ids empty)
- 422: Invalid status value (status is not 0 or 1)

## 6. Role management

### 6.1 Role list

```
GET /admin/role
```

- **Authentication**: JWT + RBAC

**Query parameters**:

| Parameter | Type | Required | Default | Description |
|------|------|------|------|------|
| page | int | No | 1 | Page number |
| limit | int | No | 15 | Items per page |

**Response example**:
```json
{
  "code": 0,
  "message": "success",
  "data": {
    "list": [
      {
        "id": "r1r2r3r4",
        "name": "超级管理员",
        "slug": "super_admin",
        "description": "拥有所有权限",
        "status": 1,
        "users_count": 3,
        "created_at": "2026-01-01 00:00:00"
      }
    ],
    "total": 5,
    "page": 1,
    "limit": 15
  }
}
```

| Field | Type | Description |
|------|------|------|
| id | string | hashid-encrypted role ID |
| name | string | Role name |
| slug | string | Role identifier (unique, used for permission checks) |
| description | string | Role description |
| status | int | 1=enabled, 0=disabled |
| users_count | int | Number of users with this role |

### 6.2 Create role

```
POST /admin/role
```

- **Authentication**: JWT + RBAC

**Request body**:
```json
{
  "name": "编辑员",
  "slug": "editor",
  "description": "内容编辑角色",
  "status": 1,
  "permission_ids": [1, 5, 12, 15]
}
```

| Field | Type | Required | Validation rule | Description |
|------|------|------|---------|------|
| name | string | Yes | max:50 | Role name |
| slug | string | Yes | max:50 | Role identifier |
| description | string | No | | Role description, default empty string |
| status | int | No | | Status, default 1 |
| permission_ids | array{int} | No | | Array of permission IDs (raw INT IDs, not hashids) |

**Response example**:
```json
{
  "code": 0,
  "message": "创建成功",
  "data": {
    "id": "r5r6r7r8",
    "name": "编辑员",
    "slug": "editor",
    "description": "内容编辑角色",
    "status": 1
  }
}
```

### 6.3 Update role

```
PUT /admin/role/{id}
```

- **Authentication**: JWT + RBAC

**Request body**:
```json
{
  "name": "内容编辑",
  "description": "更新后的描述",
  "status": 1,
  "permission_ids": [1, 5, 12, 20]
}
```

| Field | Type | Required | Description |
|------|------|------|------|
| name | string | No | Role name |
| description | string | No | Description |
| status | int | No | 0=disabled, 1=enabled |
| permission_ids | array{int} | No | Array of permission IDs; when provided, role permissions are synced (overwritten) |

**Response example**:
```json
{
  "code": 0,
  "message": "更新成功",
  "data": {
    "id": "r5r6r7r8",
    "name": "内容编辑",
    "slug": "editor",
    "description": "更新后的描述",
    "status": 1
  }
}
```

### 6.4 Delete role

```
DELETE /admin/role/{id}
```

- **Authentication**: JWT + RBAC
- **Sensitive operation**: requires password re-confirmation

**Request body**:
```json
{
  "password": "admin_password"
}
```

**Response example**:
```json
{
  "code": 0,
  "message": "删除成功",
  "data": []
}
```

On deletion, the role's associations with all permissions and users are automatically removed, then the role record is physically deleted.

## 7. Permission management

Permissions use a tree structure (parent_id self-reference) and are divided into three types. The list endpoint returns the complete permission tree.

### 7.1 Permission tree

```
GET /admin/permission
```

- **Authentication**: JWT + RBAC

**Response example**:
```json
{
  "code": 0,
  "message": "success",
  "data": [
    {
      "id": "p1p2p3p4",
      "parent_id": "0",
      "name": "用户管理",
      "slug": "/admin/user",
      "type": 1,
      "icon": "people",
      "path": "/user",
      "sort": 1,
      "created_at": "2026-01-01 00:00:00",
      "children": [
        {
          "id": "p5p6p7p8",
          "parent_id": "p1p2p3p4",
          "name": "用户列表",
          "slug": "/admin/user/index",
          "type": 2,
          "icon": "",
          "path": "/user/index",
          "sort": 1
        }
      ]
    }
  ]
}
```

| Field | Type | Description |
|------|------|------|
| id | string | hashid-encrypted |
| parent_id | string | Parent permission hashid; "0" represents the root node |
| name | string | Permission name |
| slug | string | Permission identifier (route/button identifier) |
| type | int | 1=menu, 2=button, 3=API |
| icon | string | Menu icon (Material icon name) |
| path | string | Frontend route path |
| sort | int | Sort value (ascending) |
| children | array? | List of child permissions (recursive); omitted when there are no child nodes |

### 7.2 Create permission

```
POST /admin/permission
```

- **Authentication**: JWT + RBAC

**Request body**:
```json
{
  "parent_id": 0,
  "name": "系统设置",
  "slug": "/admin/config",
  "type": 1,
  "icon": "settings",
  "path": "/config",
  "sort": 99
}
```

| Field | Type | Required | Validation rule | Description |
|------|------|------|---------|------|
| parent_id | int | No | | Parent permission ID (raw INT type), default 0 |
| name | string | Yes | max:50 | Permission name |
| slug | string | Yes | max:100 | Permission identifier |
| type | int | Yes | in:1,2,3 | 1=menu, 2=button, 3=API |
| icon | string | No | | Menu icon, default empty |
| path | string | No | | Frontend route path, default empty |
| sort | int | No | | Sort value, default 0 |

**Response example**:
```json
{
  "code": 0,
  "message": "创建成功",
  "data": {
    "id": "p9p0a1b2",
    "parent_id": "0",
    "name": "系统设置",
    "slug": "/admin/config",
    "type": 1,
    "icon": "settings",
    "path": "/config",
    "sort": 99
  }
}
```

### 7.3 Update permission

```
PUT /admin/permission/{id}
```

- **Authentication**: JWT + RBAC

**Request body**:
```json
{
  "name": "系统配置",
  "icon": "tune",
  "path": "/system/config",
  "sort": 50
}
```

| Field | Type | Required | Description |
|------|------|------|------|
| name | string | No | Permission name |
| icon | string | No | Icon |
| path | string | No | Route path |
| sort | int | No | Sort value |

### 7.4 Delete permission

```
DELETE /admin/permission/{id}
```

- **Authentication**: JWT + RBAC
- **Sensitive operation**: requires password re-confirmation

**Request body**:
```json
{
  "password": "admin_password"
}
```

**Response example**:
```json
{
  "code": 0,
  "message": "删除成功",
  "data": []
}
```

On deletion, all child permissions are cascade-deleted (records whose `parent_id` equals the current permission ID), and associations with all roles are removed.

## 8. System config

System configs are unique by the combination of `group` + `key`.

### 8.1 Config list

```
GET /admin/config
```

- **Authentication**: JWT + RBAC

**Query parameters**:

| Parameter | Type | Required | Default | Description |
|------|------|------|------|------|
| page | int | No | 1 | Page number |
| limit | int | No | 15 | Items per page |
| group | string | No | | Filter by config group |

**Response example**:
```json
{
  "code": 0,
  "message": "success",
  "data": {
    "list": [
      {
        "id": "c1c2c3c4",
        "group": "system",
        "key": "site_name",
        "value": "开放管理后台",
        "type": "string",
        "description": "站点名称",
        "created_at": "2026-01-01 00:00:00"
      }
    ],
    "total": 20,
    "page": 1,
    "limit": 15
  }
}
```

| Field | Type | Description |
|------|------|------|
| id | string | hashid |
| group | string | Config group (e.g. `system`, `email`, `storage`) |
| key | string | Config key |
| value | string | Config value |
| type | string | Value type hint (`string`, `integer`, `boolean`, `json`, etc.) |
| description | string | Config description |

### 8.2 Create config

```
POST /admin/config
```

- **Authentication**: JWT + RBAC

**Request body**:
```json
{
  "group": "email",
  "key": "smtp_host",
  "value": "smtp.example.com",
  "type": "string",
  "description": "SMTP 服务器地址"
}
```

| Field | Type | Required | Validation rule | Description |
|------|------|------|---------|------|
| group | string | Yes | max:100 | Config group |
| key | string | Yes | max:100 | Config key (unique within the same group) |
| value | string | Yes | | Config value |
| type | string | No | | Value type, default `string` |
| description | string | No | | Config description, default empty |

**Response example**:
```json
{
  "code": 0,
  "message": "创建成功",
  "data": {
    "id": "c5c6c7c8",
    "group": "email",
    "key": "smtp_host",
    "value": "smtp.example.com",
    "type": "string",
    "description": "SMTP 服务器地址"
  }
}
```

**Possible errors**:
- 422: Config item already exists (same group + key)

### 8.3 Update config

```
PUT /admin/config/{id}
```

- **Authentication**: JWT + RBAC

**Request body**:
```json
{
  "value": "smtp.newhost.com",
  "type": "string",
  "description": "更新后的 SMTP 地址"
}
```

| Field | Type | Required | Description |
|------|------|------|------|
| value | string | No | Update the config value |
| type | string | No | Update the value type |
| description | string | No | Update the description text |

### 8.4 Delete config

```
DELETE /admin/config/{id}
```

- **Authentication**: JWT + RBAC
- **Sensitive operation**: requires password re-confirmation

**Request body**:
```json
{
  "password": "admin_password"
}
```

Physically deletes the config record.

## 9. Operation logs

Operation logs are read-only; the `OperationLog` middleware writes automatically on every POST/PUT/DELETE request. Stored fields include `user_id`, `action`, `method`, `path`, `ip`, `source`, `input`.

### 9.1 Operation log list

```
GET /admin/log
```

- **Authentication**: JWT + RBAC

**Query parameters**:

| Parameter | Type | Required | Default | Description |
|------|------|------|------|------|
| page | int | No | 1 | Page number |
| limit | int | No | 15 | Items per page |
| user_id | int | No | | Exact filter by user ID (raw INT type) |
| action | string | No | | Exact filter by operation action |
| path | string | No | | Fuzzy filter by request path |
| start_date | string | No | | Start date (Y-m-d format) |
| end_date | string | No | | End date (Y-m-d format) |

**Response example**:
```json
{
  "code": 0,
  "message": "success",
  "data": {
    "list": [
      {
        "id": "l1l2l3l4",
        "user_name": "admin",
        "action": "用户登录",
        "method": "POST",
        "path": "/api/auth/login",
        "ip": "192.168.1.1",
        "source": "web",
        "input": "{\"username\":\"admin\"}",
        "created_at": "2026-05-21 10:30:00"
      }
    ],
    "total": 500,
    "page": 1,
    "limit": 15
  }
}
```

| Field | Type | Description |
|------|------|------|
| id | string | hashid |
| user_name | string | Operating username (via the user relation; unauthenticated operations show "系统") |
| action | string | Operation action description |
| method | string | HTTP method (POST/PUT/DELETE) |
| path | string | Request path |
| ip | string | Client IP |
| source | string | Request source |
| input | string | Request parameters as JSON string (excluding files) |
| created_at | string | Operation time (datetime) |

## 10. Profile

Profile endpoints require JWT authentication only (no RBAC permission check — the `AdminPermission` middleware should whitelist them).

### 10.1 Update profile

```
PUT /admin/profile
```

- **Authentication**: JWT

**Request body**:
```json
{
  "real_name": "新姓名",
  "phone": "13800138000",
  "email": "me@example.com"
}
```

| Field | Type | Required | Description |
|------|------|------|------|
| real_name | string | No | Real name |
| phone | string | No | Phone number (encrypted with Encryptable) |
| email | string | No | Email (encrypted with Encryptable) |

**Response example**:
```json
{
  "code": 0,
  "message": "更新成功",
  "data": {
    "id": "a1b2c3d4",
    "username": "admin",
    "real_name": "新姓名",
    "phone": "13800138000",
    "email": "me@example.com",
    "status": 1,
    "last_login_at": "2026-05-21 10:30:00"
  }
}
```

In the response, `phone` and `email` are returned in plaintext; `password` and `id_card` are removed.

### 10.2 Change password

```
PUT /admin/profile/password
```

- **Authentication**: JWT

**Request body**:
```json
{
  "old_password": "current_pass",
  "new_password": "new_pass_123"
}
```

| Field | Type | Required | Validation rule | Description |
|------|------|------|---------|------|
| old_password | string | Yes | | Current password |
| new_password | string | Yes | min:6, max:32 | New password |

**Response example**:
```json
{
  "code": 0,
  "message": "密码修改成功",
  "data": []
}
```

**Possible errors**:
- 422: Please provide both old and new passwords
- 422: Old password is incorrect
- 422: New password must be 6-32 characters

### 10.3 Logout

```
POST /admin/profile/logout
```

- **Authentication**: JWT

**Request body**: none (no requestBody; the token is read from the Authorization header)

**Response example**:
```json
{
  "code": 0,
  "message": "已登出",
  "data": []
}
```

Logout logic: decode the JWT to get the remaining validity (exp - now), write the md5 hash of the token to the Redis blacklist `jwt_blacklist:{md5}` with TTL = remaining validity. Blacklisted tokens are blocked by the `AdminAuth` middleware, returning 401.

Returns 401 when no token is present. Expired/invalid tokens (decode throws) are still treated as a successful logout.

## 11. Import & export

### 11.1 Export Excel

```
POST /admin/export/excel
```

- **Authentication**: JWT + RBAC
- **Response type**: file download (`application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`)

**Request body**:
```json
{
  "table": "admin_user",
  "columns": ["username", "real_name", "phone", "status", "created_at"],
  "conditions": {
    "status": 1
  },
  "title": "用户列表导出"
}
```

| Field | Type | Required | Default | Description |
|------|------|------|------|------|
| table | string | No | `admin_user` | Table name to export. Supported: `admin_user`, `operation_log`, `admin_role`, `system_config` |
| columns | array{string} | No | | Array of column field names to export; empty exports all columns of the table |
| conditions | object | No | `{}` | Filter conditions, key-value pairs; non-empty values are used in WHERE |
| title | string | No | `数据导出` | Excel title (shown as the Sheet name) |

**Supported tables and columns**:

| table | Available columns |
|-------|-------|
| `admin_user` | id, username, real_name, phone, email, status, last_login_at, last_login_ip, created_at |
| `operation_log` | id, user_id, action, method, path, ip, created_at |
| `admin_role` | id, name, slug, description, status, created_at |
| `system_config` | id, group, key, value, type, description, created_at |

Sensitive fields `phone`, `email`, `id_card` are masked automatically on export. Data is capped at 10000 rows. The first Excel row is frozen and auto-filter is enabled.

### 11.2 Export PDF

```
POST /admin/export/pdf
```

- **Authentication**: JWT + RBAC
- **Response type**: file download (`application/pdf`, A4 landscape)

**Request body**:
```json
{
  "type": "dashboard",
  "title": "管理仪表盘报告",
  "data": {
    "stats": [
      { "label": "用户总数", "value": "1280" }
    ]
  }
}
```

Or table mode:
```json
{
  "type": "table",
  "title": "用户列表",
  "data": {
    "columns": ["用户名", "真实姓名", "状态"],
    "rows": [
      ["admin", "管理员", "启用"],
      ["editor", "编辑员", "启用"]
    ]
  }
}
```

| Field | Type | Required | Default | Description |
|------|------|------|------|------|
| type | string | No | `table` | Export type: `table` / `dashboard` |
| title | string | No | `数据导出` | PDF title |
| data | object | No | `{}` | Export data |

With `type=dashboard`, `data` must contain a `stats` array (rendered as cards); with `type=table`, `data` must contain `columns` and `rows` arrays.

The PDF template includes copyright information and an export timestamp.

### 11.3 Import users (Excel)

```
POST /admin/import/users
```

- **Authentication**: JWT + RBAC
- **Request type**: `multipart/form-data` (file upload)

**Form fields**:

| Field | Type | Required | Description |
|------|------|------|------|
| file | file | Yes | `.xlsx` or `.xls` format |

**Excel column requirements**:

| Column name | Required | Description |
|------|------|------|
| username | Yes | Username (unique) |
| password | Yes | Password (stored as bcrypt hash) |
| real_name | Yes | Real name |
| phone | No | Phone number |
| email | No | Email |
| status | No | Status, default 1 |

Row 1 is the column header (case-insensitive); data starts from row 2.

**Response example**:
```json
{
  "code": 0,
  "message": "导入完成",
  "data": {
    "total": 10,
    "success": 8,
    "failed": 2,
    "errors": [
      { "row": 3, "reason": "用户名为空" },
      { "row": 7, "reason": "用户名 zhangsan 已存在" }
    ]
  }
}
```

| Field | Type | Description |
|------|------|------|
| total | int | Total rows (excluding the header row) |
| success | int | Number successfully imported |
| failed | int | Number failed |
| errors | array | Failure details, each entry contains row (Excel row number) and reason (failure reason) |

## 12. File upload

```
POST /admin/upload
```

- **Authentication**: JWT + RBAC
- **Request type**: `multipart/form-data`

**Form fields**:

| Field | Type | Required | Description |
|------|------|------|------|
| file | file | Yes | The file to upload |

**Allowed file types**: `jpg`, `jpeg`, `png`, `gif`, `pdf`, `xlsx`, `docx`
**Max file size**: 10MB

**Response example**:
```json
{
  "code": 0,
  "message": "上传成功",
  "data": {
    "url": "/upload/2026-05-21/abc123def456.png"
  }
}
```

Files are stored in date-based directories `public/upload/{Y-m-d}/` with filenames of `md5(uniqid) + original extension`. `url` is a relative path from the site root.

**Possible errors**:
- 422: Please select a file (none uploaded)
- 422: Unsupported file type
- 422: File size must not exceed 10MB
- 500: File upload failed (invalid file)

## 13. Response headers

All endpoints (injected at the global middleware layer) include the following response headers:

| Header | Description |
|----|------|
| `X-RateLimit-Limit` | Rate limit cap (count) |
| `X-RateLimit-Remaining` | Remaining request count |
| `X-RateLimit-Reset` | Rate limit window reset timestamp |
| `Retry-After` | Only returned when rate limited; suggested wait seconds |
| `X-Content-Type-Options` | `nosniff` (webman default, disables MIME sniffing) |
| `X-Frame-Options` | `DENY` (provided by webman's CORS middleware/base config) |

Rate limit details:
- Default global limit: 60/min / IP+path
- Login endpoint `/api/auth/login`: 10/min
- Register endpoint `/api/auth/register`: 5/min
- Uses Redis atomic sliding window algorithm (Lua ZSET), avoiding TOCTOU races
- Fails open when Redis is unavailable (requests pass through, not blocked)

## 14. Authentication flow

The complete authentication sequence:

```
1. Client requests POST /api/captcha/generate
   (Request header: API-Version: v1)
    ↓
   Server returns: key + type(click|slider|rotate) + base64 image + extra(type-specific data)
   
2. The user completes the captcha interaction (click/drag/rotate), and the client collects the answer
   
3. Client requests POST /api/captcha/verify
   (Request header: API-Version: v1, Content-Type: application/json)
   Request body: { key, type, clicks }
   - type=click:  clicks = [{x, y}, ...]        // coordinate array
   - type=slider: clicks = 120                   // X offset
   - type=rotate: clicks = 315                   // rotation angle
    ↓
   Server:
   a. Read the captcha:key data from storage (TTL 300s)
   b. Validate the answer by type (click: Euclidean distance ≤18px / slider: ±4px / rotate: ±5°)
   c. Valid → write Redis `captcha_verified:{key}` = 1 (TTL 300s)
   d. Invalid → return 422, counter +1, key invalidated after 3 attempts
    ↓
   Server returns: { valid: true/false }

4. Client requests POST /api/auth/login
   (Request header: API-Version: v1, Content-Type: application/json)
   Request body: { username, password(encrypted), captcha_key }
    ↓
   Server:
   a. Parameter validation → 422
   b. Check whether captcha_verified:{key} exists → 422
   c. Delete captcha_verified:{key} (single use)
   d. Decrypt password: EncryptionService::decrypt(password) → plaintext
   e. Validate user credentials (password_verify) → 401
   f. Check account status → 403/429
   g. Issue JWT (access + refresh) → 200
   h. Update last_login_at / last_login_ip
    ↓
   Client stores: access_token, refresh_token, expires_in

5. Subsequent requests carry the JWT
   Request header: Authorization: Bearer <access_token>
    ↓
   AdminAuth middleware:
   a. Extract the Bearer token
   b. Check the blacklist (Redis jwt_blacklist:{md5}) → 401
   c. Decode the JWT, validate expiry → 401
   d. Set $request->adminId = sub field
    ↓
   AdminPermission middleware:
   a. Resolve the permission identifier for the resource route
   b. Query user roles → role permissions and match
   c. No permission → 403
    ↓
   Controller handles the request
    ↓
   Response + X-RateLimit-* headers

6. Refresh before the access token expires
   Client requests POST /api/auth/refresh
   Request body: { refresh_token: "..." }
    ↓
   Server decodes refresh_token → issues new access + refresh
    ↓
   Client updates its local tokens

7. Logout
   Client requests POST /admin/profile/logout
   Request header: Authorization: Bearer <access_token>
    ↓
   Server:
   a. Decode the JWT to get the remaining TTL
   b. Write to the Redis blacklist: jwt_blacklist:{md5(token)} = 1, TTL = remaining validity
   c. Return success
```

### JWT structure

- **access_token**: `{ sub: <user_id>, username: "<name>" }`, default TTL 7200 seconds (controlled by the JWT config `default_expire`)
- **refresh_token**: `{ sub: <user_id>, token_type: "refresh" }`, default TTL 1209600 seconds (controlled by the JWT config `refresh_expire`, i.e. 14 days)

### Security management

- Passwords are stored as `PASSWORD_BCRYPT` hashes
- Passwords are encrypted in transit with AES-256-CBC-HMAC (client encrypts → server decrypts), with plaintext fallback for compatibility
- Sensitive fields (phone, email, id_card) are transparently encrypted/decrypted at the database layer with `erikwang2013/encryptable`
- API-layer IDs are transmitted encrypted with `erikwang2013/hashids`, avoiding exposure of the raw snowflake ID sequence
- SecurityFilter globally scans for XSS, SQL injection, path traversal, and command injection; same IP 5 hits/60s → temporary 15-minute blacklist
- Sensitive operations (deleting users, roles, permissions, configs) require password re-confirmation of the current logged-in user
- Concurrent session limit: a user can hold at most 3 valid tokens; when a 4th device logs in, the oldest token is forcibly blacklisted
- Account lockout: 5 consecutive login failures trigger a 15-minute lockout, during which 429 is returned

## 15. Deployment & operations

### Docker Compose

The project root provides `docker-compose.yml` orchestrating 5 services (Nginx, webman app, MySQL, Redis, Elasticsearch). PHP is built via `Dockerfile` (based on `php:8.3-cli`, with OPcache enabled).

```bash
cp .env.docker .env
docker-compose up -d
```

### CI/CD

`.github/workflows/ci.yml` defines the GitHub Actions CI pipeline:
- `php -l` syntax check
- PHPUnit unit tests
- `flutter analyze` static analysis

### Database backup

The `database/backup/` directory provides backup and restore scripts:
- `backup.sh` — mysqldump + gzip compressed backups, automatically cleans backups older than 30 days
- `restore.sh` — interactive restore, lists existing backups for selection

### Nginx security config

For production deployment, refer to `docs/nginx-security.conf` for reverse proxy security hardening.
