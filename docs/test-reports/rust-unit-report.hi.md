# Rust Workspace यूनिट परीक्षण रिपोर्ट
**语言 / Languages:** [中文](rust-unit-report.md) · [English](rust-unit-report.en.md) · [한국어](rust-unit-report.ko.md) · [Русский](rust-unit-report.ru.md) · [Deutsch](rust-unit-report.de.md) · [Français](rust-unit-report.fr.md) · [Español](rust-unit-report.es.md) · [Português](rust-unit-report.pt.md) · [हिन्दी](rust-unit-report.hi.md) · [العربية](rust-unit-report.ar.md) · [বাংলা](rust-unit-report.bn.md) · [Bahasa Indonesia](rust-unit-report.id.md) · [日本語](rust-unit-report.ja.md)

- दिनांक: 2026-08-27
- स्थान: `/home/wwwroot/social/infrastructure`
- आदेश: `cargo test --workspace` (डिफ़ॉल्ट features), साथ ही feature-gated बैकएंड सत्यापन (tsdb/graph/search/kv)
- परिणाम: **180 पास / 0 फेल** (179 यूनिट+इंटीग्रेशन टेस्ट + 1 doctest)

## सारांश

| crate | परीक्षण संख्या | पास | फेल | कवर किए गए मॉड्यूल |
|-------|-------|------|------|----------|
| bee_cache | 8 | 8 | 0 | set/get/delete/expire, incr, ttl, MemoryCache |
| bee_cli | 23 | 23 | 0 | rust_type मैपिंग, find_bin_name, validate_name, bin आर्गुमेंट पार्सिंग |
| bee_config | 14 | 14 | 0 | IniParser (टिप्पणियाँ/रिक्त स्थान/सेक्शन स्विच), Config, ConfigSource, 6 इंटीग्रेशन |
| bee_config_macro | 0 | — | — | इंटीग्रेशन टेस्टों के माध्यम से अप्रत्यक्ष कवर |
| bee_graph | 15 | 15 | 0 | StubGraphDB: ट्रैवर्सल दिशा/गहराई/लेबल, add/update/delete, त्रुटि पथ, serde (feature बैकएंड अतिरिक्त 29) |
| bee_kv | 10 | 10 | 0 | get/incr/expire/mset, समाप्त कुंजियाँ, त्रुटि पथ |
| bee_logs | 4 | 4 | 0 | level_str सभी स्तर, फ़ाइल आउटपुट, stdout/stderr आउटपुट |
| bee_orm | 19 | 19 | 0 | SelectBuilder (इंटीग्रेशन): order/limit/offset/पैरामीटर बाइंडिंग/पुनः उपयोग/table_name/त्रुटि display (lib में 0) |
| bee_orm_macro | 0 | — | — | इंटीग्रेशन टेस्टों के माध्यम से अप्रत्यक्ष कवर |
| bee_router | 36 | 36 | 0 | context (params/text/html/abort), router (method/404/namespace), dispatch पाइपलाइन, सत्र पुनर्स्थापना/स्थायित्व/समाप्त कुकी |
| bee_rust | 2 | 2 | 0 | prelude निर्यात, Result उपनाम |
| bee_search | 18 | 18 | 0 | MemoryEngine: index/delete/ओवरराइट/पेजिनेशन/get/खाली क्वेरी/serde (feature बैकएंड अतिरिक्त 20) |
| bee_session | 8 | 8 | 0 | set/get/delete, save/load/refresh, TTL floor, त्रुटि पथ, UUID विशिष्टता |
| bee_template | 6+1 | 7 | 0 | context! मैक्रो, render, लापता टेम्पलेट/चर त्रुटियाँ, खाली engine, गैर-परिमित फ्लोट (1 doctest सहित) |
| bee_tsdb | 10 | 10 | 0 | Query फ़िल्टरिंग (Neq/Regex/रेंज/AND), Point serde, enum debug (feature बैकएंड अतिरिक्त 22) |
| social_grpc | 5 | 5 | 0 | SearchService: index/search/delete राउंडट्रिप, अमान्य JSON फॉलबैक, खाली इंडेक्स |
| hello_bee | 0 | — | — | उदाहरण प्रोग्राम, कोई टेस्ट नहीं |

## अकवर्ड सूची

- **bee_kv `redis` feature (RedisStore)**: लाइव Redis सर्वर की आवश्यकता, कवर नहीं
- **hello_bee**: उदाहरण प्रोग्राम, 0 टेस्ट
- **feature-gated बैकएंड** (डिफ़ॉल्ट features में संकलित नहीं): अपने-अपने feature संयोजनों में संकलित एवं परीक्षण पास सत्यापित (tsdb 22, graph 29, search 20, kv 10), परंतु es/opensearch/clickhouse, neo4j/nebulagraph/arangodb, influxdb/iotdb/questdb, redis जैसे वास्तविक बैकएंड बाहरी सेवाओं की मांग करते हैं — केवल संकलन-स्तरीय सत्यापन
- **bee_config_macro / bee_orm_macro**: proc-macro, अपने इंटीग्रेशन टेस्टों से अप्रत्यक्ष रूप से कवर, कोई स्वतंत्र यूनिट टेस्ट नहीं

## दर्ज किए गए वास्तविक बग (लाइब्रेरी स्रोत संशोधित नहीं)

1. `bee_tsdb/src/influxdb.rs:160-169` — `line_protocol` `&point.fields` (HashMap) को बिना क्रमबद्ध किए इटरेट करता है जबकि tags क्रमबद्ध हैं → कई फ़ील्ड होने पर line protocol आउटपुट अनिश्चित
2. `bee_tsdb/src/iotdb.rs:37-42` — `write_batch` गैर-परमाणु है (प्रति-पॉइंट निष्पादन और `?` शॉर्ट-सर्किट), trait दस्तावेज़ के "atomically" दावे के विपरीत
3. `bee_graph/src/neo4j.rs:106-109` — `add_edge` हमेशा `VertexNotFound(edge.from)` लौटाता है, भले ही लापता अंतबिंदु `to` हो
4. `bee_search` MemoryEngine `search` — gRPC परत से भेजे गए from/size को अनदेखा करता है (पेजिनेशन नहीं)
5. `social_grpc/src/search.rs:54` — `h.id.parse().unwrap_or(0)`: गैर-संख्यात्मक id चुपचाप 0 बन जाता है
6. `bee_template/src/lib.rs` में `context!` मैक्रो दस्तावेज़ — दावा करता है कि NaN panic करता है, जबकि वास्तव में serde_json ≥1.0.128 इसे null के रूप में सीरियलाइज़ करता है (दस्तावेज़ पुराना)
7. `bee_search/src/elasticsearch.rs:64` — bulk NDJSON index/id को JSON में कच्चा इंटरपोलेट करता है; `"` वाली id गलत NDJSON बनाती हैं

## पर्यावरण टिप्पणियाँ

- cargo `~/.cargo/bin` में है (PATH में नहीं), `export PATH="$HOME/.cargo/bin:$PATH"` की आवश्यकता
- social_grpc को `protoc` चाहिए: `apt-get download protobuf-compiler` + `dpkg-deb -x` से `/tmp/protoc-local` में निकाला गया, `PROTOC=/tmp/protoc-local/usr/bin/protoc` (sudo की आवश्यकता नहीं)
