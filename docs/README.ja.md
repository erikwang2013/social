# ソーシャルプラットフォーム

**语言 / Languages:** [中文](../README.md) · [English](README.en.md) · [한국어](README.ko.md) · [Русский](README.ru.md) · [Deutsch](README.de.md) · [Français](README.fr.md) · [Español](README.es.md) · [Português](README.pt.md) · [हिन्दी](README.hi.md) · [العربية](README.ar.md) · [বাংলা](README.bn.md) · [Bahasa Indonesia](README.id.md) · [日本語](README.ja.md)

多言語ソーシャルプラットフォームのモノレポ：テキスト/画像コミュニティ + インスタントメッセージ + ライブ/ボイス + 仮想経済。

## プロジェクト紹介

- **3つのネイティブクライアント**：Android（Kotlin + Compose）、iOS（SwiftUI）、HarmonyOS（ArkTS）。Flutter製の管理コンソールもあり
- **ビジネスサービス**：webman v2（PHP 8.3）がRESTとWebSocketの両チャネルを提供。ライブ / ボイスルーム / 1v1 通話の状態機械は Rust に移行（infrastructure/bee-rust）、PHP コントローラは gRPC で直接接続；APIは `X-Api-Version` でバージョン管理（デフォルトv1、旧 `/api/vX` パスと互換）
- **自前メディア層**：mediasoup SFU + coturn TURNによる1v1音声通話・ボイスチャットルーム（8席）のメディア中継
- **状態の階層化**：MySQLはビジネスの事実、Redisはセッション / IM / 通話 / ルームのリアルタイム状態を担当
- **マイルストーン**：M0–M5を納品済み（音声メッセージ、1v1通話、ボイスチャットルーム、ライブ配信）。M6はlive/voice状態機械のRust移行を納品（PHPはgRPC経由でRustを直接呼び出し、サーキットブレーカー／デグレード／レート制限）。M6aは仮想経済を納品：ウォレット（残高/台帳、MySQLが唯一の事実源）、ギフト投げ銭と配信者分配、モバイルIAPチャージ（App Store / Google Play / Huawei）；M6bは決済チャネルを納品：チャージ入金の骨格（WeChat/Alipay/Stripeコールバック署名検証、サーバー側価格設定、冪等入金；出金と照合は納品済み）；M6cはCDNストレージを納品：プロバイダーは管理パネルから設定可能（S3互換：AWS S3 / Cloudflare R2 / Aliyun OSS / Tencent COS / Backblaze B2）、画像/音声/ファイルはオブジェクトストレージ + CDN経由で配信；M6dは管理レポートとダッシュボード統計を納品：レポートモジュール（ユーザー/決済/出金——日付フィルタ、集計、トレンド、分布、Excelエクスポート）とトップページのプラットフォーム統計カード

## 機能概要

![機能概要](diagrams/features.ja.svg)

## アーキテクチャ設計

![アーキテクチャ設計](diagrams/architecture.ja.svg)

## ビジネスコアフロー

![ビジネスコアフロー](diagrams/core-flow.ja.svg)

## ライフサイクル

![ライフサイクル](diagrams/lifecycle.ja.svg)

## 機能設計

![機能設計](diagrams/module-design.ja.svg)

## プロジェクト構成

| ディレクトリ | 説明 | 技術 |
|------|------|------|
| contracts/ | gRPC契約（proto、buf生成エントリ） | protobuf / buf |
| service/ | ユーザー向けビジネスサービス（REST :8788 + WS :8789） | webman v2 (PHP 8.3) |
| admin/ | 管理コンソール（open-adminベース） | webman v2 + Flutter |
| infrastructure/ | 高スループット計算層（live/voice gRPC サービス） | bee-rust (tonic) |
| media/sfu/ | 自前メディア層（mediasoup SFU :8790 + coturn :3478） | Node.js（M4で有効化） |
| apps/ | 3つのネイティブクライアント | SwiftUI / Kotlin+Compose / ArkTS |

service の内部構造：

```
service/
├── app/
│   ├── controller/   # RESTコントローラ（auth/post/follow/im/voice/wallet/gift/...）
│   ├── common/        # WalletService（残高/台帳/冪等）· GiftService（ギフト/分配）
│   ├── ws/           # WsServer · Envelopeフレームプロトコル · Deliverer配信 · ConnectionRegistry
│   ├── call/         # CallCenter：1v1通話ステートマシン（M6 で Rust に移行、PHP 側は WS シグナリング用に維持）
│   ├── room/         # RoomCenter：ボイスチャットルーム（M6 で Rust に移行、PHP 側は WS シグナリング用に維持）
│   ├── live/         # LiveCenter：ライブルーム（M6 で Rust に移行、PHP 側は WS シグナリング用に維持）
│   ├── model/        # データモデル
│   ├── process/      # Http / WsServer カスタムプロセス
│   └── storage/      # 音声ファイル保存（m4a；M6 以降は Rust VoiceStorage が担う）
├── config/           # route.php（/api/v1 ルートグループ）· process.php（:8788/:8789）
└── tests/            # phpunit単体テスト + im_e2e.php / voice_e2e.php / live_e2e.php / wallet_e2e.php ブラックボックスE2E
```

## ワンクリックインストール

前提条件：PHP ≥ 8.3（composer）、MySQL、Redis（Docker は任意、メディア層用）。

```bash
./install.sh
```

スクリプトの内容：`service/` と `admin/` でそれぞれ `composer install` を実行；`database/install.sql` からデータベースを作成（冪等、CREATE IF NOT EXISTS）；両サービスの `.env` を生成（ランダムなJWT / APPキー、既存ファイルは上書きしない）；任意でメディア層を起動（`docker compose up -d` で media/sfu と coturn、`--skip-media` でスキップ）；最後に各サービスの起動コマンドとアクセスURLを出力。

## 手動インストール

1. 依存関係をインストール：

```bash
cd service && composer install
cd admin && composer install
```

2. データベースを作成：

```bash
mysql -u root -p < database/install.sql
```

3. 環境を設定：`service/.env.example` と `admin/.env.example` を `.env` にコピーし、DB / Redis / JWT / APP キーを記入（本番では必ずランダムなキーを使用）。

4. サービスを起動：

```bash
cd service && php start.php start -d   # HTTP :8788 · WS :8789
cd admin && php start.php start -d     # admin :8787
```

5. メディア層を起動（任意）：

```bash
cd media/sfu && docker compose up -d --build   # SFU :8790 · coturn :3478
```

## 使い方

### 依存関係

- PHP ≥ 8.3（composer）
- Redis（デフォルト 127.0.0.1:6379）
- Node.js ≥ 18（SFUローカルデバッグ用）
- Docker（SFU / coturn コンテナ）

### ビジネスサービスの起動

```bash
cd service
composer install
php start.php start -d      # HTTP :8788 · WS :8789
```

必要に応じて `service/.env` で `REDIS`、`SFU_URL`（デフォルト 127.0.0.1:8790）を設定してください。

### メディア層の起動

```bash
cd media/sfu
docker compose up -d --build   # SFU :8790（RTC UDP 10000-10200）· coturn :3478
```

### クライアント

| 端 | 開き方 / ビルド方法 | プラットフォーム要件 |
|----|----------------|----------|
| Android | `cd apps/android && ./gradlew assembleDebug` | Linux / macOS でビルド可能 |
| iOS | Xcode で `apps/ios/SocialApp` を開く | macOS が必要 |
| HarmonyOS | DevEco Studio で `apps/harmonyos` を開く | DevEco Studio が必要 |

### テスト

```bash
cd service
vendor/bin/phpunit                    # 単体テスト（79 tests / 230 assertions）

php tests/im_e2e.php                  # IMブラックボックスE2E（:8788/:8789 起動中 + Redis が必要）
php tests/voice_e2e.php               # 音声E2E：バージョン管理 / 音声メッセージ / 通話 / ボイスチャットルーム
php tests/live_e2e.php                # ライブE2E：ルーム / 弾幕 / マイク / クローズ（RTMPプッシュ、HLSプル）

cd media/sfu
npm run smoke                         # SFU /signal プロトコルのスモークテスト（Dockerコンテナまたはローカル node が必要）
```

## サポート歓迎

このプロジェクトが役に立ったら、QRコードをスキャンしてご支援ください。ありがとうございます！

**微信支付**

<img src="weixinpay.png" width="160" height="175" alt="微信支付">


**支付宝**

<img src="alipay.png" width="160" height="175" alt="支付宝">

**国際送金（銀行振込）**




このプロジェクトがお役に立ったなら、グローバル銀行送金での開発支援を歓迎します。

**受取人情報**

| 項目 | 内容 |
|------|------|
| 受取人名 | WANG KEXUN |
| 受取口座番号 | 881015918251 |

**受取銀行 — ZA Bank**

| 項目 | 内容 |
|------|------|
| SWIFT Code | AABLHKHHXXX |
| 銀行名 | ZA Bank Limited |
| 銀行番号 | 387 |
| 銀行住所 | Core F, Cyberport 3, 100 Cyberport Road, Hong Kong |

**クロスボーダー送金のコルレス銀行（必要な場合）**

> 以下はクロスボーダー送金用のコルレス銀行（中継銀行）の情報であり、受取銀行の情報ではありません。送金銀行にコルレス銀行の情報が必要かどうかお問い合わせください。

香港ドル・人民元・米ドルの送金におけるコルレス銀行は **Citibank** です：

| 項目 | 内容 |
|------|------|
| 銀行名 | Citibank N.A. Hong Kong |
| SWIFT Code | CITIHKHXXXX |
| 銀行番号 | 006 |
| 支店名 | Hong Kong Branch |
| 支店番号 | 391 |
| 銀行住所 | Citibank Tower, Citibank Plaza, 3 Garden Road, Central, Hong Kong |

その他の通貨での送金におけるコルレス銀行は **BNY Mellon** です：

| 項目 | 内容 |
|------|------|
| 銀行名 | THE BANK OF NEW YORK MELLON |
| SWIFT Code | IRVTUS3NXXX |
| 銀行住所 | THE BANK OF NEW YORK MELLON, 240 GREENWICH STREET, NEW YORK, United States |

### 仮想通貨の寄付 (Crypto Donation)

このプロジェクトがお役に立ったら、QRコードをスキャンして寄付してください。ありがとうございます！

| ネットワーク (Network) | QRコード (QR Code) | ウォレットアドレス (Wallet Address) |
|---|---|---|
| BNB Smart Chain (BEP20) | [<img src="coin/1.jpg" width="150" alt="BNB Smart Chain (BEP20)">](coin/1.jpg) | `0x355d429f97511897ccb4e271ec888205f9ab6629` |
| Tron (TRC20) | [<img src="coin/2.jpg" width="150" alt="Tron (TRC20)">](coin/2.jpg) | `TEdDHWLajt1XvqtPDWmQctdrJaC3pzZZzz` |
| Ethereum (ERC20) | [<img src="coin/3.jpg" width="150" alt="Ethereum (ERC20)">](coin/3.jpg) | `0x355d429f97511897ccb4e271ec888205f9ab6629` |
| Aptos | [<img src="coin/4.jpg" width="150" alt="Aptos">](coin/4.jpg) | `0x836e3780edfc3f7b2372b39e2a1a3a5d7adfaccd96c726f21cfde1b50dd68030` |
| Plasma | [<img src="coin/5.jpg" width="150" alt="Plasma">](coin/5.jpg) | `0x355d429f97511897ccb4e271ec888205f9ab6629` |
| Polygon POS | [<img src="coin/6.jpg" width="150" alt="Polygon POS">](coin/6.jpg) | `0x355d429f97511897ccb4e271ec888205f9ab6629` |
| Solana | [<img src="coin/7.jpg" width="150" alt="Solana">](coin/7.jpg) | `2hfhboHdmdrYsY25XfQSsEWxq5ip4EQsR7f4AzSRMUyr` |
| The Open Network (TON) | [<img src="coin/8.jpg" width="150" alt="The Open Network (TON)">](coin/8.jpg) | `UQB9kFQohzmXUir9QSSZq01iwl9aQZIDdBpNmDklljRtCoGK` |
| Arbitrum One | [<img src="coin/9.jpg" width="150" alt="Arbitrum One">](coin/9.jpg) | `0x355d429f97511897ccb4e271ec888205f9ab6629` |
| AVAX C-Chain | [<img src="coin/10.jpg" width="150" alt="AVAX C-Chain">](coin/10.jpg) | `0x355d429f97511897ccb4e271ec888205f9ab6629` |

## ドキュメント

- 全体設計：`superpowers/specs/2026-08-16-social-platform-design.md`
- M4音声設計：`superpowers/specs/2026-08-17-m4-voice-design.md`
- 実装計画：`superpowers/plans/2026-08-17-m4-voice.md`
