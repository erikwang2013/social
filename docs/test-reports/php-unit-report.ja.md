# PHP ユニットテストレポート
**语言 / Languages:** [中文](php-unit-report.md) · [English](php-unit-report.en.md) · [한국어](php-unit-report.ko.md) · [Русский](php-unit-report.ru.md) · [Deutsch](php-unit-report.de.md) · [Français](php-unit-report.fr.md) · [Español](php-unit-report.es.md) · [Português](php-unit-report.pt.md) · [हिन्दी](php-unit-report.hi.md) · [العربية](php-unit-report.ar.md) · [বাংলা](php-unit-report.bn.md) · [Bahasa Indonesia](php-unit-report.id.md) · [日本語](php-unit-report.ja.md)

- 日付: 2026-08-27
- 実行: `vendor/bin/phpunit`(PHPUnit 10.5.64, PHP 8.3.7)
- 範囲: admin/(webman 管理バックエンド) + service/(webman メインサービス)

## 結果総括

| 項目 | テストケース | アサーション | 結果 |
|------|------|------|------|
| service | 136 | 348 | ✅ 全て合格(OK) |
| admin | 60 | 136 | ⚠️ 49 合格 / 4 エラー / 7 失敗 |

## service(全緑)

- 新規テストファイル(今回のバッチ): AuthMiddlewareTest、UserBriefTest、SearchSyncTest、ActionHandlerTest、JwtHelperTest、VoiceControllerTest、MonitorTest、ModelRelationTest など、既存 24 ファイルと合わせて全 136 ケース全て合格
- カバー対象モジュール: 認証/ミドルウェア/JWT、ユーザー、投稿、コメント、フォロー、通知、検索同期、IM、ルーム、通話(CallCenter/CallState)、音声、モデル関係、アクション処理(WS)

### 修正: テストスイートのランダムハング(重要)

- 現象: フル実行時にプロセスがランダムにフリーズ、単一ファイル/サブセット実行では合格
- 根本原因: `ActionHandlerTest::setUp` の `new Worker()` がインスタンスを `Worker::$workers` **静的レジストリ**に登録; 以降どの `CallCenter::start` も「Worker が存在する」と見て `Timer::add` を呼ぶ → `pcntl_alarm(1)` が SIGALRM タイマーを仕込み、プロセス終了時にハング
- 修正: setUp でレジストリをスナップショット、tearDown で復元(`ReflectionProperty` で `workers`/`pidMap` を書き戻し)
- 場所: `service/tests/ActionHandlerTest.php`

## admin(49/60、失敗は全て既存のプリセットテストで環境/設定の問題)

| テストケース | 失敗原因 | 分類 |
|------|----------|------|
| EnvConfigTest(4 失敗+1 エラー) | `admin/.env` が存在せず、getenv/dotenv のアサーションが無効 | テスト環境に .env がない |
| CaptchaTest(3 エラー+1 失敗+1 risky) | キャプチャが稼働中のサービス/Redis に依存、単体テスト環境は null を返す | 環境依存 |
| BackendEnhancementTest(2 失敗) | `app/middleware/Cors` の存在と admin_user の searchable を含むことをアサーションするが、現在の設定と不一致 | 設定アサーションの陳腐化 |

注: admin/tests は全て過去の既存ファイルで、今回のバッチでは admin の単体テストファイルは追加していない(注力は service)。

## 未カバー/追加予定

- admin 各モジュール(model/middleware/view)に単体テストなし
- 外部サービス(ES/gRPC)に依存する service の経路はユニットレベルの stub 検証のみ実施、統合レベルは API テストでのカバーを推奨
