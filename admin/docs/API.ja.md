# API リファレンス
**语言 / Languages:** [中文](API.md) · [English](API.en.md) · [한국어](API.ko.md) · [Русский](API.ru.md) · [Deutsch](API.de.md) · [Français](API.fr.md) · [Español](API.es.md) · [Português](API.pt.md) · [हिन्दी](API.hi.md) · [العربية](API.ar.md) · [বাংলা](API.bn.md) · [Bahasa Indonesia](API.id.md) · [日本語](API.ja.md)

> Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz

## 1. 概要

開放管理後台 (open-admin) は webman v2 ベースで構築され、RESTful JSON API を提供します。すべての管理側インターフェースは JWT 認証と RBAC 権限チェックを必要とし、公開インターフェースは API バージョンヘッダーによってバージョン管理されたコントローラーにルーティングされます。

- **ベース URL**: `http://localhost:8787`
- **API バージョン**: リクエストヘッダー `API-Version: v1` で制御（省略時は v1）
- **言語**: `Accept-Language` ヘッダーまたは `?lang=zh_CN|en` パラメータで切り替え（デフォルトは zh_CN）、Locale ミドルウェアが自動検出

> **エンドポイント一覧**: 認証(5) | ダッシュボード(1) | ユーザー(7) | ロール(4) | 権限(4) | 設定(4) | ログ(1) | プロフィール(3) | インポート/エクスポート(3) | アップロード(1) | 運用(4: health/metrics/docs/security.txt) | 合計 37 エンドポイント
- **認証**: `Authorization: Bearer <token>`（JWT）
- **レスポンス形式**: `{ "code": 0, "message": "success", "data": {...} }`
- **ドキュメントエンドポイント**: `GET /api/docs` は OpenAPI 3.0 JSON 仕様を返します

### リクエスト要件

- `GET` / `POST` / `PUT` / `DELETE` / `OPTIONS` / `HEAD` メソッドのみ許可。その他の HTTP メソッド（TRACE、CONNECT、PATCH など）は 405 を返します
- すべての `POST` / `PUT` リクエストは `Content-Type: application/json` を設定する必要があります（ファイルアップロードを除く）。違反時は 415 を返します
- リクエストボディは 10MB を超えてはなりません。超えた場合は 413 を返します
- セキュリティフィルターはすべてのリクエスト入力を XSS、SQL インジェクション、パストラバーサル、コマンドインジェクションのスキャン対象とし、検出時は 403 を返します
- ログイン失敗が 5 回連続するとアカウントがロックされ（15 分）、ロック中はログインリクエストが 429 を返します
- 同一ユーザーが同時に保持できる有効なトークンは最大 3 つで、超過時は最も古いトークンが自動的にブラックリストに追加されます

## 2. エラーコード

| code | 意味 | 発生シーン |
|------|------|---------|
| 0 | 成功 | |
| 400 | リクエストパラメータエラー | リクエスト形式が正しくない |
| 401 | 未認証 | トークン欠落 / 期限切れ / ブラックリスト登録済み |
| 403 | 権限なし / セキュリティ遮断 | RBAC 権限不足 / SecurityFilter 検出 |
| 404 | リソースが存在しない | クエリ/更新/削除の対象が存在しない |
| 405 | 許可されないリクエストメソッド | GET/POST/PUT/DELETE/OPTIONS/HEAD のみ許可し、非標準メソッドは直接拒否 |
| 413 | リクエストボディが大きすぎる | Content-Length が 10MB を超えている |
| 415 | サポートされないメディアタイプ | POST/PUT リクエストの Content-Type が JSON でもファイルアップロードでもない |
| 422 | パラメータ検証失敗 | 必須フィールド欠落、形式不一致、ビジネス検証エラー |
| 429 | リクエスト過多 | RateLimit 発動 / アカウントロック（ログイン5回連続失敗で15分ロック） |
| 500 | サーバー内部エラー | |

## 3. 公開エンドポイント

すべての公開エンドポイントは `/api` グループにマウントされ、`ApiVersion` ミドルウェアが `API-Version` ヘッダーに基づいて対応するバージョン管理コントローラー（例: `app\api\v1\controller\AuthController`）に振り分けます。

### 3.1 ヘルスチェック

```
GET /health
```

- **認証**: 不要
- **レート制限**: なし

**レスポンス例**:
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

`database`、`redis`、`elasticsearch` の値: `"ok"` | `"unavailable"`。`elasticsearch` は ES に到達できない場合 `"unavailable"` を返し、クラスターのヘルス状態が green/yellow でない場合は実際の status 値（例: `"red"`）を返します。

### 3.2 API ドキュメント

```
GET /api/docs
```

- **認証**: 不要
- **レート制限**: グローバルデフォルト（60回/分）
- **レスポンス**: OpenAPI 3.0.3 JSON 仕様。すべてのエンドポイント定義、パラメータ、Schema を含む

### 3.3 キャプチャ生成

```
POST /api/captcha/generate
```

- **認証**: 不要
- **リクエストヘッダー**: `API-Version: v1`（必須）
- **レート制限**: グローバルデフォルト（60回/分）

**リクエストボディ**:
```json
{
  "difficulty": "medium"
}
```

| フィールド | 型 | 必須 | 説明 |
|------|------|------|------|
| difficulty | string | いいえ | `easy` / `medium` / `hard`、デフォルトは `medium` |

**レスポンス例** — クリック型 (`type: "click"`):
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

**レスポンス例** — スライダー型 (`type: "slider"`):
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

**レスポンス例** — 回転型 (`type: "rotate"`):
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

| フィールド | 型 | 説明 |
|------|------|------|
| key | string | キャプチャ識別子、検証時に返送 |
| type | string | キャプチャタイプ: `click` / `slider` / `rotate` |
| image | string | base64 data URI 画像 |
| extra | object | タイプ別の追加データ（下記参照） |

**`extra` のタイプ別説明**:

| type | extra フィールド | 型 | 説明 |
|------|-----------|------|------|
| click | targets | array | クリック対象。`order`(順序) `text`(ヒント文字) `x` `y`(座標) を含む |
| slider | x, y | int | 欠けている部分の左上隅座標（300×200 キャンバス基準） |
| slider | puzzle_w, puzzle_h | int | パズル画像の幅と高さ |
| slider | puzzle | string | パズル画像の base64 data URI |
| rotate | angle | int | 正しい回転角度 (0-359)。画像を正すには `360-angle` 回転させる必要があります |

### 3.4 キャプチャ検証

```
POST /api/captcha/verify
```

- **認証**: 不要
- **リクエストヘッダー**: `API-Version: v1`（必須）
- **レート制限**: グローバルデフォルト（60回/分）

**リクエストボディ** — クリック型 (`type: "click"`):
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

**リクエストボディ** — スライダー型 (`type: "slider"`):
```json
{
  "key": "def456abc789",
  "type": "slider",
  "clicks": 120
}
```

**リクエストボディ** — 回転型 (`type: "rotate"`):
```json
{
  "key": "ghi789abc012",
  "type": "rotate",
  "clicks": 315
}
```

| フィールド | 型 | 必須 | 説明 |
|------|------|------|------|
| key | string | はい | キャプチャ key、generate が返したもの |
| type | string | はい | キャプチャタイプ、generate が返した `type` と一致させる必要があります |
| clicks | 変体 | はい | 回答データ、形式は type によって変わります（下記参照） |

**`clicks` のタイプ別説明**:

| type | clicks タイプ | 説明 | 誤差許容 |
|------|------------|------|---------|
| click | `[{x:int, y:int}]` | クリック座標配列、order 順 | 半径 18px |
| slider | `int` | スライダー X 軸オフセット | ±4px |
| rotate | `int` | 回転角度 (0-359) | ±5° |

**レスポンス例**:
```json
{
  "code": 0,
  "message": "验证通过",
  "data": { "valid": true }
}
```

検証が成功すると、バックエンドは `captcha_verified:{key}` を Redis に書き込み（TTL 300秒）、ログインAPIがこれに基づいて通過を許可します。
検証失敗時は `code` が 422、`message` が `"验证失败，请重试"`、`data.valid` が `false` になります。

### 3.5 ログイン

```
POST /api/auth/login
```

- **認証**: 不要
- **リクエストヘッダー**: `API-Version: v1`（必須）
- **レート制限**: 10回/分（IP + パスごと）

**リクエストボディ**:
```json
{
  "username": "admin",
  "password": "djGYscnyS5V6mW6KyDFjB8vGwjBBnB3Odpyxu8LY...",
  "captcha_key": "abc123def456"
}
```

| フィールド | 型 | 必須 | 検証ルール | 説明 |
|------|------|------|---------|------|
| username | string | はい | min:3, max:50 | ユーザー名 |
| password | string | はい | min:6, max:32 (明文) | AES-256-CBC-HMAC 暗号化後 Base64 エンコード（平文も互換） |
| captcha_key | string | はい | | キャプチャ key（先に `/api/captcha/verify` で検証する必要があります） |

### パスワード暗号化プロトコル

**RSA-2048 非対称暗号化** を使用します。公開鍵はフロントエンドコードに配置（公開しても安全）、秘密鍵はサーバーのみが保持します。

```
暗号化フロー（クライアント）:
  RSA 公開鍵 (PEM) → PKCS1v1.5 暗号化 → Base64 エンコード → 送信

復号フロー（サーバー、段階的フォールバック）:
  1. RSA 秘密鍵で復号 → 成功かつ正しい UTF-8 → 復号結果を使用
  2. AES-256-CBC-HMAC 復号 → 成功 → 復号結果を使用（旧クライアント互換）
  3. 平文フォールバック → 元の入力をそのまま使用
```

公開鍵はフロントエンドアプリに組み込まれており、ネットワーク経由の転送は不要です。秘密鍵は `.env` の `RSA_PRIVATE_KEY` にのみ保存され、漏洩してはなりません。

> AES 対称暗号化は旧バージョン互換のための方式で、全クライアントが RSA に移行した後に削除されます。

**レスポンス例**:
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

| フィールド | 型 | 説明 |
|------|------|------|
| access_token | string | JWT アクセストークン |
| refresh_token | string | JWT リフレッシュトークン |
| expires_in | int | アクセストークンの有効期間（秒）、デフォルト 7200 |
| user.id | string | hashid で暗号化されたユーザー ID |
| user.username | string | ユーザー名 |
| user.real_name | string | 氏名 |

**考えられるエラー**:
- 422: パラメータ検証失敗（必須フィールド欠落、形式不一致）
- 422: 先にキャプチャ検証を完了してください（captcha_key が `/api/captcha/verify` を通過していない）
- 401: ユーザー名またはパスワードが正しくありません
- 403: アカウントが無効化されています
- 429: アカウントがロックされています。15分後にお試しください（ログイン5回連続失敗で発動）

### 3.6 登録

```
POST /api/auth/register
```

- **認証**: 不要
- **リクエストヘッダー**: `API-Version: v1`（必須）
- **レート制限**: 5回/分（IP + パスごと）

**リクエストボディ**:
```json
{
  "username": "newuser",
  "password": "djGYscnyS5V6mW6KyDFjB8vGwjBBnB3Odpyxu8LY...",
  "real_name": "新用户",
  "captcha_key": "abc123def456"
}
```

| フィールド | 型 | 必須 | 検証ルール | 説明 |
|------|------|------|---------|------|
| username | string | はい | min:3, max:50 | ユーザー名（一意） |
| password | string | はい | min:6, max:32 (明文) | AES-256-CBC-HMAC 暗号化後 Base64 エンコード |
| real_name | string | はい | max:50 | 氏名 |
| captcha_key | string | はい | | キャプチャ key（先に `/api/captcha/verify` で検証する必要があります） |

**レスポンス例**:
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

登録成功後は直接 JWT トークンを返します。ユーザー状態はデフォルトで有効（status=1）です。

### 3.7 トークンリフレッシュ

```
POST /api/auth/refresh
```

- **認証**: 不要
- **リクエストヘッダー**: `API-Version: v1`（必須）
- **レート制限**: グローバルデフォルト（60回/分）

**リクエストボディ**:
```json
{
  "refresh_token": "eyJhbGciOiJIUzI1NiIs..."
}
```

| フィールド | 型 | 必須 | 説明 |
|------|------|------|------|
| refresh_token | string | はい | ログイン/登録時に取得した refresh_token |

**レスポンス例**:
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

リフレッシュ成功時は新しい access_token と refresh_token を同時に返し、旧トークンは自動的に無効になります。リフレッシュ時にユーザーの最終ログイン時間と IP を更新します。

**考えられるエラー**:
- 422: リフレッシュトークンがありません
- 401: リフレッシュトークンが無効または期限切れ

### 3.8 Prometheus 監視メトリクス

```
GET /metrics
```

- **認証**: 不要
- **レート制限**: なし
- **レスポンス形式**: Prometheus text format (`text/plain; version=0.0.4`)

Prometheus 監視メトリクスを公開するエンドポイントです。Grafana/Prometheus によるスクレイピング用です。

**レスポンス例**:
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

| メトリクス名 | 型 | 説明 |
|------|------|------|
| `openadmin_http_requests_total` | gauge | 累計 HTTP リクエスト数 |
| `openadmin_active_users` | gauge | 現在のアクティブユーザー数（24時間以内にログイン） |
| `openadmin_db_connection_status` | gauge | データベース接続状態、1=正常、0=異常 |
| `openadmin_redis_connection_status` | gauge | Redis 接続状態、1=正常、0=異常 |
| `openadmin_memory_usage_bytes` | gauge | PHP プロセスの現在のメモリ使用量（バイト） |

## 4. ダッシュボード

すべての管理側インターフェースは `/admin` グループにマウントされ、`AdminAuth`（JWT 認証）、`AdminPermission`（RBAC 権限チェック）、`OperationLog`（操作記録）の3つのミドルウェアを通過します。

### 4.1 ダッシュボードデータ

```
GET /admin/dashboard
```

- **認証**: JWT + RBAC
- **キャッシュ**: Redis 5分

**レスポンス例**:
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

| stats フィールド | 型 | 説明 |
|------|------|------|
| label | string | メトリクス名 |
| value | string | メトリクス値（文字列型） |
| icon | string | Material アイコン名 |
| color | string | カードの色値 |
| trend | float? | 日次増減率（パーセント）、"ユーザー総数"のみこのフィールドを持ちます |

| trends フィールド | 型 | 説明 |
|------|------|------|
| dates | array{string} | 直近30日間の日付シーケンス |
| series | array{object} | トレンドラインデータ。各項目は name（名前）、data（数値配列）、color（色）を含む |

## 5. ユーザー管理

すべてのユーザー管理APIが返す `id` は hashid で暗号化された文字列です。パスワードフィールドはレスポンスから除外されています。携帯番号とメールは一覧APIではマスク表示され、詳細APIでは平文で返されます（データベースの暗号化フィールドは Encryptable trait が自動的に復号します）。

### 5.1 ユーザー一覧

```
GET /admin/user
```

- **認証**: JWT + RBAC

**クエリパラメータ**:

| パラメータ | 型 | 必須 | デフォルト値 | 説明 |
|------|------|------|------|------|
| page | int | いいえ | 1 | ページ番号 |
| limit | int | いいえ | 15 | 1ページあたりの件数 |
| keyword | string | いいえ | | 検索キーワード。ユーザー名と氏名にマッチ |
| status | int | いいえ | | 状態フィルター、0=無効、1=有効 |

**レスポンス例**:
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

| フィールド | 型 | 説明 |
|------|------|------|
| id | string | hashid で暗号化されたユーザー ID |
| username | string | ユーザー名 |
| real_name | string | 氏名 |
| phone | string | マスクされた携帯番号（`138****5678` 形式） |
| email | string | マスクされたメール（`a***@example.com` 形式） |
| status | int | 1=有効、0=無効 |
| last_login_at | string | 最終ログイン時間 (datetime) |
| created_at | string | 作成時間 (datetime) |

### 5.2 ユーザー作成

```
POST /admin/user
```

- **認証**: JWT + RBAC

**リクエストボディ**:
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

| フィールド | 型 | 必須 | 検証ルール | 説明 |
|------|------|------|---------|------|
| username | string | はい | min:3, max:50 | ユーザー名（一意） |
| password | string | はい | min:6, max:32 | パスワード（bcrypt で保存） |
| real_name | string | はい | max:50 | 氏名 |
| phone | string | いいえ | | 携帯番号（Encryptable で暗号化保存） |
| email | string | いいえ | | メール（Encryptable で暗号化保存） |
| status | int | いいえ | in:0,1 | 状態、デフォルト 1（有効） |

**レスポンス例**:
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

**考えられるエラー**:
- 422: ユーザー名は既に存在します
- 422: パラメータ検証失敗（必須フィールド欠落）

### 5.3 ユーザー詳細

```
GET /admin/user/{id}
```

- **認証**: JWT + RBAC
- **パスパラメータ**: `{id}` は hashid で暗号化されたユーザー ID

**レスポンス例**:
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

詳細APIでは `phone` と `email` を平文で返します（データベースでは暗号化保存され、Encryptable cast が自動復号）、マスクはしません。`password` と `id_card` は常にレスポンスに含まれません。

**考えられるエラー**:
- 404: ユーザーが存在しません

### 5.4 ユーザー更新

```
PUT /admin/user/{id}
```

- **認証**: JWT + RBAC
- **パスパラメータ**: `{id}` は hashid で暗号化されたユーザー ID

**リクエストボディ**:
```json
{
  "real_name": "新姓名",
  "password": "",
  "phone": "13900139000",
  "email": "new@example.com",
  "status": 1
}
```

| フィールド | 型 | 必須 | 説明 |
|------|------|------|------|
| real_name | string | いいえ | 氏名。未指定時は元の値を保持 |
| password | string | いいえ | 新しいパスワード。空文字または未指定なら変更しない |
| phone | string | いいえ | 携帯番号 |
| email | string | いいえ | メール |
| status | int | いいえ | 0=無効、1=有効 |

**レスポンス例**:
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

**考えられるエラー**:
- 404: ユーザーが存在しません

### 5.5 ユーザー削除

```
DELETE /admin/user/{id}
```

- **認証**: JWT + RBAC
- **パスパラメータ**: `{id}` は hashid で暗号化されたユーザー ID
- **機密操作**: パスワードによる再確認が必要

**リクエストボディ**:
```json
{
  "password": "admin_password"
}
```

| フィールド | 型 | 必須 | 説明 |
|------|------|------|------|
| password | string | はい | 現在ログイン中のユーザーのパスワード（再確認） |

**レスポンス例**:
```json
{
  "code": 0,
  "message": "删除成功",
  "data": []
}
```

ソフトデリート（Eloquent SoftDeletes）を実行し、データに deleted_at をマークして物理削除はしません。

**考えられるエラー**:
- 404: ユーザーが存在しません
- 422: 機密操作にはパスワード入力による確認が必要です（password が空）
- 422: パスワード検証失敗（パスワード不一致）

### 5.6 ユーザー一括削除

```
POST /admin/user/batch/destroy
```

- **認証**: JWT + RBAC
- **機密操作**: パスワードによる再確認が必要

**リクエストボディ**:
```json
{
  "ids": ["a1b2c3d4", "e5f6g7h8"],
  "password": "admin_password"
}
```

| フィールド | 型 | 必須 | 説明 |
|------|------|------|------|
| ids | array{string} | はい | hashid で暗号化されたユーザー ID 配列 |
| password | string | はい | 現在ログイン中のユーザーのパスワード（再確認） |

**レスポンス例**:
```json
{
  "code": 0,
  "message": "删除成功",
  "data": {
    "count": 2
  }
}
```

ソフトデリートを実行し、`data.count` が実際の削除数です。

**考えられるエラー**:
- 422: 削除するユーザーを選択してください（ids が空）
- 422: 無効な ID（hashid デコード失敗）
- 422: パスワード検証失敗

### 5.7 ユーザー一括有効化/無効化

```
POST /admin/user/batch/status
```

- **認証**: JWT + RBAC

**リクエストボディ**:
```json
{
  "ids": ["a1b2c3d4", "e5f6g7h8"],
  "status": 1
}
```

| フィールド | 型 | 必須 | 説明 |
|------|------|------|------|
| ids | array{string} | はい | hashid で暗号化されたユーザー ID 配列 |
| status | int | はい | 0=無効、1=有効 |

**レスポンス例**:
```json
{
  "code": 0,
  "message": "批量启用成功",
  "data": {
    "count": 2
  }
}
```

message は status の値に応じて `"批量启用成功"` または `"批量禁用成功"` に動的に変化します。

**考えられるエラー**:
- 422: ユーザーを選択してください（ids が空）
- 422: 状態値が無効（status が 0 でも 1 でもない）

## 6. ロール管理

### 6.1 ロール一覧

```
GET /admin/role
```

- **認証**: JWT + RBAC

**クエリパラメータ**:

| パラメータ | 型 | 必須 | デフォルト値 | 説明 |
|------|------|------|------|------|
| page | int | いいえ | 1 | ページ番号 |
| limit | int | いいえ | 15 | 1ページあたりの件数 |

**レスポンス例**:
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

| フィールド | 型 | 説明 |
|------|------|------|
| id | string | hashid で暗号化されたロール ID |
| name | string | ロール名 |
| slug | string | ロール識別子（一意、権限判定に使用） |
| description | string | ロールの説明 |
| status | int | 1=有効、0=無効 |
| users_count | int | このロールを持つユーザー数 |

### 6.2 ロール作成

```
POST /admin/role
```

- **認証**: JWT + RBAC

**リクエストボディ**:
```json
{
  "name": "编辑员",
  "slug": "editor",
  "description": "内容编辑角色",
  "status": 1,
  "permission_ids": [1, 5, 12, 15]
}
```

| フィールド | 型 | 必須 | 検証ルール | 説明 |
|------|------|------|---------|------|
| name | string | はい | max:50 | ロール名 |
| slug | string | はい | max:50 | ロール識別子 |
| description | string | いいえ | | ロールの説明、デフォルトは空文字列 |
| status | int | いいえ | | 状態、デフォルト 1 |
| permission_ids | array{int} | いいえ | | 権限 ID 配列（元の INT ID、hashid ではない） |

**レスポンス例**:
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

### 6.3 ロール更新

```
PUT /admin/role/{id}
```

- **認証**: JWT + RBAC

**リクエストボディ**:
```json
{
  "name": "内容编辑",
  "description": "更新后的描述",
  "status": 1,
  "permission_ids": [1, 5, 12, 20]
}
```

| フィールド | 型 | 必須 | 説明 |
|------|------|------|------|
| name | string | いいえ | ロール名 |
| description | string | いいえ | 説明 |
| status | int | いいえ | 0=無効、1=有効 |
| permission_ids | array{int} | いいえ | 権限 ID 配列。指定するとロール権限が同期（上書き）されます |

**レスポンス例**:
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

### 6.4 ロール削除

```
DELETE /admin/role/{id}
```

- **認証**: JWT + RBAC
- **機密操作**: パスワードによる再確認が必要

**リクエストボディ**:
```json
{
  "password": "admin_password"
}
```

**レスポンス例**:
```json
{
  "code": 0,
  "message": "删除成功",
  "data": []
}
```

削除時はロールとすべての権限・ユーザーの関連付けを自動的に解除し、その後ロールレコードを物理削除します。

## 7. 権限管理

権限はツリー構造（parent_id 自己参照）を採用し、3つのタイプに分かれます。一覧APIは完全な権限ツリーを返します。

### 7.1 権限ツリー

```
GET /admin/permission
```

- **認証**: JWT + RBAC

**レスポンス例**:
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

| フィールド | 型 | 説明 |
|------|------|------|
| id | string | hashid で暗号化 |
| parent_id | string | 親権限の hashid、"0" はルートノードを表す |
| name | string | 権限名 |
| slug | string | 権限識別子（ルート/ボタン識別子） |
| type | int | 1=メニュー、2=ボタン、3=API |
| icon | string | メニューアイコン（Material アイコン名） |
| path | string | フロントエンドのルートパス |
| sort | int | ソート値（昇順） |
| children | array? | 子権限リスト（再帰）。子ノードがない場合はこのフィールドを含まない |

### 7.2 権限作成

```
POST /admin/permission
```

- **認証**: JWT + RBAC

**リクエストボディ**:
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

| フィールド | 型 | 必須 | 検証ルール | 説明 |
|------|------|------|---------|------|
| parent_id | int | いいえ | | 親権限 ID（元の INT 型）、デフォルト 0 |
| name | string | はい | max:50 | 権限名 |
| slug | string | はい | max:100 | 権限識別子 |
| type | int | はい | in:1,2,3 | 1=メニュー、2=ボタン、3=API |
| icon | string | いいえ | | メニューアイコン、デフォルトは空 |
| path | string | いいえ | | フロントエンドのルートパス、デフォルトは空 |
| sort | int | いいえ | | ソート値、デフォルト 0 |

**レスポンス例**:
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

### 7.3 権限更新

```
PUT /admin/permission/{id}
```

- **認証**: JWT + RBAC

**リクエストボディ**:
```json
{
  "name": "系统配置",
  "icon": "tune",
  "path": "/system/config",
  "sort": 50
}
```

| フィールド | 型 | 必須 | 説明 |
|------|------|------|------|
| name | string | いいえ | 権限名 |
| icon | string | いいえ | アイコン |
| path | string | いいえ | ルートパス |
| sort | int | いいえ | ソート値 |

### 7.4 権限削除

```
DELETE /admin/permission/{id}
```

- **認証**: JWT + RBAC
- **機密操作**: パスワードによる再確認が必要

**リクエストボディ**:
```json
{
  "password": "admin_password"
}
```

**レスポンス例**:
```json
{
  "code": 0,
  "message": "删除成功",
  "data": []
}
```

削除時はすべての子権限（`parent_id` = 現在の権限 ID のレコード）をカスケード削除し、同時にすべてのロールとの関連を解除します。

## 8. システム設定

システム設定は `group` + `key` の組み合わせで一意になります。

### 8.1 設定一覧

```
GET /admin/config
```

- **認証**: JWT + RBAC

**クエリパラメータ**:

| パラメータ | 型 | 必須 | デフォルト値 | 説明 |
|------|------|------|------|------|
| page | int | いいえ | 1 | ページ番号 |
| limit | int | いいえ | 15 | 1ページあたりの件数 |
| group | string | いいえ | | 設定グループでフィルター |

**レスポンス例**:
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

| フィールド | 型 | 説明 |
|------|------|------|
| id | string | hashid |
| group | string | 設定グループ（例: `system`、`email`、`storage`） |
| key | string | 設定キー |
| value | string | 設定値 |
| type | string | 値の型ヒント（`string`、`integer`、`boolean`、`json` など） |
| description | string | 設定の説明 |

### 8.2 設定作成

```
POST /admin/config
```

- **認証**: JWT + RBAC

**リクエストボディ**:
```json
{
  "group": "email",
  "key": "smtp_host",
  "value": "smtp.example.com",
  "type": "string",
  "description": "SMTP 服务器地址"
}
```

| フィールド | 型 | 必須 | 検証ルール | 説明 |
|------|------|------|---------|------|
| group | string | はい | max:100 | 設定グループ |
| key | string | はい | max:100 | 設定キー（同一グループ内で一意） |
| value | string | はい | | 設定値 |
| type | string | いいえ | | 値の型、デフォルトは `string` |
| description | string | いいえ | | 設定の説明、デフォルトは空 |

**レスポンス例**:
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

**考えられるエラー**:
- 422: 設定項目は既に存在します（同じ group + key）

### 8.3 設定更新

```
PUT /admin/config/{id}
```

- **認証**: JWT + RBAC

**リクエストボディ**:
```json
{
  "value": "smtp.newhost.com",
  "type": "string",
  "description": "更新后的 SMTP 地址"
}
```

| フィールド | 型 | 必須 | 説明 |
|------|------|------|------|
| value | string | いいえ | 設定値を更新 |
| type | string | いいえ | 値の型を更新 |
| description | string | いいえ | 説明文を更新 |

### 8.4 設定削除

```
DELETE /admin/config/{id}
```

- **認証**: JWT + RBAC
- **機密操作**: パスワードによる再確認が必要

**リクエストボディ**:
```json
{
  "password": "admin_password"
}
```

設定レコードを物理削除します。

## 9. 操作ログ

操作ログは読み取り専用APIです。`OperationLog` ミドルウェアが POST/PUT/DELETE リクエストのたびに自動的に書き込み、保存フィールドには `user_id`、`action`、`method`、`path`、`ip`、`source`、`input` が含まれます。

### 9.1 操作ログ一覧

```
GET /admin/log
```

- **認証**: JWT + RBAC

**クエリパラメータ**:

| パラメータ | 型 | 必須 | デフォルト値 | 説明 |
|------|------|------|------|------|
| page | int | いいえ | 1 | ページ番号 |
| limit | int | いいえ | 15 | 1ページあたりの件数 |
| user_id | int | いいえ | | ユーザー ID で完全一致フィルター（元の INT 型） |
| action | string | いいえ | | 操作アクションで完全一致フィルター |
| path | string | いいえ | | リクエストパスで部分一致フィルター |
| start_date | string | いいえ | | 開始日 (Y-m-d 形式) |
| end_date | string | いいえ | | 終了日 (Y-m-d 形式) |

**レスポンス例**:
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

| フィールド | 型 | 説明 |
|------|------|------|
| id | string | hashid |
| user_name | string | 操作したユーザー名（user リレーションから取得、未ログイン操作は"システム"と表示） |
| action | string | 操作アクションの説明 |
| method | string | HTTP メソッド（POST/PUT/DELETE） |
| path | string | リクエストパス |
| ip | string | クライアント IP |
| source | string | リクエストの送信元 |
| input | string | リクエストパラメータの JSON 文字列（ファイルを含まない） |
| created_at | string | 操作時間 (datetime) |

## 10. プロフィール

プロフィールAPIは JWT 認証のみ必要です（RBAC 権限チェックは不要——`AdminPermission` ミドルウェアはこれをホワイトリストに追加する必要があります）。

### 10.1 個人情報の更新

```
PUT /admin/profile
```

- **認証**: JWT

**リクエストボディ**:
```json
{
  "real_name": "新姓名",
  "phone": "13800138000",
  "email": "me@example.com"
}
```

| フィールド | 型 | 必須 | 説明 |
|------|------|------|------|
| real_name | string | いいえ | 氏名 |
| phone | string | いいえ | 携帯番号（Encryptable で暗号化保存） |
| email | string | いいえ | メール（Encryptable で暗号化保存） |

**レスポンス例**:
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

レスポンスでは `phone` と `email` が平文で返され、`password` と `id_card` は除外されています。

### 10.2 パスワード変更

```
PUT /admin/profile/password
```

- **認証**: JWT

**リクエストボディ**:
```json
{
  "old_password": "current_pass",
  "new_password": "new_pass_123"
}
```

| フィールド | 型 | 必須 | 検証ルール | 説明 |
|------|------|------|---------|------|
| old_password | string | はい | | 現在のパスワード |
| new_password | string | はい | min:6, max:32 | 新しいパスワード |

**レスポンス例**:
```json
{
  "code": 0,
  "message": "密码修改成功",
  "data": []
}
```

**考えられるエラー**:
- 422: 旧パスワードと新パスワードを入力してください
- 422: 旧パスワードが正しくありません
- 422: 新パスワードは 6〜32 文字

### 10.3 ログアウト

```
POST /admin/profile/logout
```

- **認証**: JWT

**リクエストボディ**: なし（requestBody なし、Authorization ヘッダーからトークンを読み取る）

**レスポンス例**:
```json
{
  "code": 0,
  "message": "已登出",
  "data": []
}
```

ログアウトロジック: JWT をデコードして残り有効期間 (exp - now) を取得し、そのトークンの md5 ハッシュを Redis ブラックリスト `jwt_blacklist:{md5}` に書き込みます（TTL = 残り有効期間）。ブラックリスト上のトークンは `AdminAuth` ミドルウェアで遮断され、401 が返ります。

トークンがない場合は 401 を返します。トークンが期限切れ/無効の場合（デコードで例外）もログアウト成功とみなします。

## 11. インポート/エクスポート

### 11.1 Excel エクスポート

```
POST /admin/export/excel
```

- **認証**: JWT + RBAC
- **レスポンスタイプ**: ファイルダウンロード（`application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`）

**リクエストボディ**:
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

| フィールド | 型 | 必須 | デフォルト値 | 説明 |
|------|------|------|------|------|
| table | string | いいえ | `admin_user` | エクスポートするテーブル名。対応: `admin_user`、`operation_log`、`admin_role`、`system_config` |
| columns | array{string} | いいえ | | エクスポートする列のフィールド名配列。空ならテーブル全列をエクスポート |
| conditions | object | いいえ | `{}` | フィルター条件。key-value ペアで、値が空でない場合に WHERE に使用 |
| title | string | いいえ | `数据导出` | Excel タイトル（Sheet 名として表示） |

**対応するテーブルと列**:

| table | 利用可能な列 |
|-------|-------|
| `admin_user` | id, username, real_name, phone, email, status, last_login_at, last_login_ip, created_at |
| `operation_log` | id, user_id, action, method, path, ip, created_at |
| `admin_role` | id, name, slug, description, status, created_at |
| `system_config` | id, group, key, value, type, description, created_at |

機密フィールド `phone`、`email`、`id_card` はエクスポート時に自動的にマスク処理されます。データ上限は 10000 行。Excel は先頭行を固定し、自動フィルターを設定します。

### 11.2 PDF エクスポート

```
POST /admin/export/pdf
```

- **認証**: JWT + RBAC
- **レスポンスタイプ**: ファイルダウンロード（`application/pdf`、A4 横向き）

**リクエストボディ**:
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

またはテーブルモード:
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

| フィールド | 型 | 必須 | デフォルト値 | 説明 |
|------|------|------|------|------|
| type | string | いいえ | `table` | エクスポートタイプ: `table` / `dashboard` |
| title | string | いいえ | `数据导出` | PDF タイトル |
| data | object | いいえ | `{}` | エクスポートデータ |

`type=dashboard` の場合は `data` に `stats` 配列が必要です（カード形式でレンダリング）；`type=table` の場合は `data` に `columns` と `rows` 配列が必要です。

PDF テンプレートには著作権情報とエクスポートタイムスタンプが含まれます。

### 11.3 ユーザーインポート (Excel)

```
POST /admin/import/users
```

- **認証**: JWT + RBAC
- **リクエストタイプ**: `multipart/form-data`（ファイルアップロード）

**フォームフィールド**:

| フィールド | 型 | 必須 | 説明 |
|------|------|------|------|
| file | file | はい | `.xlsx` または `.xls` 形式 |

**Excel 列の要件**:

| 列名 | 必須 | 説明 |
|------|------|------|
| username | はい | ユーザー名（一意） |
| password | はい | パスワード（bcrypt ハッシュで保存） |
| real_name | はい | 氏名 |
| phone | いいえ | 携帯番号 |
| email | いいえ | メール |
| status | いいえ | 状態、デフォルト 1 |

1行目は列タイトル（大文字小文字を区別しない）、2行目以降がデータです。

**レスポンス例**:
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

| フィールド | 型 | 説明 |
|------|------|------|
| total | int | 総行数（タイトル行を除く） |
| success | int | インポート成功数 |
| failed | int | 失敗数 |
| errors | array | 失敗の詳細。各項目は row（Excel 行番号）と reason（失敗原因）を含む |

## 12. ファイルアップロード

```
POST /admin/upload
```

- **認証**: JWT + RBAC
- **リクエストタイプ**: `multipart/form-data`

**フォームフィールド**:

| フィールド | 型 | 必須 | 説明 |
|------|------|------|------|
| file | file | はい | アップロードするファイル |

**許可されるファイルタイプ**: `jpg`、`jpeg`、`png`、`gif`、`pdf`、`xlsx`、`docx`
**最大ファイルサイズ**: 10MB

**レスポンス例**:
```json
{
  "code": 0,
  "message": "上传成功",
  "data": {
    "url": "/upload/2026-05-21/abc123def456.png"
  }
}
```

ファイルは日付ごとのディレクトリ `public/upload/{Y-m-d}/` に保存され、ファイル名は `md5(uniqid) + 元の拡張子` です。`url` はサイトルートからの相対パスです。

**考えられるエラー**:
- 422: ファイルを選択してください（未アップロード）
- 422: サポートされないファイルタイプ
- 422: ファイルサイズは 10MB を超えられません
- 500: ファイルアップロード失敗（ファイルが無効）

## 13. レスポンスヘッダー

すべてのAPI（グローバルミドルウェア層で注入）には以下のレスポンスヘッダーが含まれます：

| ヘッダー | 説明 |
|----|------|
| `X-RateLimit-Limit` | レート制限の上限（回数） |
| `X-RateLimit-Remaining` | 残りリクエスト回数 |
| `X-RateLimit-Reset` | レート制限ウィンドウのリセットタイムスタンプ |
| `Retry-After` | レート制限発動時のみ返され、推奨待機秒数 |
| `X-Content-Type-Options` | `nosniff`（webman のデフォルト、MIME スニッフィング禁止） |
| `X-Frame-Options` | `DENY`（webman の CORS ミドルウェア/基本設定による） |

レート制限の詳細:
- デフォルトのグローバル制限: 60回/分 / IP+パス
- ログインエンドポイント `/api/auth/login`: 10回/分
- 登録エンドポイント `/api/auth/register`: 5回/分
- Redis のアトミックなスライディングウィンドウアルゴリズム（Lua ZSET）を使用し、TOCTOU 競合を回避
- Redis が利用できない場合は fail open（通過させる）、リクエストをブロックしない

## 14. 認証フロー

完全な認証シーケンス:

```
1. クライアントが POST /api/captcha/generate をリクエスト
   (リクエストヘッダー: API-Version: v1)
    ↓
   サーバーが返す: key + type(click|slider|rotate) + base64 画像 + extra(タイプ別データ)
   
2. ユーザーがキャプチャ操作（クリック/ドラッグ/回転）を完了し、クライアントが回答を収集
   
3. クライアントが POST /api/captcha/verify をリクエスト
   (リクエストヘッダー: API-Version: v1, Content-Type: application/json)
   リクエストボディ: { key, type, clicks }
   - type=click:  clicks = [{x, y}, ...]        // 座標配列
   - type=slider: clicks = 120                   // X オフセット
   - type=rotate: clicks = 315                   // 回転角度
    ↓
   サーバー:
   a. ストレージから captcha:key データを読み取る（TTL 300秒）
   b. type に応じて回答を検証（click: ユークリッド距離 ≤18px / slider: ±4px / rotate: ±5°）
   c. 検証成功 → Redis `captcha_verified:{key}` = 1 を書き込み (TTL 300秒)
   d. 検証失敗 → 422 を返し、カウント +1、3回超で key が無効化
    ↓
   サーバーが返す: { valid: true/false }

4. クライアントが POST /api/auth/login をリクエスト
   (リクエストヘッダー: API-Version: v1, Content-Type: application/json)
   リクエストボディ: { username, password(暗号化), captcha_key }
    ↓
   サーバー:
   a. パラメータ検証 → 422
   b. captcha_verified:{key} の存在を確認 → 422
   c. captcha_verified:{key} を削除（一度きりの使用）
   d. パスワード復号: EncryptionService::decrypt(password) → 平文
   e. ユーザー資格情報を検証 (password_verify) → 401
   f. アカウント状態を確認 → 403/429
   g. JWT を発行 (access + refresh) → 200
   h. last_login_at / last_login_ip を更新
    ↓
   クライアントは保存: access_token, refresh_token, expires_in

5. 以降のリクエストに JWT を添付
   リクエストヘッダー: Authorization: Bearer <access_token>
    ↓
   AdminAuth ミドルウェア:
   a. Bearer トークンを抽出
   b. ブラックリストを確認 (Redis jwt_blacklist:{md5}) → 401
   c. JWT をデコードし、期限切れを検証 → 401
   d. $request->adminId = sub フィールドを設定
    ↓
   AdminPermission ミドルウェア:
   a. リソースルートの権限識別子を解析
   b. ユーザーのロール → ロール権限を照会し、マッチング
   c. 権限なし → 403
    ↓
   Controller がリクエストを処理
    ↓
   Response + X-RateLimit-* ヘッダー

6. Access Token の期限切れ前にリフレッシュ
   クライアントが POST /api/auth/refresh をリクエスト
   リクエストボディ: { refresh_token: "..." }
    ↓
   サーバーが refresh_token をデコード → 新しい access + refresh を発行
    ↓
   クライアントがローカルトークンを更新

7. ログアウト
   クライアントが POST /admin/profile/logout をリクエスト
   リクエストヘッダー: Authorization: Bearer <access_token>
    ↓
   サーバー:
   a. JWT をデコードして残り TTL を取得
   b. Redis ブラックリストに書き込み: jwt_blacklist:{md5(token)} = 1, TTL = 残り有効期間
   c. 成功を返す
```

### JWT 構造

- **access_token**: `{ sub: <user_id>, username: "<name>" }`、デフォルト TTL 7200 秒（JWT 設定の `default_expire` で制御）
- **refresh_token**: `{ sub: <user_id>, token_type: "refresh" }`、デフォルト TTL 1209600 秒（JWT 設定の `refresh_expire` で制御、14日）

### セキュリティ管理

- パスワードは `PASSWORD_BCRYPT` ハッシュで保存
- パスワードの転送は AES-256-CBC-HMAC で暗号化（クライアント暗号化 → サーバー復号）、平文フォールバックに対応
- 機密フィールド（phone, email, id_card）は `erikwang2013/encryptable` でデータベース層の透過的な暗号化/復号を実現
- API 層の ID は `erikwang2013/hashids` で暗号化して転送し、元の snowflake ID シーケンスの露出を防止
- SecurityFilter が XSS、SQL インジェクション、パストラバーサル、コマンドインジェクションをグローバルにスキャンし、同一 IP は 5回/60秒で 15 分間の一時ブラックリスト
- 機密操作（ユーザー、ロール、権限、設定の削除）には現在ログイン中のユーザーのパスワードによる再確認が必要
- 同時セッション制限: 同一ユーザーは有効なトークンを最大 3 つ保持でき、4台目のデバイスでログインすると最も古いトークンが強制的にブラックリスト入り
- アカウントロック: ログイン失敗が 5 回連続すると 15 分間のアカウントロックが発動し、ロック中は 429 を返します

## 15. デプロイと運用

### Docker Compose

プロジェクトルートに `docker-compose.yml` があり、5つのサービス（Nginx、webman app、MySQL、Redis、Elasticsearch）を構成します。PHP は `Dockerfile` でビルドされます（`php:8.3-cli` ベース、OPcache 有効）。

```bash
cp .env.docker .env
docker-compose up -d
```

### CI/CD

`.github/workflows/ci.yml` が GitHub Actions の継続的インテグレーションパイプラインを定義しています:
- `php -l` 構文チェック
- PHPUnit 単体テスト
- `flutter analyze` 静的解析

### データベースバックアップ

`database/backup/` ディレクトリにバックアップと復元のスクリプトがあります:
- `backup.sh` — mysqldump + gzip で圧縮バックアップ、30日前の古いバックアップを自動削除
- `restore.sh` — 対話式の復元、既存バックアップを一覧表示して選択

### Nginx セキュリティ設定

本番環境へのデプロイでは `docs/nginx-security.conf` を参照してリバースプロキシのセキュリティ強化を設定してください。
