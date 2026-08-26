# Admin ベースライン受入（M0、2026-08-17）

**语言 / Languages:** [中文](ADMIN_BASELINE.md) · [English](ADMIN_BASELINE.en.md) · [한국어](ADMIN_BASELINE.ko.md) · [Русский](ADMIN_BASELINE.ru.md) · [Deutsch](ADMIN_BASELINE.de.md) · [Français](ADMIN_BASELINE.fr.md) · [Español](ADMIN_BASELINE.es.md) · [Português](ADMIN_BASELINE.pt.md) · [हिन्दी](ADMIN_BASELINE.hi.md) · [العربية](ADMIN_BASELINE.ar.md) · [বাংলা](ADMIN_BASELINE.bn.md) · [Bahasa Indonesia](ADMIN_BASELINE.id.md) · [日本語](ADMIN_BASELINE.ja.md)

open-admin（webman v2 + Flutter 管理コンソール）のベースライン状態と改修の入り口。

## 現在のバージョンと稼働状態

| 項目 | 値 |
|---|---|
| フレームワーク | webman v2（workerman/webman-framework **v2.2.3**） |
| PHP | 8.3.7（CLI） |
| 依存関係 | `composer install` 成功、69 パッケージ |
| .env | **存在しない**（リポジトリに `.env` も `.env.example` もなく、MySQL/Redis に合わせてローカルで作成が必要） |
| マイグレーション入口 | なし（`think`/`artisan` なし。webman はマイグレーション非内蔵、M0 にマイグレーションタスクなし） |
| テスト | `vendor/bin/phpunit`：60 tests / 136 assertions、**4 errors / 7 failures / 6 warnings / 1 risky、全緑ではない** |

## 有効化済みモジュール（README で確認）

- **JWT 認証**：ログイン/リフレッシュ/ログアウト、クリック CAPTCHA、アカウントロック（5 回失敗で 15 分ロック）、同時セッション制限（ユーザーごとに ≤3 Token）
- **RBAC**：ロール/権限ツリー、method.path 粒度の認可
- **操作監査**：ログ照会 + 8 プラットフォームの由来識別
- **ファイル管理**：アップロード / Excel エクスポート / PDF エクスポート（マスキング）
- **i18n**：中英切り替え（Accept-Language / ?lang=）
- その他：ダッシュボード（Redis キャッシュ）、システム設定、ヘルスチェック/metrics/OpenAPI 3.0、18 層のセキュリティ防御

## テスト失敗の明細（いずれも既存のプロジェクト課題であり、今回の変更によるものではない）

| テストグループ | 失敗 | 原因 |
|---|---|---|
| `EnvConfigTest`（5 件） | 4 failure + 1 error | テストは `.env`/`.env.example` が必須で、`APP_NAME`/`JWT_SECRET_KEY`/`DB_HOST` などの getenv 値が設定されていることを検証。リポジトリにサンプル env が同梱されていない |
| `CaptchaTest`（4 件） | 3 error + 1 failure（ほかに 1 risky はアサーションなし） | クリック CAPTCHA が Redis ストレージに依存するが、ローカル未提供 |
| `BackendEnhancementTest`（2 件） | 2 failure | `user` データソースに searchable、middleware に cors/rate_limit が含まれることを検証——設定とテストアサーションのドリフト |

全緑復帰のローカル手順：`config/` 内の設定キーに従って `.env` を作成し（EnvConfigTest が依存するキーを補う）、MySQL + Redis を用意し（CaptchaTest 用）、担当者が BackendEnhancementTest の 2 件の設定ドリフトを裁決する。

## gRPC 準備状態（T3）

- Composer パッケージ導入済み：`grpc/grpc 1.82.0`、`google/protobuf 5.35`（`--no-plugins` で security-php プラグインの重複ロードバグを回避）
- PHP スタブ生成済み：`admin/generated/`（`Social/Admin/V1/AdminServiceClient.php` など。infra/user の 3 セットの契約を含む）
- **grpc PHP 拡張は未導入**：pecl に書き込み権限がなく sudo にパスワードが必要。gRPC クライアント実行には `sudo pecl install grpc` が必要

## 改修の入り口（設計書 §3.4 の 8 項目の新規追加）

1. コンテンツ審査ワークベンチ：投稿/コメント/画像の二言語対照審査、却下理由の多言語テンプレート、ユーザー処罰
2. 通報処理キュー
3. GDPR リクエスト台（エクスポート/削除チケット）
4. データダッシュボードの bee_tsdb 連携
5. i18n 用語管理（4 クライアント共用の用語 CRUD）
6. ギフトライブラリ管理（SKU、価格、エフェクト、多言語名）
7. ライブ provider 設定（ルーティング戦略、切替順序）
8. 出金申請の審査

**gRPC 接続ポイント**：admin 側の契約スタブは `admin/generated/` にある（`Social/Admin/V1` の死活監視 + 今後の業務メッセージを再利用）。service への呼び出しは `Social\User\V1\UserServiceClient`、infrastructure への呼び出しは `Social\Infra\V1\InfraServiceClient` 経由。service/infrastructure との死活監視チェーンは `service/README.grpcs.md` と T10 統合プローブを参照。
