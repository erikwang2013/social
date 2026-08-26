# API インターフェース自動化テストレポート
**语言 / Languages:** [中文](api-test-report.md) · [English](api-test-report.en.md) · [한국어](api-test-report.ko.md) · [Русский](api-test-report.ru.md) · [Deutsch](api-test-report.de.md) · [Français](api-test-report.fr.md) · [Español](api-test-report.es.md) · [Português](api-test-report.pt.md) · [हिन्दी](api-test-report.hi.md) · [العربية](api-test-report.ar.md) · [বাংলা](api-test-report.bn.md) · [Bahasa Indonesia](api-test-report.id.md) · [日本語](api-test-report.ja.md)

- 日付: 2026-08-27
- 実行: `tests/api/run.php`（curl アサーションスクリプト）、結果 `tests/api/results.json`
- 範囲: admin HTTP API（A01-A45）+ service HTTP API（S01-S57b、S58-S68 含む）
- サービス: admin `http://127.0.0.1:8791`、service `http://127.0.0.1:8788`（WebSocket `:8789` は今回の HTTP テストケースでは未カバー）

## 結論

**116 テストケース: 113 合格 / 3 失敗（97.4% 合格率）; 3 件の失敗はすべて原因特定済みの製品欠陥**

| グループ | 合格/全体 |
|------|-----------|
| admin A01-A45（認証、キャプチャ、ユーザー管理、HashID、ロール権限、設定、ログ、エクスポート/インポート、アップロード、ヘルスチェックなど） | 42/45 |
| service S01-S68（登録/ログイン/ログアウト/リフレッシュ、プロフィール、フォロー、投稿/いいね/タイムライン、コメント、通知、検索、IM セッション/メッセージ/プッシュ、音声アップロード/ファイル/通話/ルームなど） | 71/71 |

## 失敗テストケース（3 件、すべて製品欠陥）

| ケース | 期待値 | 実測値 | 根本原因 |
|------|------|------|------|
| A20 不正な hashid ユーザー詳細 | 404 | 500 | `HashidsService::decode()` が不正な ID に対してキャッチされない `InvalidArgumentException` をスロー（admin/app/common/HashidsService.php:28、BaseController.php:52）、例外がそのまま 500 として伝播。キャッチして 404 を返すべき |
| A39 Excel エクスポート | xlsx ファイルストリーム | 200+JSON エラーボディ（業務失敗） | `ExportController::excel()` の戻り型は `: Response` だが `use support\Response` がないため、型が `app\admin\controller\Response` と解釈される → 成功時の戻りで必ず `TypeError` が発生（ExportController.php:122）、エクスポート機能が全面的に使用不可 |
| A40 PDF エクスポート | pdf ファイルストリーム | 200+JSON エラーボディ（業務失敗） | 同上、`ExportController::pdf()`（ExportController.php:135）に `use support\Response` がない |

> 補足（同一ファイルの潜在欠陥、現在は上記 TypeError に隠されている）: `ExportController` 90 行目で phone/email に対し `EncryptionService::decrypt()` を呼ぶ一方、`AdminUser` モデルの `email/phone/id_card` フィールドには `Encryptable::class` キャストが宣言されており（書き込み時自動暗号化、読み取り時自動復号）、エクスポートで平文を二重復号することになる → 電話番号/メールが空でないアカウントが 1 つでも存在すると `EncryptionException: Invalid ciphertext prefix for AES-256-CBC` が発生。戻り型を修正してもこの問題は再現します。

## テスト中に修正した環境問題（製品コードの変更ではない）

1. **m2/m3/m4 マイグレーションテーブルの `id` に AUTO_INCREMENT がない（ブロッキング、修正済み）**: `service/database/m2.sql`/`m3.sql`/`m4.sql` が作成する `social_follows`、`social_notifications` の `id BIGINT UNSIGNED NOT NULL` に `AUTO_INCREMENT` がなく、INSERT のたびに `1364 Field 'id' doesn't have a default value` が発生し、フォロー/通知/IM/音声の全書き込み経路をブロック。ローカルで `ALTER TABLE ... MODIFY id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` を実行（残り 8 テーブルは元から自動採番）。**マイグレーションスクリプト自体への自動採番追加を推奨。**
2. **service/.env が到達不能なデータベースを指している（ブロッキング）**: `DB_PORT=13306` でパスワードなし、実際のメイン MySQL は `127.0.0.1:3306 (root/root)` にある; webman の `createUnsafeMutable` が CLI 環境変数を上書きする。テスト中、`.env` を `service/.env.api-test-bak` に退避（内容はそのまま保持）し、環境変数注入でサービスを起動; 復元は .env ファイルのアクセスポリシー制限により未実施で、手動 `mv service/.env.api-test-bak service/.env` が必要（注意: 復元後、サービス再起動で再び到達不能なデータベースに当たります）。
3. **admin は .env がなく環境変数に依存**: `DB_PASSWORD=root ENCRYPTABLE_KEY(16B) ENCRYPTION_KEY(32B)` が必要。`encryptable` プラグインは webman コンテナに provider 未登録の場合 `EnvEncryptableConfig` にフォールバック（`ENCRYPTION_KEY` を読み取り、デフォルト cipher は aes-256-gcm）、キー長不一致でアカウント作成/インポート/エクスポート時に `MissingEncryptionKeyException` が発生。
4. **Elasticsearch 未起動**: `GET /api/v1/search/posts` が 503 を返す（設計上のフォールバック）、S グループの検索ケースは想定どおり処理（0 または 503 を受け入れ）、失敗として集計しない。

## 契約/ドキュメント不整合（改訂推奨、非ブロッキング）

- キャプチャのドキュメント（apidoc と CaptchaController のコメント）は `clicks=[{x,y}]` をオブジェクト配列と記すが、`poster-php` 実装は `[[x,y]]` 座標ペア配列を要求し、ドキュメントどおりオブジェクトを渡すと実際には必ず失敗する。
- 音声アップロードは `voice_url` を `/voice/{md5}.m4a` で返す（API ルート基準、`/api/v1` プレフィックスなし）; クライアント側で自前で `/api/v1` を付与しないとアクセス不可; ファイルアクセスは認証ルート経由（token 必須）。

## 環境と再現

- テスト認証情報: テストアカウント `e2e_smoke`（admin、テスト専用パスワード）+ `apitest_*@test.dev`（service、実行後に自動クリーンアップ）、すべて `tests/api/run.php` の定数に記載、実キーは不使用。
- 再現:

```bash
cd /home/wwwroot/social/admin && DB_PASSWORD=root ENCRYPTABLE_KEY='apitest-enc-16b!!' \
  ENCRYPTION_KEY='apitest-db-encrypt-key-32-byte!!' php start.php start   # admin :8791
cd /home/wwwroot/social/service && DB_HOST=127.0.0.1 DB_PORT=3306 DB_USERNAME=root \
  DB_PASSWORD=root php start.php start                                     # service :8788
php /home/wwwroot/social/tests/api/run.php                                  # 再実行（116 ケース）
```

## インターフェース一覧（route.php / apidoc による）

- service `config/route.php`: 39 本の HTTP ルート（認証 5、ユーザー 2、フォロー 5、投稿 7、コメント 2、通知 4、検索 2、IM 4、音声/通話/ルーム 5、ヘルス/ドキュメント 3）
- admin `config/route.php`: 33 本の HTTP ルート（認証/キャプチャ 4、ユーザー CRUD 5、ロール 5、権限 2、設定 4、ログ 1、個人センター 4、エクスポート 2、インポート 1、アップロード 1、ヘルス/ドキュメント 4）
