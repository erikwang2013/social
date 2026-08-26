# 変更履歴

**语言 / Languages:** [中文](CHANGELOG.md) · [English](CHANGELOG.en.md) · [한국어](CHANGELOG.ko.md) · [Русский](CHANGELOG.ru.md) · [Deutsch](CHANGELOG.de.md) · [Français](CHANGELOG.fr.md) · [Español](CHANGELOG.es.md) · [Português](CHANGELOG.pt.md) · [हिन्दी](CHANGELOG.hi.md) · [العربية](CHANGELOG.ar.md) · [বাংলা](CHANGELOG.bn.md) · [Bahasa Indonesia](CHANGELOG.id.md) · [日本語](CHANGELOG.ja.md)

## [1.0.6] — 2026-08-07

### 追加
- `bee_cli` の実装: `new`（プロジェクトのスキャフォールディング）、`generate controller/model`、`--watch` ホットリロード付き `run`、`pack`（リリースビルド + `dist/` へのコピー）
- スキャフォールディングとコード生成の CLI ユニットテスト（新規 7 件）

### 修正
- `bee_rust::init()` が `logs` フィーチャーの背後にゲートされるように — 削減フィーチャービルド（例: `--no-default-features --features kv`）が再びコンパイル可能に
- `bee_kv::InMemoryKvStore::exists` の Clippy `unnecessary_map_or` リント
- `rustfmt.toml` から stable で黙って無視されていた nightly 専用オプションを削除。ワークスペースが `cargo fmt --all --check` に合格
- `bee_cli` バイナリに `doc = false` を設定し、`bee_rust` との rustdoc 出力ファイル名の衝突を解消
- `hello` サンプルのポートが `PORT` 環境変数で設定可能に

### 変更
- `bee-rust migrate` が "not implemented" を報告して非ゼロで終了（予定）
- README / README.en を実際の CLI 動作に合わせて更新

## [1.0.4] — 2026-07-29

### 追加
- `security-rust` によるセキュリティ攻撃検出フィルター（27 の検出器）
- XSS・SQL インジェクション・コマンドインジェクション・パストラバーサルをカバーする `SecurityFilter`
- `bee_rust` と `bee_router` の `security` フィーチャーフラグ

### 変更
- セキュリティ機能のドキュメントを README に追記
- 決済サポート（WeChat Pay / Alipay）のセクションを README に追記

### 修正
- Rust 2024 エディション向け `bee_template` の Tera raw 識別子構文

## [1.0.3] — 2026-07-29

### 追加
- 13 クレートからなる初期ワークスペース構成
- `Controller` トレイトと `Router` による MVC ルーティング
- `QuerySet` ビルダーと `Model` derive マクロによる ORM
- Redis と Memory バックエンドを備えた KV/Cache トレイト抽象化
- Memory/Redis バックエンドによるセッション管理
- INI/YAML/ENV 対応とホットリロードによる設定管理
- Tera によるテンプレートレンダリング
- tracing 統合によるロギング
- CLI スキャフォールディングとコード生成
- 検索・グラフ・時系列エンジンのトレイトスタブ（ドライバは計画中）

[1.0.4]: https://github.com/erikwang2013/bee-rust/compare/v1.0.3...v1.0.4
[1.0.3]: https://github.com/erikwang2013/bee-rust/releases/tag/v1.0.3
