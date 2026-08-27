# Rust Workspace ইউনিট টেস্ট রিপোর্ট
**语言 / Languages:** [中文](rust-unit-report.md) · [English](rust-unit-report.en.md) · [한국어](rust-unit-report.ko.md) · [Русский](rust-unit-report.ru.md) · [Deutsch](rust-unit-report.de.md) · [Français](rust-unit-report.fr.md) · [Español](rust-unit-report.es.md) · [Português](rust-unit-report.pt.md) · [हिन्दी](rust-unit-report.hi.md) · [العربية](rust-unit-report.ar.md) · [বাংলা](rust-unit-report.bn.md) · [Bahasa Indonesia](rust-unit-report.id.md) · [日本語](rust-unit-report.ja.md)

- তারিখ: 2026-08-27
- অবস্থান: `/home/wwwroot/social/infrastructure`
- কমান্ড: `cargo test --workspace` (ডিফল্ট features), পাশাপাশি feature-gated ব্যাকএন্ড যাচাই (tsdb/graph/search/kv)
- ফলাফল: **183 পাস / 0 ফেল** (178 ইউনিট+ইন্টিগ্রেশন + 5 feature-ইনলাইন + 1 doctest, ইত্যাদি; ডিফল্ট workspace-এ bee_search-এর 6টি কেস অন্তর্ভুক্ত কারণ social_grpc তার `elasticsearch` feature-এর উপর নির্ভরশীল)

## সারাংশ

| crate | টেস্ট সংখ্যা | পাস | ফেল | আচ্ছাদিত মডিউল |
|-------|-------|------|------|----------|
| bee_cache | 8 | 8 | 0 | set/get/delete/expire, incr, ttl, MemoryCache |
| bee_cli | 14 | 14 | 0 | rust_type ম্যাপিং, find_bin_name, validate_name, bin আর্গুমেন্ট পার্সিং |
| bee_config | 8 + 6 ইন্টিগ্রেশন | 14 | 0 | IniParser (কমেন্ট/ফাঁকা/সেকশন সুইচ), Config, ConfigSource, রিলোড ত্রুটি |
| bee_config_macro | 0 | — | — | ইন্টিগ্রেশন টেস্টের মাধ্যমে পরোক্ষভাবে আচ্ছাদিত |
| bee_graph | 15 | 15 | 0 | StubGraphDB: ট্রাভার্সাল দিক/গভীরতা/লেবেল, add/update/delete, ত্রুটি পথ, serde (feature neo4j আরও 5) |
| bee_kv | 10 | 10 | 0 | get/incr/expire/mset, মেয়াদোত্তীর্ণ কী, ত্রুটি পথ (feature redis আরও 3 প্রকৃত-Redis কেস) |
| bee_logs | 4 | 4 | 0 | level_str সব স্তর, ফাইল আউটপুট, stdout/stderr আউটপুট |
| bee_orm | 19 ইন্টিগ্রেশন | 19 | 0 | SelectBuilder: order/limit/offset/প্যারামিটার বাইন্ডিং/পুনঃব্যবহার/table_name/ত্রুটি Display (lib-এ 0) |
| bee_orm_macro | 0 | — | — | ইন্টিগ্রেশন টেস্টের মাধ্যমে পরোক্ষভাবে আচ্ছাদিত |
| bee_router | 36 | 36 | 0 | context (params/text/html/abort), router (method/404/namespace), dispatch পাইপলাইন, সেশন পুনরুদ্ধার/স্থায়ীত্ব/মেয়াদোত্তীর্ণ কুকি |
| bee_rust | 2 (bin আরও 9) | 11 | 0 | prelude এক্সপোর্ট, Result উপনাম, CLI আর্গুমেন্ট পার্সিং |
| bee_search | 20 (6 feature-ইনলাইন সহ) | 20 | 0 | MemoryEngine: index/delete/ওভাররাইট/পেজিনেশন/get/খালি কোয়েরি/serde; Elasticsearch ড্রাইভার: get/search/bulk/aggregate, NDJSON এস্কেপিং |
| bee_session | 8 | 8 | 0 | set/get/delete, save/load/refresh, TTL floor, ত্রুটি পথ, UUID স্বতন্ত্রতা |
| bee_template | 6 + 1 doctest | 7 | 0 | context! ম্যাক্রো, render, অনুপস্থিত টেমপ্লেট/ভেরিয়েবল ত্রুটি, খালি engine, অসসীম ফ্লোট (1 doctest) |
| bee_tsdb | 11 | 11 | 0 | লেখা/ব্যাচ লেখা, রেঞ্জ কোয়েরি সীমা, Eq/Neq/Regex/AND ফিল্টারিং, Point serde, CQ (feature influxdb আরও 5, line protocol নিয়ন্ত্রণযোগ্যতা সহ) |
| social_grpc | 6 | 6 | 0 | SearchService: index/search/delete রাউন্ডট্রিপ, অবৈধ JSON ফলব্যাক, খালি ইনডেক্স, অ-সংখ্যামূলক id ত্রুটি |
| hello_bee | 0 | — | — | উদাহরণ প্রোগ্রাম, কোনো টেস্ট নেই |

## এই রাউন্ডে সংশোধিত প্রকৃত ত্রুটি (সর্বনিম্ন ফিক্স + রিগ্রেশন টেস্ট)

1. **bee_search MemoryEngine `search` পেজিনেশন উপেক্ষা করে** (`crates/bee_search/src/lib.rs`) — gRPC স্তর থেকে পাঠানো `from`/`size` বাতিল হয়ে যেত, সবসময় সব হিট ফেরত আসত। ফিক্স: কোয়েরি JSON থেকে `from`/`size` পড়ে hits-এ skip/truncate প্রয়োগ করে, `total` সব ম্যাচ গণনা করে চলেছে। নতুন: `test_search_honors_from_size_pagination` (অসংগঠিত HashMap ইটারেশনের বিরুদ্ধে শক্ত: ইঞ্জিনের নিজের পূর্ণ ফলাফলের স্লাইসের সাথে তুলনা)।
2. **social_grpc `search` অ-সংখ্যামূলক id নীরবে 0 বানায়** (`crates/social_grpc/src/search.rs:53-60`) — `h.id.parse().unwrap_or(0)` অ-সংখ্যামূলক ডকুমেন্ট id নীরবে 0 হিসেবে ফেরাচ্ছিল। ফিক্স: পার্স ব্যর্থতা `Status::invalid_argument` ফেরায়। নতুন: `non_numeric_hit_id_becomes_invalid_argument`।
3. **bee_tsdb InfluxDB line protocol ফিল্ড ক্রমবিহীন** (`crates/bee_tsdb/src/influxdb.rs:160-170`) — tags সাজানো কিন্তু fields নয়; একাধিক ফিল্ডে আউটপুট অনির্দিষ্ট। ফিক্স: fields কী অনুযায়ী সাজানো। নতুন: `line_protocol_is_deterministic_across_field_insertion_order` (ভিন্ন ভিন্ন সন্নিবেশ ক্রম অভিন্ন লাইন তৈরি করে, a,b ক্রমে)।
4. **bee_search Elasticsearch bulk NDJSON id এস্কেপ করে না** (`crates/bee_search/src/elasticsearch.rs`) — index/id সরাসরি স্ট্রিংয়ে ঢোকানো হত; `"` থাকা id ভুল NDJSON তৈরি করত। ফিক্স: `bulk_ndjson()` বের করা হয়েছে, অ্যাকশন লাইন serde_json দিয়ে সিরিয়ালাইজ হয়। নতুন: `bulk_ndjson_escapes_ids_and_stays_parseable`।
5. **bee_graph Neo4j `add_edge` ত্রুটি প্রান্তবিন্দু সবসময় `from`** (`crates/bee_graph/src/neo4j.rs:107-116`) — অনুপস্থিত প্রান্তবিন্দু যখন `to`, ত্রুটি বার্তা বিভ্রান্তিকর। ফিক্স: `nodes-matched < 2` হলে রিপোর্ট করার আগে `get_vertex` দিয়ে প্রকৃত অনুপস্থিত প্রান্তবিন্দু নির্ধারণ। নতুন: `add_edge_reports_the_missing_endpoint` (মক HTTP পরিষেবা যাচাই করে যে `from1`-এর বদলে `to1` রিপোর্ট হয়)।
6. **bee_template `context!` ডক আচরণের সাথে অসামঞ্জস্যপূর্ণ** (`crates/bee_template/src/lib.rs`) — ডক দাবি করে অসসীম ফ্লোট panic করে, কিন্তু serde_json আসলে সেগুলোকে `null` হিসেবে সিরিয়ালাইজ করে (বিদ্যমান টেস্টে প্রমাণিত)। ফিক্স: ডক আপডেট।

## নতুন কভারেজ

- **প্রকৃত Redis সহ bee_kv RedisStore ইন্টিগ্রেশন টেস্ট** (`crates/bee_kv/src/redis_store.rs`, feature `redis`) — আগের রিপোর্টে স্পষ্টভাবে নাম দেওয়া কভারেজ ফাঁক পূরণ করে। লোকাল Redis উপলব্ধ (127.0.0.1:6379); 3 কেস: set/get/del রাউন্ডট্রিপ, incr/expire, mset/mget; কীগুলিতে pid+ন্যানোসেকেন্ড উপসর্গ, কেসগুলো নিজেরাই পরিষ্কার করে। Redis অনুপলব্ধ হলে কেসগুলো মার্জিতভাবে বাদ যায় (SKIP ছাপে এবং পাস করে)।

## কভারেজ ফাঁক (অপরিবর্তিত)

- **bee_tsdb IoTDB `write_batch` অ-পরমাণু** (`crates/bee_tsdb/src/iotdb.rs`) — পয়েন্টে পয়েন্টে `?` শর্ট-সার্কিট লেখা, trait ডকের "atomically"-এর সাথে অসামঞ্জস্যপূর্ণ। ফিক্সে ব্যাকএন্ড ট্রানজেকশন সমর্থন দরকার; কোনো লোকাল IoTDB ইনস্ট্যান্স নেই, তাই এই রাউন্ডে অন্ধ পরিবর্তন নেই; পরিচিত সীমাবদ্ধতা হিসেবে তালিকাভুক্ত।
- **বহিরাগত ব্যাকএন্ড** (es/opensearch/clickhouse, neo4j/nebulagraph/arangodb, influxdb/iotdb/questdb, memcached) — প্রধান ড্রাইভার (elasticsearch, neo4j, influxdb লেখা/কোয়েরি/CQ পথ) লোকাল মক HTTP পরিষেবা দিয়ে আচ্ছাদিত; বাকি লোকাল পরিষেবা ছাড়া শুধু কম্পাইল-স্তরের যাচাই।
- **MySQL**: লোকাল 127.0.0.1:3306 উপলব্ধ (root, খালি পাসওয়ার্ড), কিন্তু workspace-এর কোনো crate MySQL ড্রাইভার আনে না — bee_orm ড্রাইভার-স্বাধীন SQL বিল্ডার, QuerySet কেস প্রকৃত DB-র উপর নির্ভর করে না; কোনো ড্রাইভার নির্ভরতা প্রয়োজন নেই এবং যোগ করা উচিত নয়।
- **bee_config_macro / bee_orm_macro**: proc-macro, নিজ নিজ ইন্টিগ্রেশন টেস্টের মাধ্যমে পরোক্ষভাবে আচ্ছাদিত, আলাদা ইউনিট টেস্ট নেই।

## গুণমান যাচাই

- `cargo fmt --check`: পাস (এই রাউন্ডে পুরো workspace-এ `cargo fmt` চালানো হয়েছে, আগের সেশনগুলোর 20+ ফরম্যাটিং বিচ্যুতি ঠিক করা হয়েছে)।
- `cargo clippy --workspace --all-targets`: নতুন কোডে শূন্য সতর্কতা; বাকি 3টি পূর্ব-বিদ্যমান সতর্কতা (bee_config `get("default").is_none()`, bee_rust Ok-তে `unwrap()`, bee_search MemoryEngine-এ Default impl নেই), এই রাউন্ডের আওতার বাইরে।

## পরিবেশ নোট

- cargo `~/.cargo/bin`-এ আছে (ডিফল্ট PATH-এ নেই), `export PATH="$HOME/.cargo/bin:$PATH"` প্রয়োজন।
- `protoc` এখন উপলব্ধ (`/home/erik/.local/bin/protoc`)।
- social_grpc ব্যাকগ্রাউন্ডে চলছে (পোর্ট 50051); এই রিপোর্ট শুধু `cargo test` চালিয়েছে, তাতে `cargo run` করেনি।
- Redis (6379) এবং MySQL (3306) লোকালি উপলব্ধ; feature কেস তালিকা:
  - `cargo test -p bee_tsdb --features influxdb` → 16 পাস
  - `cargo test -p bee_search --features elasticsearch` → 20 পাস
  - `cargo test -p bee_graph --features neo4j` → 20 পাস
  - `cargo test -p bee_kv --features redis` → 13 পাস (3টি প্রকৃত-Redis কেস সহ)
