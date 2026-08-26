# Rust Workspace ユニットテストレポート
**语言 / Languages:** [中文](rust-unit-report.md) · [English](rust-unit-report.en.md) · [한국어](rust-unit-report.ko.md) · [Русский](rust-unit-report.ru.md) · [Deutsch](rust-unit-report.de.md) · [Français](rust-unit-report.fr.md) · [Español](rust-unit-report.es.md) · [Português](rust-unit-report.pt.md) · [हिन्दी](rust-unit-report.hi.md) · [العربية](rust-unit-report.ar.md) · [বাংলা](rust-unit-report.bn.md) · [Bahasa Indonesia](rust-unit-report.id.md) · [日本語](rust-unit-report.ja.md)

- 日付: 2026-08-27
- 場所: `/home/wwwroot/social/infrastructure`
- コマンド: `cargo test --workspace`(デフォルト features)、加えて feature-gated バックエンド検証(tsdb/graph/search/kv)
- 結果: **180 合格 / 0 失敗**(ユニット+統合テスト 179 + doctest 1)

## サマリー

| crate | テスト数 | 合格 | 失敗 | カバー対象モジュール |
|-------|-------|------|------|----------|
| bee_cache | 8 | 8 | 0 | set/get/delete/expire、incr、ttl、MemoryCache |
| bee_cli | 23 | 23 | 0 | rust_type マッピング、find_bin_name、validate_name、bin 引数パース |
| bee_config | 14 | 14 | 0 | IniParser(コメント/空白/セクション切替)、Config、ConfigSource、integration 6 |
| bee_config_macro | 0 | — | — | integration tests 経由で間接カバー |
| bee_graph | 15 | 15 | 0 | StubGraphDB: 走査方向/深さ/ラベル、add/update/delete、エラーパス、serde(feature バックエンド別途 29) |
| bee_kv | 10 | 10 | 0 | get/incr/expire/mset、期限切れキー、エラーパス |
| bee_logs | 4 | 4 | 0 | level_str 全レベル、file output、stdout/stderr output |
| bee_orm | 19 | 19 | 0 | SelectBuilder(統合): order/limit/offset/パラメータバインド/再利用/table_name/エラー display(lib 内 0) |
| bee_orm_macro | 0 | — | — | integration tests 経由で間接カバー |
| bee_router | 36 | 36 | 0 | context(params/text/html/abort)、router(method/404/namespace)、dispatch パイプライン、セッション復元/永続化/期限切れクッキー |
| bee_rust | 2 | 2 | 0 | prelude エクスポート、Result エイリアス |
| bee_search | 18 | 18 | 0 | MemoryEngine: index/delete/上書き/ページネーション/get/空クエリ/serde(feature バックエンド別途 20) |
| bee_session | 8 | 8 | 0 | set/get/delete、save/load/refresh、TTL floor、エラーパス、UUID 一意性 |
| bee_template | 6+1 | 7 | 0 | context! マクロ、render、テンプレート/変数欠落エラー、空 engine、非有限浮動小数点(doctest 1 含む) |
| bee_tsdb | 10 | 10 | 0 | Query フィルタ(Neq/Regex/範囲/AND)、Point serde、enum debug(feature バックエンド別途 22) |
| social_grpc | 5 | 5 | 0 | SearchService: index/search/delete 往復、不正 JSON フォールバック、空インデックス |
| hello_bee | 0 | — | — | サンプルプログラム、テストなし |

## 未カバーリスト

- **bee_kv `redis` feature(RedisStore)**: 稼働中の Redis サーバーが必要、未カバー
- **hello_bee**: サンプルプログラム、0 テスト
- **feature-gated バックエンド**(デフォルト features ではコンパイルされない): 各 feature 組み合わせでコンパイルとテスト合格を検証済み(tsdb 22、graph 29、search 20、kv 10)。ただし es/opensearch/clickhouse、neo4j/nebulagraph/arangodb、influxdb/iotdb/questdb、redis などの実バックエンドは外部サービスが必要なため、コンパイルレベル検証のみ
- **bee_config_macro / bee_orm_macro**: proc-macro、各 integration tests で間接カバーされ、独立ユニットテストなし

## 記録された実バグ(ライブラリソース未修正)

1. `bee_tsdb/src/influxdb.rs:160-169` — `line_protocol` が `&point.fields`(HashMap)をソートせずイテレートする一方 tags はソート済み → 複数フィールド時に line protocol 出力が非決定的
2. `bee_tsdb/src/iotdb.rs:37-42` — `write_batch` が非アトミック(ポイント単位実行 + `?` ショートサーキット)、trait ドキュメントの「atomically」主張と不一致
3. `bee_graph/src/neo4j.rs:106-109` — `add_edge` が欠落エンドポイントが `to` の場合でも常に `VertexNotFound(edge.from)` を返す
4. `bee_search` MemoryEngine `search` — gRPC 層から渡される from/size を無視(ページネーションなし)
5. `social_grpc/src/search.rs:54` — `h.id.parse().unwrap_or(0)`: 非数値 id が黙って 0 になる
6. `bee_template/src/lib.rs` の `context!` マクロドキュメント — NaN は panic すると主張するが、実際は serde_json ≥1.0.128 が null にシリアライズ(ドキュメント陳腐化)
7. `bee_search/src/elasticsearch.rs:64` — bulk NDJSON が index/id を JSON に生挿入; id に `"` が含まれると不正な NDJSON になる

## 環境メモ

- cargo は `~/.cargo/bin` にある(PATH にない)、`export PATH="$HOME/.cargo/bin:$PATH"` が必要
- social_grpc は `protoc` が必要: `apt-get download protobuf-compiler` + `dpkg-deb -x` で `/tmp/protoc-local` に展開、`PROTOC=/tmp/protoc-local/usr/bin/protoc`(sudo 不要)
