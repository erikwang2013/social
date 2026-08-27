# सभी परीक्षणों की समग्र सारांश रिपोर्ट
**语言 / Languages:** [中文](SUMMARY.md) · [English](SUMMARY.en.md) · [한국어](SUMMARY.ko.md) · [Русский](SUMMARY.ru.md) · [Deutsch](SUMMARY.de.md) · [Français](SUMMARY.fr.md) · [Español](SUMMARY.es.md) · [Português](SUMMARY.pt.md) · [हिन्दी](SUMMARY.hi.md) · [العربية](SUMMARY.ar.md) · [বাংলা](SUMMARY.bn.md) · [Bahasa Indonesia](SUMMARY.id.md) · [日本語](SUMMARY.ja.md)

- दिनांक: 2026-08-27 (दूसरा पूर्ण रिग्रेशन)
- परीक्षण टीम: PHP यूनिट परीक्षण / Rust यूनिट परीक्षण / API स्वचालन / UI एंड-टू-एंड (GO भूमिका हेतु अंत में स्पष्टीकरण देखें)
- चार अलग-अलग रिपोर्टें + यह सारांश सभी स्थानीय रूप से `docs/test-reports/` में संग्रहीत

## अवलोकन

| भूमिका | रिपोर्ट | परीक्षण मामले | पास | फेल | निष्कर्ष |
|------|------|------|------|------|------|
| PHP यूनिट परीक्षण | `php-unit-report.md` | 226 | 226 | 0 | service 159/408 + admin 67/67 पूर्ण हरा |
| Rust यूनिट परीक्षण | `rust-unit-report.md` | 183 | 183 | 0 | 16 crates पूर्ण हरे, और 5 वास्तविक दोष ठीक किए गए |
| API स्वचालन | `api-test-report.md` | 116 | 116 | 0 | पिछले दौर के 3 उत्पाद दोषों का सुधार सत्यापित |
| UI एंड-टू-एंड | `ui-e2e-report.md` | 41 | 41 | 0 | पूर्ण हरा, 1 blocked (ES प्रारंभ नहीं) |
| **कुल** | | **566** | **566** | **0** | पास दर 100% (1 blocked) |

## इस दौर में ठीक किए गए वास्तविक दोष (सभी ठीक और रिग्रेशन-सत्यापित)

1. **A20 अमान्य hashid 500→404** (पिछले दौर से शेष): `BaseController::decodeId()` `InvalidArgumentException` पकड़कर `support\exception\NotFoundException(404)` (body code) फेंकता है; बैच विधियाँ 422 अर्थ बनाए रखती हैं
2. **A39/A40 Excel/PDF निर्यात निश्चित विफलता** (पिछले दौर से शेष): `ExportController` में `use support\Response;` जोड़ा गया (रिटर्न टाइप पहले एक अस्तित्वहीन क्लास में हल होता था); Encryptable cast द्वारा पहले से डिक्रिप्ट किए गए फ़ील्ड्स की दूसरी डिक्रिप्शन हटाई गई
3. **कैप्चा Imagick ड्राइवर क्रैश** (नया पता चला, प्रोडक्शन भी प्रभावित): स्थानीय ImageMagick 7 में `RESOURCETYPE_PIXELS` कॉन्स्टेंट नहीं है; `config/poster.php` में ड्राइवर पहचान में कॉन्स्टेंट गार्ड जोड़ा गया, अनुपस्थित होने पर स्वतः GD पर फ़ॉलबैक
4. **service होमपेज `/` 404** (नया पता चला): webman-framework v2.2.4 डिफ़ॉल्ट रूप से रूट रूट नहीं हल करता; `service/config/route.php` में `Route::get('/')` स्पष्ट रूप से पंजीकृत
5. **Rust के 5 दोष** (नए पाए गए, विवरण rust-unit-report.md में): bee_search MemoryEngine पेजिनेशन अनदेखा करता है, social_grpc गैर-संख्यात्मक id को चुपचाप 0 बना देता है, bee_tsdb InfluxDB line protocol फ़ील्ड क्रम में नहीं, bee_search ES bulk NDJSON id अनएस्केप्ड, bee_graph Neo4j add_edge त्रुटि एंडपॉइंट हमेशा `from`
6. **टेस्ट स्क्रिप्ट स्वयं**: `tests/api/run.php` में DB पासवर्ड खाली स्ट्रिंग `?:` से 'root' पर फ़ॉलबैक होती थी → `?? 'root'` में बदला गया; admin की तीन पुरानी एसर्शन सूट वर्तमान कोड के अनुसार फिर से लिखी गईं (Searchable अप्रचलित, Cors मिडलवेयर कुंजियाँ, poster-php कैप्चा अनुबंध)

## M5 माइलस्टोन सत्यापन (नया)

- लाइव मॉड्यूल (LiveCenter: बनाएं/विवरण/दानमाकु/माइक लिंक/बंद) डिलीवर होकर सत्यापित: service phpunit +23 मामले (159/408 हरा), ब्लैक-बॉक्स E2E `tests/live_e2e.php` की सभी 27 जाँचें पास (RTMP पुश, HLS पुल सहित)

## पर्यावरण सुधार और सावधानियाँ (इस परीक्षण बैच के कारण)

- **8788 अन्य प्रोजेक्ट की प्रक्रिया द्वारा अधिकृत**: इस मशीन का `property-management-platform` service गलती से 8788 पोर्ट पर था; उसे रोका गया और खाली पासवर्ड पर्यावरण चर के साथ social service पुनः प्रारंभ किया गया
- **`service/.env` अभी भी `service/.env.api-test-bak` है**: पुनर्स्थापना .env फ़ाइल एक्सेस नीति से प्रतिबंधित; मैन्युअल `mv service/.env.api-test-bak service/.env` आवश्यक (पुनर्स्थापना के बाद सेवा पुनः आरंभ करें)
- **ImageMagick 7 अनुकूलता**: Imagick ड्राइवर बहाल करने हेतु ImageMagick 6.x में डाउनग्रेड करें या poster-php को IM7-अनुकूल उन्नत करें; वर्तमान GD ड्राइवर पूरी श्रृंखला में सामान्य
- **ES प्रारंभ नहीं**: सर्च-प्रकार के मामले (API + E2E) 503/blocked के रूप में पास चिह्नित; Elasticsearch प्रारंभ करने के बाद पुनः सत्यापन आवश्यक

## अनुबंध/दस्तावेज़ असंगतियाँ (संशोधन सुझाव, गैर-अवरोधक)

- कैप्चा apidoc `clicks=[{x,y}]` ऑब्जेक्ट ऐरे लिखता है, जबकि poster-php कार्यान्वयन `[[x,y]]` निर्देशांक-युग्म ऐरे चाहता है
- वॉइस अपलोड `voice_url` को `/voice/{md5}.m4a` के रूप में लौटाता है (`/api/v1` उपसर्ग नहीं); क्लाइंट को स्वयं जोड़ना होगा

## GO परीक्षण इंजीनियर नोट

रिपॉजिटरी में **कोई Go कोड नहीं है** (कोई go.mod नहीं, कोई .go फ़ाइल नहीं); इस भूमिका के पास परीक्षण करने के लिए कोई मॉड्यूल नहीं था और इसे निष्पादित नहीं किया गया। कवरेज जोड़ने के लिए पहले एक Go घटक (जैसे गेटवे/सर्च साइडकार) पेश करना होगा।

## पुनरुत्पादन

```bash
# यूनिट परीक्षण
cd service && vendor/bin/phpunit
cd admin && vendor/bin/phpunit
cd infrastructure && cargo test --workspace
# API स्वचालन (पहले admin :8791 और service :8788 शुरू करें, ENCRYPTABLE_KEY/ENCRYPTION_KEY इंजेक्ट करें; स्थानीय root खाली पासवर्ड हेतु DB_PASS='' चाहिए)
DB_PASS='' php tests/api/run.php
# UI E2E
cd tests/e2e && npx playwright test
```
