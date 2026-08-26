# परिवर्तन लॉग

**语言 / Languages:** [中文](CHANGELOG.md) · [English](CHANGELOG.en.md) · [한국어](CHANGELOG.ko.md) · [Русский](CHANGELOG.ru.md) · [Deutsch](CHANGELOG.de.md) · [Français](CHANGELOG.fr.md) · [Español](CHANGELOG.es.md) · [Português](CHANGELOG.pt.md) · [हिन्दी](CHANGELOG.hi.md) · [العربية](CHANGELOG.ar.md) · [বাংলা](CHANGELOG.bn.md) · [Bahasa Indonesia](CHANGELOG.id.md) · [日本語](CHANGELOG.ja.md)

## [1.0.6] — 2026-08-07

### जोड़ा गया
- `bee_cli` की वास्तविक इम्प्लीमेंटेशन: `new` (प्रोजेक्ट स्कैफोल्डिंग), `generate controller/model`, `--watch` हॉट रीलोड के साथ `run`, `pack` (रिलीज़ बिल्ड + `dist/` में कॉपी)
- स्कैफोल्डिंग और कोड जनरेशन के लिए CLI यूनिट टेस्ट (7 नए टेस्ट)

### ठीक किया गया
- `bee_rust::init()` अब `logs` फीचर के पीछे गेट किया गया है — कम किए गए फीचर बिल्ड (जैसे `--no-default-features --features kv`) फिर से कंपाइल होते हैं
- `bee_kv::InMemoryKvStore::exists` में Clippy `unnecessary_map_or` लिंट
- `rustfmt.toml` से केवल-nightly वाले विकल्प हटाए गए जो stable पर चुपचाप अनदेखा किए जाते थे; वर्कस्पेस अब `cargo fmt --all --check` पास करता है
- `bee_cli` बाइनरी `doc = false` — `bee_rust` के साथ rustdoc आउटपुट फ़ाइलनाम टकराव हटाया गया
- `hello` उदाहरण का पोर्ट अब `PORT` env var से कॉन्फ़िगर किया जा सकता है

### बदला गया
- `bee-rust migrate` "not implemented" रिपोर्ट करता है और नॉन-ज़ीरो कोड के साथ बाहर निकलता है (योजनाबद्ध)
- README / README.en को वास्तविक CLI व्यवहार बताने के लिए अपडेट किया गया

## [1.0.4] — 2026-07-29

### जोड़ा गया
- `security-rust` के माध्यम से सुरक्षा हमले का पता लगाने वाला फ़िल्टर (27 डिटेक्टर)
- XSS, SQL इंजेक्शन, कमांड इंजेक्शन, पाथ ट्रैवर्सल कवरेज के साथ `SecurityFilter`
- `bee_rust` और `bee_router` में `security` फीचर फ्लैग

### बदला गया
- सुरक्षा फीचर दस्तावेज़ीकरण के साथ README अपडेट किया गया
- भुगतान समर्थन अनुभाग (WeChat Pay / Alipay) के साथ README अपडेट किया गया

### ठीक किया गया
- Rust 2024 एडिशन के लिए `bee_template` Tera raw आइडेंटिफ़ायर सिंटैक्स

## [1.0.3] — 2026-07-29

### जोड़ा गया
- 13 क्रेट्स के साथ प्रारंभिक वर्कस्पेस संरचना
- `Controller` ट्रेट और `Router` के साथ MVC रूटिंग
- `QuerySet` बिल्डर और `Model` डिराइव मैक्रो के साथ ORM
- Redis और Memory बैकएंड के साथ KV/Cache ट्रेट एब्स्ट्रैक्शन
- Memory/Redis बैकएंड के साथ सत्र प्रबंधन
- INI/YAML/ENV समर्थन और हॉट-रीलोड के साथ कॉन्फ़िग प्रबंधन
- Tera के माध्यम से टेम्पलेट रेंडरिंग
- tracing इंटीग्रेशन के साथ लॉगिंग
- CLI स्कैफोल्डिंग और कोड जनरेशन
- सर्च, ग्राफ़, टाइम-सीरीज़ इंजन ट्रेट स्टब्स (ड्राइवर योजनाबद्ध)

[1.0.4]: https://github.com/erikwang2013/bee-rust/compare/v1.0.3...v1.0.4
[1.0.3]: https://github.com/erikwang2013/bee-rust/releases/tag/v1.0.3
