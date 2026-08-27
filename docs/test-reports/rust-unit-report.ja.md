# Rust Workspace ユニットテストレポート
**语言 / Languages:** [中文](rust-unit-report.md) · [English](rust-unit-report.en.md) · [한국어](rust-unit-report.ko.md) · [Русский](rust-unit-report.ru.md) · [Deutsch](rust-unit-report.de.md) · [Français](rust-unit-report.fr.md) · [Español](rust-unit-report.es.md) · [Português](rust-unit-report.pt.md) · [हिन्दी](rust-unit-report.hi.md) · [العربية](rust-unit-report.ar.md) · [বাংলা](rust-unit-report.bn.md) · [Bahasa Indonesia](rust-unit-report.id.md) · [日本語](rust-unit-report.ja.md)

- 日付: 2026-08-27
- 場所: `/home/wwwroot/social/infrastructure`
- コマンド: `cargo test --workspace`(デフォルト features)、加えて feature-gated バックエンド検証(tsdb/graph/search/kv)
- 結果: **183 合格 / 0 失敗**(ユニット+統合 178 + feature インライン 5 + doctest 1 など; デフォルト workspace は bee_search の 6 ケースを含む。social_grpc がその `elasticsearch` feature に依存するため)

## サマリー

| crate | テスト数 | 合格 | 失敗 | カバー対象モジュール |
|-------|-------|------|------|----------|
| bee_cache | 8 | 8 | 0 | set/get/delete/expire、incr、ttl、MemoryCache |
| bee_cli | 14 | 14 | 0 | rust_type マッピング、find_bin_name、validate_name、bin 引数パース |
| bee_config | 8 + 6 統合 | 14 | 0 | IniParser(コメント/空白/セクション切替)、Config、ConfigSource、リロードエラー |
| bee_config_macro | 0 | — | — | integration tests 経由で間接カバー |
| bee_graph | 15 | 15 | 0 | StubGraphDB: 走査方向/深さ/ラベル、add/update/delete、エラーパス、serde(feature neo4j さらに 5) |
| bee_kv | 10 | 10 | 0 | get/incr/expire/mset、期限切れキー、エラーパス(feature redis さらに実 Redis 3 ケース) |
| bee_logs | 4 | 4 | 0 | level_str 全レベル、file output、stdout/stderr output |
| bee_orm | 19 統合 | 19 | 0 | SelectBuilder: order/limit/offset/パラメータバインド/再利用/table_name/エラー Display(lib 内 0) |
| bee_orm_macro | 0 | — | — | integration tests 経由で間接カバー |
| bee_router | 36 | 36 | 0 | context(params/text/html/abort)、router(method/404/namespace)、dispatch パイプライン、セッション復元/永続化/期限切れクッキー |
| bee_rust | 2(bin さらに 9) | 11 | 0 | prelude エクスポート、Result エイリアス、CLI 引数パース |
| bee_search | 20(うち feature インライン 6) | 20 | 0 | MemoryEngine: index/delete/上書き/ページネーション/get/空クエリ/serde; Elasticsearch ドライバ: get/search/bulk/aggregate、NDJSON エスケープ |
| bee_session | 8 | 8 | 0 | set/get/delete、save/load/refresh、TTL floor、エラーパス、UUID 一意性 |
| bee_template | 6 + 1 doctest | 7 | 0 | context! マクロ、render、テンプレート/変数欠落エラー、空 engine、非有限浮動小数点(1 doctest) |
| bee_tsdb | 11 | 11 | 0 | 書き込み/バッチ書き込み、範囲クエリ境界、Eq/Neq/Regex/AND フィルタリング、Point serde、CQ(feature influxdb さらに 5、line protocol 決定性含む) |
| social_grpc | 6 | 6 | 0 | SearchService: index/search/delete 往復、不正 JSON フォールバック、空インデックス、非数値 id エラー |
| hello_bee | 0 | — | — | サンプルプログラム、テストなし |

## 今回のラウンドで修正した実バグ(最小修正 + 回帰テスト)

1. **bee_search MemoryEngine `search` がページネーションを無視** (`crates/bee_search/src/lib.rs`) — gRPC 層から渡された `from`/`size` が破棄され、常に全ヒットを返していた。修正: クエリ JSON から `from`/`size` を読み取り hits に skip/truncate を適用、`total` は全マッチ数をカウントしたまま。新規: `test_search_honors_from_size_pagination`(順序不定な HashMap イテレーションに強い: エンジン自身の全結果スライスと比較)。
2. **social_grpc `search` が非数値 id を黙って 0 にする** (`crates/social_grpc/src/search.rs:53-60`) — `h.id.parse().unwrap_or(0)` が非数値ドキュメント id を黙って 0 として返していた。修正: parse 失敗で `Status::invalid_argument` を返す。新規: `non_numeric_hit_id_becomes_invalid_argument`。
3. **bee_tsdb InfluxDB line protocol の fields が順不同** (`crates/bee_tsdb/src/influxdb.rs:160-170`) — tags はソート済みだが fields はされておらず、複数フィールド時に出力が非決定的。修正: fields をキーでソート。新規: `line_protocol_is_deterministic_across_field_insertion_order`(異なる挿入順でも同一の行、a,b 順)。
4. **bee_search Elasticsearch bulk NDJSON が id をエスケープしていない** (`crates/bee_search/src/elasticsearch.rs`) — index/id を生文字列で補間; `"` を含む id で不正な NDJSON になっていた。修正: `bulk_ndjson()` を抽出し、アクション行を serde_json でシリアライズ。新規: `bulk_ndjson_escapes_ids_and_stays_parseable`。
5. **bee_graph Neo4j `add_edge` のエラー端点が常に `from`** (`crates/bee_graph/src/neo4j.rs:107-116`) — 欠落端点が `to` の場合に誤解を招くエラーメッセージだった。修正: `nodes-matched < 2` のとき `get_vertex` で実際に欠落している端点を判定してから報告。新規: `add_edge_reports_the_missing_endpoint`(モック HTTP サービスが `from1` ではなく `to1` を報告することを検証)。
6. **bee_template `context!` ドキュメントが挙動と不一致** (`crates/bee_template/src/lib.rs`) — 非有限浮動小数点は panic するとドキュメントにあったが、実際は serde_json が `null` としてシリアライズ(既存テストで証明済み)。修正: ドキュメント更新。

## 新規カバレッジ

- **実 Redis による bee_kv RedisStore 統合テスト** (`crates/bee_kv/src/redis_store.rs`、feature `redis`) — 前回レポートで明示的に挙げたカバレッジギャップを解消。ローカル Redis 利用可能(127.0.0.1:6379); 3 ケース: set/get/del 往復、incr/expire、mset/mget; キーは pid+ナノ秒プレフィックス付き、ケースは自分で後始末。Redis が使えない場合は SKIP を表示して通す(優雅にスキップ)。

## カバレッジギャップ(現状維持)

- **bee_tsdb IoTDB `write_batch` が非アトミック** (`crates/bee_tsdb/src/iotdb.rs`) — ポイント単位の `?` ショートサーキット書き込み、trait ドキュメントの「atomically」と不一致。修正にはバックエンドのトランザクションサポートが必要; ローカル IoTDB インスタンスがないため今回のラウンドでは盲目的な変更なし; 既知の制限として記載。
- **外部バックエンド**(es/opensearch/clickhouse、neo4j/nebulagraph/arangodb、influxdb/iotdb/questdb、memcached) — 主要ドライバ(elasticsearch、neo4j、influxdb の書き込み/クエリ/CQ パス)はローカルモック HTTP サービスでカバー; ローカルサービスがない残りはコンパイルレベルの検証のみ。
- **MySQL**: ローカル 127.0.0.1:3306 利用可能(root、パスワードなし)だが、workspace のどの crate も MySQL ドライバを導入していない — bee_orm はドライバ非依存の SQL ビルダーで、QuerySet ケースは実 DB に依存しない; ドライバ依存は不要であり追加すべきでない。
- **bee_config_macro / bee_orm_macro**: proc-macro、各 integration tests で間接カバーされ、独立ユニットテストなし。

## 品質チェック

- `cargo fmt --check`: 合格(今回のラウンドで workspace 全体に `cargo fmt` を実行し、以前のセッションが残した 20+ のフォーマット逸脱を修正)。
- `cargo clippy --workspace --all-targets`: 新規コードに警告ゼロ; 残り 3 件は既存警告(bee_config `get("default").is_none()`、bee_rust の Ok に対する `unwrap()`、bee_search MemoryEngine の Default 実装なし)、今回のラウンドの対象外。

## 環境メモ

- cargo は `~/.cargo/bin` にある(デフォルトでは PATH にない)、`export PATH="$HOME/.cargo/bin:$PATH"` が必要。
- `protoc` は利用可能(`/home/erik/.local/bin/protoc`)。
- social_grpc はバックグラウンドで稼働中(ポート 50051); このレポートは `cargo test` のみ実行し、`cargo run` はしていない。
- Redis (6379) と MySQL (3306) がローカルで利用可能; feature ケース一覧:
  - `cargo test -p bee_tsdb --features influxdb` → 16 合格
  - `cargo test -p bee_search --features elasticsearch` → 20 合格
  - `cargo test -p bee_graph --features neo4j` → 20 合格
  - `cargo test -p bee_kv --features redis` → 13 合格(実 Redis 3 ケース含む)
