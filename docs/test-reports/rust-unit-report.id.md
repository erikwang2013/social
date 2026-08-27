# Laporan Pengujian Unit Rust Workspace
**语言 / Languages:** [中文](rust-unit-report.md) · [English](rust-unit-report.en.md) · [한국어](rust-unit-report.ko.md) · [Русский](rust-unit-report.ru.md) · [Deutsch](rust-unit-report.de.md) · [Français](rust-unit-report.fr.md) · [Español](rust-unit-report.es.md) · [Português](rust-unit-report.pt.md) · [हिन्दी](rust-unit-report.hi.md) · [العربية](rust-unit-report.ar.md) · [বাংলা](rust-unit-report.bn.md) · [Bahasa Indonesia](rust-unit-report.id.md) · [日本語](rust-unit-report.ja.md)

- Tanggal: 2026-08-27
- Lokasi: `/home/wwwroot/social/infrastructure`
- Perintah: `cargo test --workspace` (fitur default), ditambah verifikasi backend dengan feature-gate (tsdb/graph/search/kv)
- Hasil: **183 lulus / 0 gagal** (178 unit+integrasi + 5 inline fitur + 1 doctest, dst.; workspace default menyertakan 6 kasus bee_search karena social_grpc bergantung pada fitur `elasticsearch`-nya)

## Ringkasan

| crate | Jumlah pengujian | Lulus | Gagal | Modul yang dicakup |
|-------|-------|------|------|----------|
| bee_cache | 8 | 8 | 0 | set/get/delete/expire, incr, ttl, MemoryCache |
| bee_cli | 14 | 14 | 0 | pemetaan rust_type, find_bin_name, validate_name, penguraian argumen bin |
| bee_config | 8 + 6 integrasi | 14 | 0 | IniParser (komentar/spasi/perpindahan bagian), Config, ConfigSource, kesalahan muat ulang |
| bee_config_macro | 0 | — | — | tercakup tidak langsung melalui pengujian integrasi |
| bee_graph | 15 | 15 | 0 | StubGraphDB: arah/kedalaman/label traversal, add/update/delete, jalur kesalahan, serde (fitur neo4j +5) |
| bee_kv | 10 | 10 | 0 | get/incr/expire/mset, kunci kedaluwarsa, jalur kesalahan (fitur redis +3 kasus Redis asli) |
| bee_logs | 4 | 4 | 0 | level_str semua tingkat, keluaran file, keluaran stdout/stderr |
| bee_orm | 19 integrasi | 19 | 0 | SelectBuilder: order/limit/offset/ikatan parameter/penggunaan ulang/table_name/Display kesalahan (0 di lib) |
| bee_orm_macro | 0 | — | — | tercakup tidak langsung melalui pengujian integrasi |
| bee_router | 36 | 36 | 0 | context (params/text/html/abort), router (method/404/namespace), pipeline dispatch, pemulihan/persistensi/kuki kedaluwarsa sesi |
| bee_rust | 2 (bin +9) | 11 | 0 | ekspor prelude, alias Result, penguraian argumen CLI |
| bee_search | 20 (termasuk 6 inline fitur) | 20 | 0 | MemoryEngine: index/delete/penimpaan/paginasi/get/kueri kosong/serde; driver Elasticsearch: get/search/bulk/aggregate, escape NDJSON |
| bee_session | 8 | 8 | 0 | set/get/delete, save/load/refresh, TTL floor, jalur kesalahan, keunikan UUID |
| bee_template | 6 + 1 doctest | 7 | 0 | makro context!, render, kesalahan templat/variabel hilang, engine kosong, float tak hingga (1 doctest) |
| bee_tsdb | 11 | 11 | 0 | penulisan/penulisan batch, batas kueri rentang, pemfilteran Eq/Neq/Regex/AND, Point serde, CQ (fitur influxdb +5, termasuk determinisme line protocol) |
| social_grpc | 6 | 6 | 0 | SearchService: perjalanan bolak-balik index/search/delete, fallback JSON tidak valid, indeks kosong, kesalahan id non-numerik |
| hello_bee | 0 | — | — | program contoh, tanpa pengujian |

## Defek nyata yang diperbaiki pada ronde ini (fix minimal + uji regresi)

1. **bee_search MemoryEngine `search` mengabaikan paginasi** (`crates/bee_search/src/lib.rs`) — `from`/`size` yang dikirim dari lapisan gRPC dibuang, selalu mengembalikan semua hasil. Fix: membaca `from`/`size` dari JSON kueri dan menerapkan skip/truncate pada hits, `total` tetap menghitung semua kecocokan. Baru: `test_search_honors_from_size_pagination` (tahan terhadap iterasi HashMap yang tidak berurutan: membandingkan dengan irisan hasil lengkap engine itu sendiri).
2. **social_grpc `search` mengubah id non-numerik menjadi 0 secara diam-diam** (`crates/social_grpc/src/search.rs:53-60`) — `h.id.parse().unwrap_or(0)` mengembalikan id dokumen non-numerik sebagai 0 tanpa peringatan. Fix: kegagalan parse mengembalikan `Status::invalid_argument`. Baru: `non_numeric_hit_id_becomes_invalid_argument`.
3. **bee_tsdb field line protocol InfluxDB tidak berurutan** (`crates/bee_tsdb/src/influxdb.rs:160-170`) — tags diurutkan tetapi fields tidak; keluaran non-deterministik dengan banyak field. Fix: urutkan fields berdasarkan kunci. Baru: `line_protocol_is_deterministic_across_field_insertion_order` (urutan penyisipan berbeda menghasilkan baris identik, diurutkan menurut a,b).
4. **bee_search Elasticsearch bulk NDJSON tidak meng-escape id** (`crates/bee_search/src/elasticsearch.rs`) — index/id diinterpolasi langsung ke string; id yang mengandung `"` menghasilkan NDJSON rusak. Fix: `bulk_ndjson()` diekstrak, baris aksi diserialisasi melalui serde_json. Baru: `bulk_ndjson_escapes_ids_and_stays_parseable`.
5. **bee_graph Neo4j `add_edge` selalu melaporkan ujung `from`** (`crates/bee_graph/src/neo4j.rs:107-116`) — ketika ujung yang hilang adalah `to`, pesan kesalahan menyesatkan. Fix: ketika `nodes-matched < 2`, gunakan `get_vertex` untuk menentukan ujung yang benar-benar hilang sebelum melaporkan. Baru: `add_edge_reports_the_missing_endpoint` (layanan HTTP tiruan memverifikasi bahwa `to1` dilaporkan, bukan `from1`).
6. **bee_template dokumen `context!` tidak konsisten dengan perilaku** (`crates/bee_template/src/lib.rs`) — dokumen mengklaim float tak hingga memicu panic, tetapi serde_json justru men-serialize-nya sebagai `null` (dibuktikan oleh pengujian yang ada). Fix: dokumen diperbarui.

## Cakupan baru

- **Uji integrasi bee_kv RedisStore dengan Redis asli** (`crates/bee_kv/src/redis_store.rs`, fitur `redis`) — mengisi celah cakupan yang secara eksplisit disebut dalam laporan sebelumnya. Redis lokal tersedia (127.0.0.1:6379); 3 kasus: perjalanan bolak-balik set/get/del, incr/expire, mset/mget; kunci berprefiks pid+nano detik, kasus membersihkan dirinya sendiri. Jika Redis tidak tersedia, kasus dilewati dengan elegan (mencetak SKIP dan lolos).

## Celah cakupan (tidak berubah)

- **bee_tsdb IoTDB `write_batch` tidak atomik** (`crates/bee_tsdb/src/iotdb.rs`) — penulisan per titik dengan short-circuit `?`, tidak konsisten dengan «atomically» pada dokumen trait. Perbaikan memerlukan dukungan transaksi backend; tidak ada instans IoTDB lokal, jadi ronde ini tidak ada perubahan membabi buta; dicatat sebagai keterbatasan yang diketahui.
- **Backend eksternal** (es/opensearch/clickhouse, neo4j/nebulagraph/arangodb, influxdb/iotdb/questdb, memcached) — driver utama (elasticsearch, neo4j, influxdb: jalur penulisan/kueri/CQ) tercakup dengan layanan HTTP tiruan lokal; sisanya tanpa layanan lokal hanya verifikasi tingkat kompilasi.
- **MySQL**: lokal 127.0.0.1:3306 tersedia (root, tanpa kata sandi), tetapi tidak ada crate di workspace yang memperkenalkan driver MySQL — bee_orm adalah pembangun SQL independen driver, kasus QuerySet tidak bergantung pada basis data nyata; dependensi driver tidak diperlukan dan tidak boleh ditambahkan.
- **bee_config_macro / bee_orm_macro**: proc-macro, tercakup tidak langsung melalui pengujian integrasinya, tanpa pengujian unit mandiri.

## Pemeriksaan kualitas

- `cargo fmt --check`: lulus (ronde ini `cargo fmt` dijalankan di seluruh workspace, memperbaiki 20+ penyimpangan format yang ditinggalkan sesi sebelumnya).
- `cargo clippy --workspace --all-targets`: nol peringatan pada kode baru; 3 sisanya adalah peringatan yang sudah ada sebelumnya (bee_config `get("default").is_none()`, bee_rust `unwrap()` pada Ok, bee_search MemoryEngine tanpa impl Default), di luar cakupan ronde ini.

## Catatan lingkungan

- cargo berada di `~/.cargo/bin` (tidak di PATH secara default), perlu `export PATH="$HOME/.cargo/bin:$PATH"`.
- `protoc` kini tersedia (`/home/erik/.local/bin/protoc`).
- social_grpc berjalan di latar belakang (port 50051); laporan ini hanya menjalankan `cargo test`, bukan `cargo run` padanya.
- Redis (6379) dan MySQL (3306) tersedia lokal; daftar kasus fitur:
  - `cargo test -p bee_tsdb --features influxdb` → 16 lulus
  - `cargo test -p bee_search --features elasticsearch` → 20 lulus
  - `cargo test -p bee_graph --features neo4j` → 20 lulus
  - `cargo test -p bee_kv --features redis` → 13 lulus (termasuk 3 kasus Redis asli)
