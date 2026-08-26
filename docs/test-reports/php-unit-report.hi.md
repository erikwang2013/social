# PHP यूनिट परीक्षण रिपोर्ट
**语言 / Languages:** [中文](php-unit-report.md) · [English](php-unit-report.en.md) · [한국어](php-unit-report.ko.md) · [Русский](php-unit-report.ru.md) · [Deutsch](php-unit-report.de.md) · [Français](php-unit-report.fr.md) · [Español](php-unit-report.es.md) · [Português](php-unit-report.pt.md) · [हिन्दी](php-unit-report.hi.md) · [العربية](php-unit-report.ar.md) · [বাংলা](php-unit-report.bn.md) · [Bahasa Indonesia](php-unit-report.id.md) · [日本語](php-unit-report.ja.md)

- दिनांक: 2026-08-27
- निष्पादन: `vendor/bin/phpunit` (PHPUnit 10.5.64, PHP 8.3.7)
- दायरा: admin/ (webman एडमिन पैनल) + service/ (webman मुख्य सेवा)

## निष्कर्ष अवलोकन

| प्रोजेक्ट | परीक्षण मामले | एसर्शन | परिणाम |
|------|------|------|------|
| service | 136 | 348 | ✅ सभी पास (OK) |
| admin | 60 | 136 | ⚠️ 49 पास / 4 त्रुटियाँ / 7 फेल |

## service (पूर्ण रूप से हरा)

- नए परीक्षण फ़ाइलें (इस बैच में): AuthMiddlewareTest, UserBriefTest, SearchSyncTest, ActionHandlerTest, JwtHelperTest, VoiceControllerTest, MonitorTest, ModelRelationTest आदि; मौजूदा 24 परीक्षण फ़ाइलों के साथ मिलाकर कुल 136 मामले सभी पास
- कवर किए गए मॉड्यूल: प्रमाणीकरण/मिडलवेयर/JWT, उपयोगकर्ता, पोस्ट, कमेंट, फ़ॉलो, नोटिफिकेशन, सर्च सिंक, IM, रूम, कॉल (CallCenter/CallState), वॉइस, मॉडल संबंध, एक्शन हैंडलिंग (WS)

### सुधार: टेस्ट सूट का रैंडम हैंग (महत्वपूर्ण)

- लक्षण: पूर्ण रन में प्रोसेस बेतरतीब ढंग से जम जाता है; एकल फ़ाइल/उपसमुच्चय चलाने पर पास
- मूल कारण: `ActionHandlerTest::setUp` में `new Worker()` इंस्टेंस को `Worker::$workers` **स्टैटिक रजिस्ट्री** में पंजीकृत करता है; उसके बाद कोई भी `CallCenter::start` "Worker मौजूद है" देखकर `Timer::add` कॉल करता है → `pcntl_alarm(1)` SIGALRM टाइमर स्थापित करता है, प्रोसेस बाहर निकलते समय हैंग हो जाता है
- सुधार: setUp रजिस्ट्री का स्नैपशॉट लेता है, tearDown पुनर्स्थापित करता है (`ReflectionProperty` से `workers`/`pidMap` वापस लिखता है)
- स्थान: `service/tests/ActionHandlerTest.php`

## admin (49/60; फेल सभी पूर्व-मौजूद परीक्षण हैं और पर्यावरण/कॉन्फ़िगरेशन समस्याएँ हैं)

| परीक्षण मामला | फेल होने का कारण | श्रेणी |
|------|----------|------|
| EnvConfigTest (4 फेल + 1 त्रुटि) | `admin/.env` मौजूद नहीं, getenv/dotenv एसर्शन विफल | परीक्षण वातावरण में .env नहीं |
| CaptchaTest (3 त्रुटियाँ + 1 फेल + 1 risky) | कैप्चा चालू सेवा/Redis पर निर्भर, यूनिट टेस्ट वातावरण null लौटाता है | पर्यावरण निर्भरता |
| BackendEnhancementTest (2 फेल) | `app/middleware/Cors` की उपस्थिति और admin_user में searchable होने का एसर्शन — वर्तमान कॉन्फ़िगरेशन एसर्शन से मेल नहीं खाता | कॉन्फ़िगरेशन एसर्शन पुराने |

नोट: admin/tests सभी पूर्व-मौजूद फ़ाइलें हैं; इस बैच में admin के नए यूनिट टेस्ट नहीं जोड़े गए (ध्यान service पर था)।

## अकवर्ड / जोड़ने योग्य

- admin के मॉड्यूल (model/middleware/view) में यूनिट टेस्ट की कमी
- बाहरी सेवाओं (ES/gRPC) पर निर्भर service के रास्तों का केवल यूनिट-स्तरीय stub सत्यापन हुआ; एकीकरण स्तर API परीक्षणों से कवर करने की अनुशंसा
