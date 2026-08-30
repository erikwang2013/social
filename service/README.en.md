# Social Service (user-facing business service)

**Language / Languages:** [中文](README.md) · [English](README.en.md)

webman v2 (PHP 8.3) user-facing business service: REST :8788 + WebSocket :8789 dual channel; live / voice chat room / 1v1 call state machines migrated to Rust (infrastructure/bee-rust), PHP controllers connect via gRPC.

## Features

- **REST APIs**: auth / post / follow / im / voice / wallet / gift / payment / withdrawal controllers, `/api/v1` route group, versioned via `X-Api-Version` (default v1, compatible with legacy `/api/vX` paths)
- **WebSocket**: WsServer · Envelope frame protocol · Deliverer push · ConnectionRegistry
- **Voice / live**: 1v1 calls / voice chat rooms (8 seats) / live state machines carried by Rust, PHP side kept for WS signaling
- **Storage**: voice files (m4a) uploaded and served by Rust VoiceStorage (object_store, S3-compatible); providers configurable from the admin panel
- **Virtual economy**: wallet (balance/ledger, MySQL as single source of truth), gift tipping with streamer share, mobile IAP top-up
- **Payment channels**: WeChat / Alipay / Stripe callback signature verification, server-side pricing, idempotent crediting; withdrawals and internal reconciliation

## One-Click Install

Prerequisites: PHP ≥ 8.3 (composer), MySQL, Redis.

Run from the repository root:

```bash
./install.sh
```

The script runs `composer install` once for `service/` and once for `admin/`, creates the database from the root `database/install.sql` (idempotent), generates `service/.env` and `admin/.env` (never overwrites existing files), and prints the commands to start each service and the access URLs.

## Manual Install

1. Install dependencies:

```bash
cd service && composer install
```

2. Create the database (service and admin share one database):

```bash
mysql -u root -p < ../database/install.sql
```

3. Configure environment: for a manual install, copy `.env.example` to `.env` and fill in the DB / Redis / JWT keys (always random in production); `install.sh` generates it automatically. Configure `REDIS` and `SFU_URL` (default 127.0.0.1:8790) as needed.

4. Start the service:

```bash
php start.php start -d      # HTTP :8788 · WS :8789
```

## Usage

### Routes and processes

- `config/route.php`: `/api/v1` route group (default v1, compatible with legacy `/api/vX` paths)
- `config/process.php`: registers HTTP :8788 and WsServer :8789 processes
- `config/payment.php`: payment channel keys and pricing

### Tests

```bash
vendor/bin/phpunit      # unit tests (incl. PaymentServiceTest / WalletServiceTest / VoiceStorageTest)

php tests/im_e2e.php          # IM black-box E2E (requires :8788/:8789 running + Redis)
php tests/voice_e2e.php       # Voice E2E: versioning / voice messages / calls / voice chat rooms
php tests/live_e2e.php        # Live E2E: rooms / danmaku / mic / close (RTMP push, HLS pull)
php tests/wallet_e2e.php      # Wallet E2E: balance / ledger / gift split
php tests/payment_e2e.php     # Payment E2E: order creation / callback verification / idempotent crediting
php tests/storage_e2e.php     # Storage E2E: upload URL matches the active provider (local/s3)
```

> Media layer (SFU / coturn) local debugging: `cd media/sfu && npm run smoke`; containerized: `docker compose up -d --build`.
