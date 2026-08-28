# Social Platform

**语言 / Languages:** [中文](../README.md) · [English](README.en.md) · [한국어](README.ko.md) · [Русский](README.ru.md) · [Deutsch](README.de.md) · [Français](README.fr.md) · [Español](README.es.md) · [Português](README.pt.md) · [हिन्दी](README.hi.md) · [العربية](README.ar.md) · [বাংলা](README.bn.md) · [Bahasa Indonesia](README.id.md) · [日本語](README.ja.md)

Multilingual social platform monorepo: image/text community + instant messaging + live/voice + virtual economy.

## Introduction

- **Three native clients**: Android (Kotlin + Compose), iOS (SwiftUI), HarmonyOS (ArkTS), plus a Flutter admin console
- **Business services**: webman v2 (PHP 8.3) serving both REST and WebSocket channels; live/voice-call state machines migrated to Rust (infrastructure/bee-rust); PHP controllers connect via gRPC; the API is versioned via `X-Api-Version` (default v1, compatible with legacy `/api/vX` paths)
- **In-house media layer**: mediasoup SFU + coturn TURN for media forwarding in 1v1 voice calls and voice chat rooms (8 seats)
- **State layering**: MySQL as the source of truth for business data, Redis for real-time session / IM / call / room state
- **Milestones**: M0–M5 delivered (voice messages, 1v1 calls, voice chat rooms, live streaming); M6 delivers the Rust migration of live/voice state machines (PHP calls Rust directly over gRPC; circuit breaker / degradation / rate limiting); M6a delivers the virtual economy: wallet (balance/ledger, MySQL as single source of truth), gift tipping with streamer share, and mobile IAP top-up (App Store / Google Play / Huawei); M6b delivers payment channels: top-up crediting skeleton (WeChat/Alipay/Stripe callback signature verification, server-side pricing, idempotent crediting; withdrawals delivered, reconciliation later)

## Feature Overview

![Feature Overview](diagrams/features.en.svg)

## Architecture

![Architecture](diagrams/architecture.en.svg)

## Core Business Flows

![Core Business Flows](diagrams/core-flow.en.svg)

## Lifecycle

![Lifecycle](diagrams/lifecycle.en.svg)

## Module Design

![Module Design](diagrams/module-design.en.svg)

## Project Structure

| Directory | Description | Tech |
|------|------|------|
| contracts/ | gRPC contracts (proto, buf generation entry) | protobuf / buf |
| service/ | User-facing business service (REST :8788 + WS :8789) | webman v2 (PHP 8.3) |
| admin/ | Admin console (built on open-admin) | webman v2 + Flutter |
| infrastructure/ | High-throughput compute layer (live/voice gRPC services) | bee-rust (tonic) |
| media/sfu/ | In-house media layer (mediasoup SFU :8790 + coturn :3478) | Node.js (enabled in M4) |
| apps/ | Three native clients | SwiftUI / Kotlin+Compose / ArkTS |

Internal structure of service:

```
service/
├── app/
│   ├── controller/   # REST controllers (auth/post/follow/im/voice/wallet/gift/payment/...)
│   ├── common/        # WalletService (balance/ledger/idempotent) · GiftService (gift/split) · PaymentService (idempotent order/credit) · PaymentVerifier (3-channel verify)
│   ├── ws/           # WsServer · Envelope frame protocol · Deliverer push · ConnectionRegistry
│   ├── call/         # CallCenter: 1v1 call state machine (M6 migrated to Rust; PHP side kept for WS signaling)
│   ├── room/         # RoomCenter: voice chat rooms (M6 migrated to Rust; PHP side kept for WS signaling)
│   ├── live/         # LiveCenter: live rooms (M6 migrated to Rust; PHP side kept for WS signaling)
│   ├── model/        # Data models
│   ├── process/      # Custom Http / WsServer processes
│   └── storage/      # Voice file storage (m4a; carried by Rust VoiceStorage since M6)
├── config/           # route.php (/api/v1 route group) · process.php (:8788/:8789) · payment.php (channel keys/pricing)
└── tests/            # phpunit unit tests (incl. PaymentServiceTest) + im_e2e.php / voice_e2e.php / live_e2e.php / wallet_e2e.php / payment_e2e.php black-box E2E
```

## Getting Started

### Dependencies

- PHP ≥ 8.3 (composer)
- Redis (default 127.0.0.1:6379)
- Node.js ≥ 18 (local SFU debugging)
- Docker (SFU / coturn containers)

### Start the business service

```bash
cd service
composer install
php start.php start -d      # HTTP :8788 · WS :8789
```

Configure `REDIS` and `SFU_URL` (default 127.0.0.1:8790) in `service/.env` as needed.

### Start the media layer

```bash
cd media/sfu
docker compose up -d --build   # SFU :8790 (RTC UDP 10000-10200) · coturn :3478
```

### Clients

| Platform | How to open / build | Platform requirements |
|----|----------------|----------|
| Android | `cd apps/android && ./gradlew assembleDebug` | Buildable on Linux / macOS |
| iOS | Open `apps/ios/SocialApp` in Xcode | Requires macOS |
| HarmonyOS | Open `apps/harmonyos` in DevEco Studio | Requires DevEco Studio |

### Tests

```bash
cd service
vendor/bin/phpunit                    # Unit tests (79 tests / 230 assertions)

php tests/im_e2e.php                  # IM black-box E2E (requires :8788/:8789 running + Redis)
php tests/voice_e2e.php               # Voice E2E: versioned / voice messages / calls / voice chat rooms
php tests/live_e2e.php                # Live E2E: rooms / danmaku / mic / close (RTMP push, HLS pull)

cd media/sfu
npm run smoke                         # SFU /signal protocol smoke test (requires Docker container or local node)
```

## Welcome Support

If this project helps you, scan the QR code to support us, thank you!

**WeChat Pay**

<img src="weixinpay.png" width="160" height="175" alt="WeChat Pay">


**Alipay**

<img src="alipay.png" width="160" height="175" alt="Alipay">

**Global Transfer (Bank Transfer)**




If you find this project helpful, you are welcome to support development via global bank transfer.

**Payee Information**

| Item | Details |
|------|------|
| Payee Name | WANG KEXUN |
| Payee Account Number | 881015918251 |

**Receiving Bank — ZA Bank**

| Item | Details |
|------|------|
| SWIFT Code | AABLHKHHXXX |
| Bank Name | ZA Bank Limited |
| Bank Code | 387 |
| Bank Address | Core F, Cyberport 3, 100 Cyberport Road, Hong Kong |

**Correspondent Bank for Cross-Border Remittance (if needed)**

> The following is the correspondent (intermediary) bank information for cross-border remittance, not the receiving bank. Please check with your remitting bank whether correspondent bank information is required.

The correspondent bank for remittances in Hong Kong dollars, renminbi, and US dollars is **Citibank**:

| Item | Details |
|------|------|
| Bank Name | Citibank N.A. Hong Kong |
| SWIFT Code | CITIHKHXXXX |
| Bank Code | 006 |
| Branch Name | Hong Kong Branch |
| Branch Code | 391 |
| Bank Address | Citibank Tower, Citibank Plaza, 3 Garden Road, Central, Hong Kong |

The correspondent bank for remittances in other currencies is **BNY Mellon**:

| Item | Details |
|------|------|
| Bank Name | THE BANK OF NEW YORK MELLON |
| SWIFT Code | IRVTUS3NXXX |
| Bank Address | THE BANK OF NEW YORK MELLON, 240 GREENWICH STREET, NEW YORK, United States |


## Documentation

- Overall design: `superpowers/specs/2026-08-16-social-platform-design.md`
- M4 voice design: `superpowers/specs/2026-08-17-m4-voice-design.md`
- Implementation plan: `superpowers/plans/2026-08-17-m4-voice.md`
