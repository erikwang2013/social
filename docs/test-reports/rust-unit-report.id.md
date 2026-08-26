# Laporan Pengujian Unit Rust Workspace
**语言 / Languages:** [中文](rust-unit-report.md) · [English](rust-unit-report.en.md) · [한국어](rust-unit-report.ko.md) · [Русский](rust-unit-report.ru.md) · [Deutsch](rust-unit-report.de.md) · [Français](rust-unit-report.fr.md) · [Español](rust-unit-report.es.md) · [Português](rust-unit-report.pt.md) · [हिन्दी](rust-unit-report.hi.md) · [العربية](rust-unit-report.ar.md) · [বাংলা](rust-unit-report.bn.md) · [Bahasa Indonesia](rust-unit-report.id.md) · [日本語](rust-unit-report.ja.md)

- Tanggal: 2026-08-27
- Lokasi: `/home/wwwroot/social/infrastructure`
- Perintah: `cargo test --workspace` (fitur default), ditambah verifikasi backend dengan feature-gate (tsdb/graph/search/kv)
- Hasil: **180 lulus / 0 gagal** (179 pengujian unit+integrasi + 1 doctest)

## Ringkasan

| crate | Jumlah pengujian | Lulus | Gagal | Modul yang dicakup |
|-------|-------|------|------|----------|
| bee_cache | 8 | 8 | 0 | set/get/delete/expire, incr, ttl, MemoryCache |
| bee_cli | 23 | 23 | 0 | pemetaan rust_type, find_bin_name, validate_name, penguraian argumen bin |
| bee_config | 14 | 14 | 0 | IniParser (komentar/spasi/perpindahan bagian), Config, ConfigSource, 6 integrasi |
| bee_config_macro | 0 | — | — | tercakup tidak langsung melalui pengujian integrasi |
| bee_graph | 15 | 15 | 0 | StubGraphDB: arah/kedalaman/label traversal, add/update/delete, jalur kesalahan, serde (backend fitur +29) |
| bee_kv | 10 | 10 | 0 | get/incr/expire/mset, kunci kedaluwarsa, jalur kesalahan |
| bee_logs | 4 | 4 | 0 | level_str semua tingkat, keluaran file, keluaran stdout/stderr |
| bee_orm | 19 | 19 | 0 | SelectBuilder (integrasi): order/limit/offset/ikatan parameter/penggunaan ulang/table_name/tampilan kesalahan (0 di lib) |
| bee_orm_macro | 0 | — | — | tercakup tidak langsung melalui pengujian integrasi |
| bee_router | 36 | 36 | 0 | context (params/text/html/abort), router (method/404/namespace), pipeline dispatch, pemulihan/persistensi/kuki kedaluwarsa sesi |
| bee_rust | 2 | 2 | 0 | ekspor prelude, alias Result |
| bee_search | 18 | 18 | 0 | MemoryEngine: index/delete/penimpaan/paginasi/get/kueri kosong/serde (backend fitur +20) |
| bee_session | 8 | 8 | 0 | set/get/delete, save/load/refresh, TTL floor, jalur kesalahan, keunikan UUID |
| bee_template | 6+1 | 7 | 0 | makro context!, render, kesalahan templat/variabel hilang, engine kosong, float tak hingga (termasuk 1 doctest) |
| bee_tsdb | 10 | 10 | 0 | pemfilteran Query (Neq/Regex/rentang/AND), Point serde, enum debug (backend fitur +22) |
| social_grpc | 5 | 5 | 0 | SearchService: perjalanan bolak-balik index/search/delete, fallback JSON tidak valid, indeks kosong |
| hello_bee | 0 | — | — | program contoh, tanpa pengujian |

## Daftar belum tercakup

- **Fitur `redis` bee_kv (RedisStore)**: memerlukan server Redis aktif, belum tercakup
- **hello_bee**: program contoh, 0 pengujian
- **Backend dengan feature-gate** (tidak dikompilasi dengan fitur default): terverifikasi dapat dikompilasi dan lulus pengujian pada kombinasi fiturnya masing-masing (tsdb 22, graph 29, search 20, kv 10), tetapi backend sungguhan seperti es/opensearch/clickhouse, neo4j/nebulagraph/arangodb, influxdb/iotdb/questdb, redis memerlukan layanan eksternal — hanya verifikasi tingkat kompilasi
- **bee_config_macro / bee_orm_macro**: proc-macro, tercakup tidak langsung melalui pengujian integrasinya, tanpa pengujian unit mandiri

## Bug nyata yang didokumentasikan (sumber pustaka tidak diubah)

1. `bee_tsdb/src/influxdb.rs:160-169` — `line_protocol` mengiterasi `&point.fields` (HashMap) tanpa diurutkan, padahal tags diurutkan → keluaran line protocol tidak deterministik saat ada banyak field
2. `bee_tsdb/src/iotdb.rs:37-42` — `write_batch` tidak atomik (dieksekusi per titik dengan short-circuit `?`), tidak konsisten dengan klaim "atomically" pada dokumentasi trait
3. `bee_graph/src/neo4j.rs:106-109` — `add_edge` selalu mengembalikan `VertexNotFound(edge.from)`, bahkan ketika titik ujung yang hilang adalah `to`
4. `bee_search` MemoryEngine `search` — mengabaikan from/size yang dikirim dari lapisan gRPC (tanpa paginasi)
5. `social_grpc/src/search.rs:54` — `h.id.parse().unwrap_or(0)`: id non-numerik berubah diam-diam menjadi 0
6. Dokumentasi makro `context!` di `bee_template/src/lib.rs` — mengklaim NaN akan panic, padahal serde_json ≥1.0.128 justru men-serialize-nya sebagai null (dokumentasi usang)
7. `bee_search/src/elasticsearch.rs:64` — NDJSON bulk menyisipkan index/id mentah ke dalam JSON; id yang mengandung `"` menghasilkan NDJSON rusak

## Catatan lingkungan

- cargo berada di `~/.cargo/bin` (tidak ada di PATH), perlu `export PATH="$HOME/.cargo/bin:$PATH"`
- social_grpc memerlukan `protoc`: diperoleh via `apt-get download protobuf-compiler` + `dpkg-deb -x` diekstrak ke `/tmp/protoc-local`, `PROTOC=/tmp/protoc-local/usr/bin/protoc` (tanpa sudo)
