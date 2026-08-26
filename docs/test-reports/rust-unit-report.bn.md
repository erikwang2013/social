# Rust Workspace ইউনিট টেস্ট রিপোর্ট
**语言 / Languages:** [中文](rust-unit-report.md) · [English](rust-unit-report.en.md) · [한국어](rust-unit-report.ko.md) · [Русский](rust-unit-report.ru.md) · [Deutsch](rust-unit-report.de.md) · [Français](rust-unit-report.fr.md) · [Español](rust-unit-report.es.md) · [Português](rust-unit-report.pt.md) · [हिन्दी](rust-unit-report.hi.md) · [العربية](rust-unit-report.ar.md) · [বাংলা](rust-unit-report.bn.md) · [Bahasa Indonesia](rust-unit-report.id.md) · [日本語](rust-unit-report.ja.md)

- তারিখ: 2026-08-27
- অবস্থান: `/home/wwwroot/social/infrastructure`
- কমান্ড: `cargo test --workspace` (ডিফল্ট features), পাশাপাশি feature-gated ব্যাকএন্ড যাচাই (tsdb/graph/search/kv)
- ফলাফল: **180 পাস / 0 ফেল** (179 ইউনিট+ইন্টিগ্রেশন টেস্ট + 1 doctest)

## সারাংশ

| crate | টেস্ট সংখ্যা | পাস | ফেল | আচ্ছাদিত মডিউল |
|-------|-------|------|------|----------|
| bee_cache | 8 | 8 | 0 | set/get/delete/expire, incr, ttl, MemoryCache |
| bee_cli | 23 | 23 | 0 | rust_type ম্যাপিং, find_bin_name, validate_name, bin আর্গুমেন্ট পার্সিং |
| bee_config | 14 | 14 | 0 | IniParser (কমেন্ট/ফাঁকা/সেকশন সুইচ), Config, ConfigSource, 6 ইন্টিগ্রেশন |
| bee_config_macro | 0 | — | — | ইন্টিগ্রেশন টেস্টের মাধ্যমে পরোক্ষভাবে আচ্ছাদিত |
| bee_graph | 15 | 15 | 0 | StubGraphDB: ট্রাভার্সাল দিক/গভীরতা/লেবেল, add/update/delete, ত্রুটি পথ, serde (feature ব্যাকএন্ড আরও 29) |
| bee_kv | 10 | 10 | 0 | get/incr/expire/mset, মেয়াদোত্তীর্ণ কী, ত্রুটি পথ |
| bee_logs | 4 | 4 | 0 | level_str সব স্তর, ফাইল আউটপুট, stdout/stderr আউটপুট |
| bee_orm | 19 | 19 | 0 | SelectBuilder (ইন্টিগ্রেশন): order/limit/offset/প্যারামিটার বাইন্ডিং/পুনঃব্যবহার/table_name/ত্রুটি display (lib-এ 0) |
| bee_orm_macro | 0 | — | — | ইন্টিগ্রেশন টেস্টের মাধ্যমে পরোক্ষভাবে আচ্ছাদিত |
| bee_router | 36 | 36 | 0 | context (params/text/html/abort), router (method/404/namespace), dispatch পাইপলাইন, সেশন পুনরুদ্ধার/স্থায়ীত্ব/মেয়াদোত্তীর্ণ কুকি |
| bee_rust | 2 | 2 | 0 | prelude এক্সপোর্ট, Result উপনাম |
| bee_search | 18 | 18 | 0 | MemoryEngine: index/delete/ওভাররাইট/পেজিনেশন/get/খালি কোয়েরি/serde (feature ব্যাকএন্ড আরও 20) |
| bee_session | 8 | 8 | 0 | set/get/delete, save/load/refresh, TTL floor, ত্রুটি পথ, UUID স্বতন্ত্রতা |
| bee_template | 6+1 | 7 | 0 | context! ম্যাক্রো, render, অনুপস্থিত টেমপ্লেট/ভেরিয়েবল ত্রুটি, খালি engine, অসসীম ফ্লোট (1 doctest সহ) |
| bee_tsdb | 10 | 10 | 0 | Query ফিল্টারিং (Neq/Regex/রেঞ্জ/AND), Point serde, enum debug (feature ব্যাকএন্ড আরও 22) |
| social_grpc | 5 | 5 | 0 | SearchService: index/search/delete রাউন্ডট্রিপ, অবৈধ JSON ফলব্যাক, খালি ইনডেক্স |
| hello_bee | 0 | — | — | উদাহরণ প্রোগ্রাম, কোনো টেস্ট নেই |

## অকভার তালিকা

- **bee_kv `redis` feature (RedisStore)**: লাইভ Redis সার্ভার প্রয়োজন, কভার করা হয়নি
- **hello_bee**: উদাহরণ প্রোগ্রাম, 0 টেস্ট
- **feature-gated ব্যাকএন্ড** (ডিফল্ট features-এ কম্পাইল হয় না): নিজ নিজ feature সংমিশ্রণে কম্পাইল ও টেস্ট পাস যাচাই করা হয়েছে (tsdb 22, graph 29, search 20, kv 10), কিন্তু es/opensearch/clickhouse, neo4j/nebulagraph/arangodb, influxdb/iotdb/questdb, redis-এর মতো প্রকৃত ব্যাকএন্ডে বাহ্যিক পরিষেবা দরকার — শুধু কম্পাইল-স্তরের যাচাই
- **bee_config_macro / bee_orm_macro**: proc-macro, নিজ নিজ ইন্টিগ্রেশন টেস্টের মাধ্যমে পরোক্ষভাবে আচ্ছাদিত, আলাদা ইউনিট টেস্ট নেই

## নথিভুক্ত প্রকৃত বাগ (লাইব্রেরি সোর্স অপরিবর্তিত)

1. `bee_tsdb/src/influxdb.rs:160-169` — `line_protocol` `&point.fields` (HashMap) সাজানো ছাড়াই ইটারেট করে অথচ tags সাজানো → একাধিক ফিল্ডে line protocol আউটপুট অনির্দিষ্ট
2. `bee_tsdb/src/iotdb.rs:37-42` — `write_batch` অ-পরমাণু (পয়েন্টে পয়েন্টে চলে এবং `?` শর্ট-সার্কিট), trait ডকের "atomically" দাবির সাথে অসামঞ্জস্যপূর্ণ
3. `bee_graph/src/neo4j.rs:106-109` — `add_edge` সর্বদা `VertexNotFound(edge.from)` ফেরায়, এমনকি অনুপস্থিত প্রান্তবিন্দু `to` হলেও
4. `bee_search` MemoryEngine `search` — gRPC স্তর থেকে পাঠানো from/size উপেক্ষা করে (পেজিনেশন নেই)
5. `social_grpc/src/search.rs:54` — `h.id.parse().unwrap_or(0)`: অ-সংখ্যামূলক id নীরবে 0 হয়ে যায়
6. `bee_template/src/lib.rs`-এর `context!` ম্যাক্রো ডক — দাবি করে NaN panic করে, অথচ serde_json ≥1.0.128 আসলে একে null হিসেবে সিরিয়ালাইজ করে (ডক পুরনো)
7. `bee_search/src/elasticsearch.rs:64` — bulk NDJSON index/id কাঁচাভাবে JSON-এ ঢোকায়; `"` থাকা id ভুল NDJSON তৈরি করে

## পরিবেশ নোট

- cargo `~/.cargo/bin`-এ আছে (PATH-এ নেই), `export PATH="$HOME/.cargo/bin:$PATH"` প্রয়োজন
- social_grpc-এর জন্য `protoc` দরকার: `apt-get download protobuf-compiler` + `dpkg-deb -x` দিয়ে `/tmp/protoc-local`-এ আনপ্যাক, `PROTOC=/tmp/protoc-local/usr/bin/protoc` (sudo দরকার নেই)
