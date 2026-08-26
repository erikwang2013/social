# Admin बेसलाइन स्वीकृति (M0, 2026-08-17)

**语言 / Languages:** [中文](ADMIN_BASELINE.md) · [English](ADMIN_BASELINE.en.md) · [한국어](ADMIN_BASELINE.ko.md) · [Русский](ADMIN_BASELINE.ru.md) · [Deutsch](ADMIN_BASELINE.de.md) · [Français](ADMIN_BASELINE.fr.md) · [Español](ADMIN_BASELINE.es.md) · [Português](ADMIN_BASELINE.pt.md) · [हिन्दी](ADMIN_BASELINE.hi.md) · [العربية](ADMIN_BASELINE.ar.md) · [বাংলা](ADMIN_BASELINE.bn.md) · [Bahasa Indonesia](ADMIN_BASELINE.id.md) · [日本語](ADMIN_BASELINE.ja.md)

open-admin (webman v2 + Flutter एडमिन कंसोल) की बेसलाइन स्थिति और परिवर्तन के प्रवेश बिंदु।

## वर्तमान संस्करण और रनटाइम स्थिति

| आइटम | मान |
|---|---|
| फ्रेमवर्क | webman v2 (workerman/webman-framework **v2.2.3**) |
| PHP | 8.3.7 (CLI) |
| निर्भरताएँ | `composer install` सफल, 69 पैकेज |
| .env | **मौजूद नहीं** (रिपॉज़िटरी में न तो `.env` है और न ही `.env.example`; MySQL/Redis के अनुसार लोकल में बनाना होगा) |
| माइग्रेशन प्रवेश | कोई नहीं (`think`/`artisan` उपलब्ध नहीं; webman में माइग्रेशन बिल्ट-इन नहीं है, M0 में माइग्रेशन कार्य नहीं) |
| टेस्ट | `vendor/bin/phpunit`: 60 tests / 136 assertions, **4 errors / 7 failures / 6 warnings / 1 risky — पूरी तरह ग्रीन नहीं** |

## सक्षम मॉड्यूल (README में पुष्टि की गई)

- **JWT प्रमाणीकरण**: लॉगिन/रिफ्रेश/लॉगआउट, क्लिक कैप्चा, खाता लॉक (5 असफल प्रयास पर 15 मिनट लॉक), समवर्ती सत्र सीमा (प्रति उपयोगकर्ता ≤3 Token)
- **RBAC**: भूमिका/अनुमति ट्री, method.path ग्रैन्युलैरिटी पर प्राधिकरण
- **ऑपरेशन ऑडिट**: लॉग क्वेरी + 8 प्लेटफ़ॉर्म स्रोतों की पहचान
- **फ़ाइल प्रबंधन**: अपलोड / Excel निर्यात / PDF निर्यात (मास्क किया हुआ)
- **i18n**: चीनी/अंग्रेज़ी स्विचिंग (Accept-Language / ?lang=)
- अन्य: डैशबोर्ड (Redis कैश), सिस्टम कॉन्फ़िगरेशन, हेल्थ चेक/metrics/OpenAPI 3.0, 18-परत सुरक्षा सुरक्षा

## टेस्ट विफलता विवरण (सभी मौजूदा प्रोजेक्ट कमियाँ हैं, इस बदलाव से नहीं आईं)

| टेस्ट समूह | विफलता | कारण |
|---|---|---|
| `EnvConfigTest` (5 आइटम) | 4 failure + 1 error | टेस्ट दावा करते हैं कि `.env`/`.env.example` का होना आवश्यक है और `APP_NAME`/`JWT_SECRET_KEY`/`DB_HOST` आदि के getenv मान सेट होने चाहिए; रिपॉज़िटरी में उदाहरण env शामिल नहीं है |
| `CaptchaTest` (4 आइटम) | 3 error + 1 failure (इसके अलावा 1 risky बिना assertion) | क्लिक कैप्चा Redis स्टोरेज पर निर्भर है, जो लोकल में उपलब्ध नहीं है |
| `BackendEnhancementTest` (2 आइटम) | 2 failure | दावा करता है कि `user` डेटा स्रोत में searchable और middleware में cors/rate_limit होना चाहिए — कॉन्फ़िगरेशन और टेस्ट assertion में ड्रिफ्ट |

पूरी तरह ग्रीन बहाल करने के लोकल चरण: `config/` में कॉन्फ़िगरेशन कुंजियों के अनुसार `.env` बनाएँ (EnvConfigTest पर निर्भर कुंजियाँ जोड़ें), MySQL + Redis प्रदान करें (CaptchaTest के लिए), और ज़िम्मेदार व्यक्ति BackendEnhancementTest के दो कॉन्फ़िगरेशन ड्रिफ्ट का निर्णय करे।

## gRPC तत्परता स्थिति (T3)

- Composer पैकेज इंस्टॉल: `grpc/grpc 1.82.0`, `google/protobuf 5.35` (`--no-plugins` से security-php प्लगइन डुप्लीकेट-लोडिंग बग से बचा गया)
- PHP स्टब जनरेट: `admin/generated/` (`Social/Admin/V1/AdminServiceClient.php` आदि, जिसमें infra/user तीन कॉन्ट्रैक्ट सेट शामिल हैं)
- **grpc PHP एक्सटेंशन इंस्टॉल नहीं**: pecl के पास लिखने की अनुमति नहीं और sudo के लिए पासवर्ड चाहिए; gRPC क्लाइंट चलाने से पहले `sudo pecl install grpc` आवश्यक है

## परिवर्तन के प्रवेश बिंदु (डिज़ाइन दस्तावेज़ §3.4 के आठ नए आइटम)

1. कंटेंट मॉडरेशन वर्कबेंच: पोस्ट/कमेंट/इमेज की द्विभाषी साथ-साथ समीक्षा, अस्वीकृति-कारण मल्टीभाषा टेम्पलेट, उपयोगकर्ता दंड
2. रिपोर्ट प्रोसेसिंग क्यू
3. GDPR अनुरोध डेस्क (निर्यात/हटाने के टिकट)
4. bee_tsdb के साथ डेटा डैशबोर्ड एकीकरण
5. i18n एंट्री प्रबंधन (चार क्लाइंटों के लिए साझा CRUD)
6. गिफ्ट लाइब्रेरी प्रबंधन (SKU, कीमत, इफ़ेक्ट, मल्टीभाषा नाम)
7. लाइव provider कॉन्फ़िगरेशन (रूटिंग रणनीति, स्विच क्रम)
8. विड्रॉल अनुरोध समीक्षा

**gRPC एकीकरण बिंदु**: admin पक्ष के कॉन्ट्रैक्ट स्टब `admin/generated/` में हैं (प्रोब + भविष्य के बिज़नेस मैसेज के लिए `Social/Admin/V1` का पुन: उपयोग); service के कॉल `Social\User\V1\UserServiceClient` से और infrastructure के कॉल `Social\Infra\V1\InfraServiceClient` से जाते हैं; service/infrastructure के साथ प्रोब श्रृंखला `service/README.grpcs.md` और T10 एकीकरण प्रोब में वर्णित है।
