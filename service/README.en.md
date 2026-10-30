# Social Service (user-facing business service)

**Language / Languages:** [中文](README.md) · [English](README.en.md)

![Bub — project mascot](../docs/diagrams/mascot.svg)

webman v2 (PHP 8.3) user-facing business service: REST :8788 + WebSocket :8789 dual channel; live / voice chat room / 1v1 call state machines migrated to Rust (infrastructure/bee-rust), PHP controllers connect via gRPC.

> Mascot **Bub** · platform overview in [../docs/README.en.md](../docs/README.en.md)

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

2. Create the database: preferably run `./install.sh` from the repository root; for a manual install, import the `social` and `open_admin` segments separately (see `install.sh`).

3. Configure environment: preferably run `./install.sh` from the repository root to generate `.env`; for a manual install, create `.env` and fill in the DB / Redis / JWT keys (always random in production). Configure `REDIS`, `SFU_URL` (default 127.0.0.1:8790), and `TURN_URL` / `TURN_SECRET` (`install.sh` generates the secret) as needed.

4. Start the service:

```bash
php start.php start -d      # HTTP :8788 · WS :8789
```

## Usage

### Routes and processes

- `config/route.php`: `/api/v1` route group (default v1, compatible with legacy `/api/vX` paths)
- `config/process.php`: registers HTTP :8788 and WsServer :8789 processes
- `config/payment.php`: payment channel keys and pricing
- `GET /api/v1/voice/turn-credentials`: returns TURN REST temporary credentials (auth required)

### Tests

PHPUnit under `service/tests` covers payment signature verification; CI runs `composer install` + `vendor/bin/phpunit`.

> Media layer (SFU / coturn) local debugging: `cd media/sfu && npm run smoke`; containerized: `docker compose up -d --build`.
