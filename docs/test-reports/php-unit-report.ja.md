# PHP ユニットテストレポート
**语言 / Languages:** [中文](php-unit-report.md) · [English](php-unit-report.en.md) · [한국어](php-unit-report.ko.md) · [Русский](php-unit-report.ru.md) · [Deutsch](php-unit-report.de.md) · [Français](php-unit-report.fr.md) · [Español](php-unit-report.es.md) · [Português](php-unit-report.pt.md) · [हिन्दी](php-unit-report.hi.md) · [العربية](php-unit-report.ar.md) · [বাংলা](php-unit-report.bn.md) · [Bahasa Indonesia](php-unit-report.id.md) · [日本語](php-unit-report.ja.md)

- 日付: 2026-08-27
- 実行: `vendor/bin/phpunit`(PHPUnit 10.5.64, PHP 8.3.7)
- 範囲: admin/(webman 管理バックエンド) + service/(webman メインサービス)

## 結果総括

| 項目 | テストケース | アサーション | 結果 |
|------|------|------|------|
| service | 136 | 348 | ✅ 全て合格(OK) |
| admin | 67 | 180 | ✅ 全て合格(OK) |

## 環境説明

- MySQL 127.0.0.1:3306(root、空パスワード)、DB `social`(social_*)と `open_admin`(erik_*)は作成済み・データ投入済み(super_admin ロール、39 件の権限)
- Redis 127.0.0.1:6379 稼働中(キャプチャ格納 `poster:captcha:*`);Elasticsearch 未起動(ヘルスチェックは unavailable に降格、失敗扱いにしない)
- service は 8788、admin は 8791 で稼働中
- service と admin はどちらも `.env` なし(リポジトリは誤って取り込んだ env を削除済み、commit e5379fc)、アプリは `config/*.php` の `getenv('X') ?: デフォルト値` フォールバックで稼働
- **Imagick 拡張はロード済みだが `RESOURCETYPE_PIXELS` 定数が欠落**(本機のビルドには新しい RESOURCETYPE_* 定数セットしかない)、poster-php の ImagickDriver はコンストラクタでこの定数を参照するため即クラッシュ

## service(136/136 全緑)

- 前バッチのベースラインと一致、カバー対象: 認証/ミドルウェア/JWT、ユーザー、投稿、コメント、フォロー、通知、検索同期、IM、ルーム、通話(CallCenter/CallState)、音声、モデル関係、アクション処理(WS)
- 本バッチではコード変更なし、失敗なし

## admin(前バッチ 49/60 → 本バッチ 67/67 全緑)

### 修正: 実コードの欠陥(1 箇所)

| 場所 | 根本原因 | 修正 |
|------|------|------|
| `config/poster.php` | `image.driver` のデフォルトが `auto`、DriverFactory は Imagick 拡張を検出すると ImagickDriver を選ぶが、本機の Imagick には `RESOURCETYPE_PIXELS` 定数がない → キャプチャ生成/ポスターが直接 500(本番サービスも同様に影響) | ドライバ検出に定数ガードを追加: `getenv('POSTER_IMAGE_DRIVER') ?: (defined('Imagick::RESOURCETYPE_PIXELS') ? 'auto' : 'gd')`、定数欠落時は自動で GD にフォールバック |

### 修正: 陳腐化したアサーション(現在のコードと照合して更新)

| テストファイル | ケース | 根本原因 | 修正 |
|----------|------|------|------|
| EnvConfigTest | env_file_exists / env_example_file_exists / getenv_reads_env_variables / config_env_keys_exist_in_dotenv(4 失敗+1 エラー) | `.env`/`.env.example` の存在と getenv の値の有無をアサーションするが、リポジトリは env ファイルを削除済みで再構築不可 | 「.env なしで動作する」契約に書き換え: 各 `getenv()` キーに `?:` デフォルト値が必要、デフォルト設定はローカルサービス(127.0.0.1:3306/open_admin)を指す、重要設定の型が正しい |
| BackendEnhancementTest | test_admin_user_source_contains_searchable | AdminUser は Searchable trait を廃止(代わりに `Erikwang2013\Encryptable\Encryptable` でフィールド透過的暗号化/復号化、`toSearchableArray()` は残置) | Encryptable trait をアサーションするよう変更; toSearchableArray のアサーションは元々通過していたため残置 |
| BackendEnhancementTest | test_middleware_config_contains_cors_and_rate_limit | `config/middleware.php` が `'@'` グローバルグループキー形式に変更され、トップレベル配列にミドルウェアクラスが直接含まれない | `$middlewares['@']` に Cors と RateLimit が含まれるかを検証するよう変更 |
| CaptchaTest | 全 7 ケース(元は 6 エラー+1 失敗) | 二重の陳腐化: (a) Imagick 定数の欠落(既に poster.php で修正); (b) 旧 poster-php 契約に基づくアサーション — `extra.targets`(x/y 含む)が `extra.texts`(text+order のみ)に変更、座標はストレージ層のみに保存; クリック検証形式が `['x'=>, 'y'=>]` から `[x, y]` 数値ペアに変更 | 現在の契約に合わせて書き換え: 構造/難易度数(2/3/4)/フィールド検証、正しいクリックは Redis(`poster:captcha:{key}` の `data.targets`)から座標を読んで検証、誤クリックは失敗、max_attempts(3)超過で key は消費・削除、key の一意性 |

### 新規テスト(1 ファイル、12 ケース)

`tests/AdminControllerTest.php`(著作権ヘッダ付き)、カバー:

- **BaseController::decodeId**(修正直後の 404 動作): encode/decode の往復が一致; 不正な hashid は `support\exception\NotFoundException` を code=404 でスロー; encodeIds は ID フィールドのみ書き換え
- **RoleController**: super_admin ロールの update は 403 を返す(実 DB データ)
- **PermissionController::buildTree**: 権限ツリーのネスト(2 階層)+ ノード id が全て hashid 化
- **ConfigController**: group/key/value 欠落時はバリデーション 422; 不正な hashid は 404
- **ExportController**: `admin_user` のエクスポート対象機微フィールドは phone/email/id_card(他テーブルは空); PDF HTML はタイトル/セル値を htmlspecialchars でエスケープ(XSS 対策)し、著作権声明を含む

### 既知の説明

- テストで構築する webman Request は生の HTTP メッセージ(buffer)として渡す(workerman の Request コンストラクタ引数は buffer で、method/uri だけでは POST ボディを解析できない)、AdminControllerTest のコメント参照
- キャプチャ正解クリックのケースは Redis から保存済みターゲットを読み取る; Redis が使えない場合は markTestSkipped になり、スイート結果に影響しない

## 未カバー/追加予定

- admin 各 model の Encryptable 暗号化/復号化、OperationLog/AdminPermission ミドルウェアと RBAC キャッシュ経路は依然単体テスト不足、API テストまたは次バッチでのカバーを推奨
- 外部サービス(ES/gRPC)に依存する service の経路は引き続きユニットレベルの stub 検証のみ、統合レベルは API テストでカバー
