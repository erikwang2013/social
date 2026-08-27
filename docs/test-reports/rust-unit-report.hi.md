# Rust Workspace यूनिट परीक्षण रिपोर्ट
**语言 / Languages:** [中文](rust-unit-report.md) · [English](rust-unit-report.en.md) · [한국어](rust-unit-report.ko.md) · [Русский](rust-unit-report.ru.md) · [Deutsch](rust-unit-report.de.md) · [Français](rust-unit-report.fr.md) · [Español](rust-unit-report.es.md) · [Português](rust-unit-report.pt.md) · [हिन्दी](rust-unit-report.hi.md) · [العربية](rust-unit-report.ar.md) · [বাংলা](rust-unit-report.bn.md) · [Bahasa Indonesia](rust-unit-report.id.md) · [日本語](rust-unit-report.ja.md)

- दिनांक: 2026-08-27
- स्थान: `/home/wwwroot/social/infrastructure`
- आदेश: `cargo test --workspace` (डिफ़ॉल्ट features), साथ ही feature-gated बैकएंड सत्यापन (tsdb/graph/search/kv)
- परिणाम: **183 पास / 0 फेल** (178 यूनिट+इंटीग्रेशन + 5 feature-इनलाइन + 1 doctest, आदि; डिफ़ॉल्ट workspace में bee_search के 6 केस शामिल हैं क्योंकि social_grpc उसकी `elasticsearch` feature पर निर्भर है)

## सारांश

| crate | परीक्षण संख्या | पास | फेल | कवर किए गए मॉड्यूल |
|-------|-------|------|------|----------|
| bee_cache | 8 | 8 | 0 | set/get/delete/expire, incr, ttl, MemoryCache |
| bee_cli | 14 | 14 | 0 | rust_type मैपिंग, find_bin_name, validate_name, bin आर्गुमेंट पार्सिंग |
| bee_config | 8 + 6 इंटीग्रेशन | 14 | 0 | IniParser (टिप्पणियाँ/रिक्त स्थान/सेक्शन स्विच), Config, ConfigSource, रीलोड त्रुटियाँ |
| bee_config_macro | 0 | — | — | इंटीग्रेशन टेस्टों के माध्यम से अप्रत्यक्ष कवर |
| bee_graph | 15 | 15 | 0 | StubGraphDB: ट्रैवर्सल दिशा/गहराई/लेबल, add/update/delete, त्रुटि पथ, serde (feature neo4j अतिरिक्त 5) |
| bee_kv | 10 | 10 | 0 | get/incr/expire/mset, समाप्त कुंजियाँ, त्रुटि पथ (feature redis अतिरिक्त 3 वास्तविक-Redis केस) |
| bee_logs | 4 | 4 | 0 | level_str सभी स्तर, फ़ाइल आउटपुट, stdout/stderr आउटपुट |
| bee_orm | 19 इंटीग्रेशन | 19 | 0 | SelectBuilder: order/limit/offset/पैरामीटर बाइंडिंग/पुनः उपयोग/table_name/त्रुटि Display (lib में 0) |
| bee_orm_macro | 0 | — | — | इंटीग्रेशन टेस्टों के माध्यम से अप्रत्यक्ष कवर |
| bee_router | 36 | 36 | 0 | context (params/text/html/abort), router (method/404/namespace), dispatch पाइपलाइन, सत्र पुनर्स्थापना/स्थायित्व/समाप्त कुकी |
| bee_rust | 2 (bin अतिरिक्त 9) | 11 | 0 | prelude निर्यात, Result उपनाम, CLI आर्गुमेंट पार्सिंग |
| bee_search | 20 (6 feature-इनलाइन सहित) | 20 | 0 | MemoryEngine: index/delete/ओवरराइट/पेजिनेशन/get/खाली क्वेरी/serde; Elasticsearch ड्राइवर: get/search/bulk/aggregate, NDJSON एस्केपिंग |
| bee_session | 8 | 8 | 0 | set/get/delete, save/load/refresh, TTL floor, त्रुटि पथ, UUID विशिष्टता |
| bee_template | 6 + 1 doctest | 7 | 0 | context! मैक्रो, render, लापता टेम्पलेट/चर त्रुटियाँ, खाली engine, गैर-परिमित फ्लोट (1 doctest) |
| bee_tsdb | 11 | 11 | 0 | लेखन/बैच लेखन, रेंज क्वेरी सीमाएँ, Eq/Neq/Regex/AND फ़िल्टरिंग, Point serde, CQ (feature influxdb अतिरिक्त 5, line protocol निर्धारण सहित) |
| social_grpc | 6 | 6 | 0 | SearchService: index/search/delete राउंडट्रिप, अमान्य JSON फॉलबैक, खाली इंडेक्स, गैर-संख्यात्मक id त्रुटि |
| hello_bee | 0 | — | — | उदाहरण प्रोग्राम, कोई टेस्ट नहीं |

## इस राउंड में सुधारे गए वास्तविक दोष (न्यूनतम फिक्स + रिग्रेशन टेस्ट)

1. **bee_search MemoryEngine `search` पेजिनेशन को अनदेखा करता है** (`crates/bee_search/src/lib.rs`) — gRPC परत से भेजे गए `from`/`size` को त्याग दिया जाता था, हमेशा सभी हिट लौटाता था। फिक्स: क्वेरी JSON से `from`/`size` पढ़ता है और hits पर skip/truncate लागू करता है, `total` फिर भी सभी मैच गिनता है। नया: `test_search_honors_from_size_pagination` (अव्यवस्थित HashMap इटरेशन के प्रति मज़बूत: इंजन के स्वयं के पूर्ण परिणाम के स्लाइस से तुलना करता है)।
2. **social_grpc `search` गैर-संख्यात्मक id को चुपचाप 0 बना देता है** (`crates/social_grpc/src/search.rs:53-60`) — `h.id.parse().unwrap_or(0)` गैर-संख्यात्मक दस्तावेज़ id को चुपचाप 0 के रूप में लौटा रहा था। फिक्स: पार्स विफलता `Status::invalid_argument` लौटाती है। नया: `non_numeric_hit_id_becomes_invalid_argument`।
3. **bee_tsdb InfluxDB line protocol फ़ील्ड क्रम से बाहर** (`crates/bee_tsdb/src/influxdb.rs:160-170`) — tags क्रमबद्ध हैं पर fields नहीं; कई फ़ील्डों पर आउटपुट अनिश्चित। फिक्स: fields को कुंजी से क्रमबद्ध करें। नया: `line_protocol_is_deterministic_across_field_insertion_order` (अलग-अलग सम्मिलन क्रम समान पंक्तियाँ उत्पन्न करते हैं, a,b क्रम में)।
4. **bee_search Elasticsearch bulk NDJSON id को एस्केप नहीं करता** (`crates/bee_search/src/elasticsearch.rs`) — index/id को सीधे स्ट्रिंग में इंटरपोलेट किया जाता था; `"` वाली id गलत NDJSON बनाती थीं। फिक्स: `bulk_ndjson()` निकाला गया, एक्शन पंक्तियाँ serde_json से सीरियलाइज़ होती हैं। नया: `bulk_ndjson_escapes_ids_and_stays_parseable`।
5. **bee_graph Neo4j `add_edge` त्रुटि अंतबिंदु हमेशा `from`** (`crates/bee_graph/src/neo4j.rs:107-116`) — जब लापता अंतबिंदु `to` होता है, त्रुटि संदेश भ्रामक होता है। फिक्स: `nodes-matched < 2` होने पर रिपोर्ट करने से पहले `get_vertex` से वास्तविक लापता अंतबिंदु निर्धारित करें। नया: `add_edge_reports_the_missing_endpoint` (मॉक HTTP सेवा सत्यापित करती है कि `from1` के बजाय `to1` रिपोर्ट होता है)।
6. **bee_template `context!` दस्तावेज़ व्यवहार से असंगत** (`crates/bee_template/src/lib.rs`) — दस्तावेज़ दावा करता है कि गैर-परिमित फ्लोट panic करते हैं, पर serde_json वास्तव में उन्हें `null` के रूप में सीरियलाइज़ करता है (मौजूदा टेस्ट से सिद्ध)। फिक्स: दस्तावेज़ अद्यतन।

## नया कवरेज

- **वास्तविक Redis के साथ bee_kv RedisStore इंटीग्रेशन टेस्ट** (`crates/bee_kv/src/redis_store.rs`, feature `redis`) — पिछली रिपोर्ट में स्पष्ट रूप से नामित कवरेज अंतर को भरता है। स्थानीय Redis उपलब्ध (127.0.0.1:6379); 3 केस: set/get/del राउंडट्रिप, incr/expire, mset/mget; कुंजियों पर pid+नैनोसेकंड उपसर्ग, केस स्वयं सफाई करते हैं। जब Redis अनुपलब्ध हो, केस शालीनता से छोड़ दिए जाते हैं (SKIP छापकर पास हो जाते हैं)।

## कवरेज अंतराल (अपरिवर्तित)

- **bee_tsdb IoTDB `write_batch` गैर-परमाणु** (`crates/bee_tsdb/src/iotdb.rs`) — प्रति-पॉइंट `?` शॉर्ट-सर्किट लेखन, trait दस्तावेज़ के "atomically" के साथ असंगत। फिक्स के लिए बैकएंड ट्रांज़ैक्शन समर्थन चाहिए; कोई स्थानीय IoTDB इंस्टेंस नहीं, इसलिए इस राउंड में अंधे बदलाव नहीं; ज्ञात सीमा के रूप में सूचीबद्ध।
- **बाहरी बैकएंड** (es/opensearch/clickhouse, neo4j/nebulagraph/arangodb, influxdb/iotdb/questdb, memcached) — मुख्य ड्राइवर (elasticsearch, neo4j, influxdb लेखन/क्वेरी/CQ पथ) स्थानीय मॉक HTTP सेवाओं से कवर हैं; बाकी बिना स्थानीय सेवा के केवल संकलन-स्तरीय सत्यापन।
- **MySQL**: स्थानीय 127.0.0.1:3306 उपलब्ध (root, खाली पासवर्ड), पर workspace का कोई भी crate MySQL ड्राइवर नहीं लाता — bee_orm ड्राइवर-स्वतंत्र SQL बिल्डर है, QuerySet केस वास्तविक DB पर निर्भर नहीं; कोई ड्राइवर निर्भरता आवश्यक नहीं और जोड़ी नहीं जानी चाहिए।
- **bee_config_macro / bee_orm_macro**: proc-macro, अपने इंटीग्रेशन टेस्टों से अप्रत्यक्ष रूप से कवर, कोई स्वतंत्र यूनिट टेस्ट नहीं।

## गुणवत्ता जाँच

- `cargo fmt --check`: पास (इस राउंड में पूरे workspace पर `cargo fmt` चलाया गया, पिछले सत्रों से बची 20+ फ़ॉर्मेटिंग विचलनें ठीक कीं)।
- `cargo clippy --workspace --all-targets`: नए कोड में शून्य चेतावनी; बाकी 3 पूर्व-मौजूद चेतावनियाँ हैं (bee_config `get("default").is_none()`, bee_rust Ok पर `unwrap()`, bee_search MemoryEngine में Default impl नहीं), इस राउंड के दायरे से बाहर।

## पर्यावरण टिप्पणियाँ

- cargo `~/.cargo/bin` में है (डिफ़ॉल्ट रूप से PATH में नहीं), `export PATH="$HOME/.cargo/bin:$PATH"` की आवश्यकता।
- `protoc` अब उपलब्ध है (`/home/erik/.local/bin/protoc`)।
- social_grpc बैकग्राउंड में चल रहा है (पोर्ट 50051); इस रिपोर्ट ने केवल `cargo test` चलाया, उस पर `cargo run` नहीं।
- Redis (6379) और MySQL (3306) स्थानीय रूप से उपलब्ध; feature केस सूची:
  - `cargo test -p bee_tsdb --features influxdb` → 16 पास
  - `cargo test -p bee_search --features elasticsearch` → 20 पास
  - `cargo test -p bee_graph --features neo4j` → 20 पास
  - `cargo test -p bee_kv --features redis` → 13 पास (3 वास्तविक-Redis केस सहित)
