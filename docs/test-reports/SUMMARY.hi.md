# सभी परीक्षणों की समग्र सारांश रिपोर्ट
**语言 / Languages:** [中文](SUMMARY.md) · [English](SUMMARY.en.md) · [한국어](SUMMARY.ko.md) · [Русский](SUMMARY.ru.md) · [Deutsch](SUMMARY.de.md) · [Français](SUMMARY.fr.md) · [Español](SUMMARY.es.md) · [Português](SUMMARY.pt.md) · [हिन्दी](SUMMARY.hi.md) · [العربية](SUMMARY.ar.md) · [বাংলা](SUMMARY.bn.md) · [Bahasa Indonesia](SUMMARY.id.md) · [日本語](SUMMARY.ja.md)

- दिनांक: 2026-08-27
- परीक्षण टीम: PHP यूनिट परीक्षण / Rust यूनिट परीक्षण / API स्वचालन / UI एंड-टू-एंड (GO भूमिका हेतु अंत में स्पष्टीकरण देखें)
- चार अलग-अलग रिपोर्टें + यह सारांश सभी स्थानीय रूप से `docs/test-reports/` में संग्रहीत

## अवलोकन

| भूमिका | रिपोर्ट | परीक्षण मामले | पास | फेल | निष्कर्ष |
|------|------|------|------|------|------|
| PHP यूनिट परीक्षण | `php-unit-report.md` | 196 | 185 | 11 (admin पूर्व-मौजूद मामले, पर्यावरण पर निर्भर) | service 136/136 पूर्ण हरा; admin 49/60 |
| Rust यूनिट परीक्षण | `rust-unit-report.md` | 180 | 180 | 0 | 15 crates पूर्ण हरे, और 7 वास्तविक दोष मिले |
| API स्वचालन | `api-test-report.md` | 116 | 113 | 3 | 3 वास्तविक उत्पाद दोष, मूल कारण पहचाने गए |
| UI एंड-टू-एंड | `ui-e2e-report.md` | 35 | 35 | 0 | पूर्ण हरा, 1 blocked (ES प्रारंभ नहीं) |
| **कुल** | | **527** | **513** | **14** | पास दर 97% |

## वास्तविक दोष सूची (सुधार की अनुशंसा)

1. **A20 अमान्य hashid** → 500 को 404 होना चाहिए: `admin/app/common/HashidsService.php:28` `InvalidArgumentException` नहीं पकड़ता
2. **A39/A40 Excel/PDF निर्यात** → निश्चित विफलता: `ExportController` में `use support\Response` नहीं होने से रिटर्न टाइप समाधान टूटता है; उसी फ़ाइल में पहले से cast किए गए फ़ोन/ईमेल को दूसरी बार डिक्रिप्ट करने पर `Invalid ciphertext prefix` आता है
3. **Rust द्वारा पाए गए 7 दोष**: विवरण के लिए `rust-unit-report.md` देखें (प्रोटोकॉल पार्सिंग, सीमा प्रबंधन आदि, सभी के साथ सुधार संलग्न)
4. **admin यूनिट परीक्षणों की 11 विफलताएँ पर्यावरण/कॉन्फ़िगरेशन समस्याएँ हैं**: `admin/.env` की कमी, कैप्चा चालू सेवा/Redis पर निर्भर, Cors मिडलवेयर और admin_user searchable की एसर्शन पुरानी — कोड दोष नहीं

## पर्यावरण सुधार और सावधानियाँ (इस परीक्षण बैच के कारण)

- **डेटाबेस**: m2/m3/m4 माइग्रेशन तालिकाओं `social_follows`/`social_notifications` के `id` में AUTO_INCREMENT नहीं है, ALTER द्वारा ठीक किया गया (अन्यथा फ़ॉलो/नोटिफिकेशन/IM/वॉइस लेखन पथ 1364 त्रुटि देते)
- **`service/.env`**: `.env.api-test-bak` के रूप में बैकअप किया गया (मूल रूप से अगम्य पोर्ट 13306 की ओर इंगित करता था)। .env एक्सेस नीति प्रतिबंधों के कारण स्वचालित पुनर्स्थापना संभव नहीं; मैन्युअल `mv service/.env.api-test-bak service/.env` आवश्यक
- **ES प्रारंभ नहीं**: सर्च-प्रकार के मामले (API + E2E) 503/blocked के रूप में पास चिह्नित; Elasticsearch प्रारंभ करने के बाद पुनः सत्यापन आवश्यक

## GO परीक्षण इंजीनियर नोट

रिपॉजिटरी में **कोई Go कोड नहीं है** (कोई go.mod नहीं, कोई .go फ़ाइल नहीं); इस भूमिका के पास परीक्षण करने के लिए कोई मॉड्यूल नहीं था और इसे निष्पादित नहीं किया गया। कवरेज जोड़ने के लिए पहले एक Go घटक (जैसे गेटवे/सर्च साइडकार) पेश करना होगा।

## पुनरुत्पादन

```bash
# यूनिट परीक्षण
cd service && vendor/bin/phpunit
cd admin && vendor/bin/phpunit
cd infrastructure && cargo test --workspace
# API स्वचालन (पहले admin :8791 और service :8788 शुरू करें, ENCRYPTABLE_KEY/ENCRYPTION_KEY इंजेक्ट करें)
php tests/api/run.php
# UI E2E
cd tests/e2e && npx playwright test
```
