# API स्वचालित परीक्षण रिपोर्ट
**语言 / Languages:** [中文](api-test-report.md) · [English](api-test-report.en.md) · [한국어](api-test-report.ko.md) · [Русский](api-test-report.ru.md) · [Deutsch](api-test-report.de.md) · [Français](api-test-report.fr.md) · [Español](api-test-report.es.md) · [Português](api-test-report.pt.md) · [हिन्दी](api-test-report.hi.md) · [العربية](api-test-report.ar.md) · [বাংলা](api-test-report.bn.md) · [Bahasa Indonesia](api-test-report.id.md) · [日本語](api-test-report.ja.md)

- दिनांक: 2026-08-27
- निष्पादन: `tests/api/run.php` (curl एसर्शन स्क्रिप्ट), परिणाम `tests/api/results.json`
- दायरा: admin HTTP API (A01-A45) + service HTTP API (S01-S57b, S58-S68 सहित)
- सेवाएँ: admin `http://127.0.0.1:8791`, service `http://127.0.0.1:8788` (WebSocket `:8789` इस HTTP परीक्षण दौर में कवर नहीं)

## निष्कर्ष

**116 परीक्षण मामले: 113 पास / 3 फेल (97.4% पास दर); 3 फेल सभी पहचाने गए मूल कारण वाले उत्पाद दोष हैं**

| समूह | पास/कुल |
|------|-----------|
| admin A01-A45 (प्रमाणीकरण, कैप्चा, उपयोगकर्ता प्रबंधन, HashID, भूमिका अनुमतियाँ, कॉन्फ़िगरेशन, लॉग, निर्यात/आयात, अपलोड, हेल्थ चेक आदि) | 42/45 |
| service S01-S68 (रजिस्टर/लॉगिन/लॉगआउट/रिफ्रेश, प्रोफ़ाइल, फ़ॉलो, पोस्ट/लाइक/टाइमलाइन, कमेंट, नोटिफिकेशन, सर्च, IM सत्र/संदेश/पुश, वॉइस अपलोड/फ़ाइल/कॉल/रूम आदि) | 71/71 |

## फेल परीक्षण मामले (3, सभी उत्पाद दोष)

| मामला | अपेक्षित | वास्तविक | मूल कारण |
|------|------|------|------|
| A20 अमान्य hashid उपयोगकर्ता विवरण | 404 | 500 | `HashidsService::decode()` अमान्य ID के लिए अनकैच्ड `InvalidArgumentException` फेंकता है (admin/app/common/HashidsService.php:28, BaseController.php:52); अपवाद 500 के रूप में पारित होता है, इसे पकड़कर 404 लौटाना चाहिए |
| A39 Excel निर्यात | xlsx फ़ाइल स्ट्रीम | 200+JSON त्रुटि बॉडी (व्यावसायिक विफलता) | `ExportController::excel()` रिटर्न टाइप `: Response` घोषित करता है परंतु `use support\Response` नहीं है, टाइप `app\admin\controller\Response` के रूप में हल होता है → कोई भी सफल रिटर्न `TypeError` फेंकता है (ExportController.php:122), निर्यात सुविधा पूरी तरह अनुपयोगी |
| A40 PDF निर्यात | pdf फ़ाइल स्ट्रीम | 200+JSON त्रुटि बॉडी (व्यावसायिक विफलता) | उपरोक्त के समान, `ExportController::pdf()` (ExportController.php:135) में `use support\Response` नहीं है |

> अतिरिक्त टिप्पणी (उसी फ़ाइल में संभावित दोष, वर्तमान में उपरोक्त TypeError से ढका हुआ): `ExportController` पंक्ति 90 phone/email पर `EncryptionService::decrypt()` कॉल करता है, जबकि `AdminUser` मॉडल के `email/phone/id_card` फ़ील्ड `Encryptable::class` कास्ट घोषित करते हैं (लिखते समय स्वतः एन्क्रिप्ट, पढ़ते समय स्वतः डिक्रिप्ट); निर्यात प्लेनटेक्स्ट को दूसरी बार डिक्रिप्ट करेगा → जैसे ही गैर-रिक्त फ़ोन/ईमेल वाला कोई खाता मौजूद होगा, `EncryptionException: Invalid ciphertext prefix for AES-256-CBC` फेंकेगा। रिटर्न टाइप ठीक करने के बाद भी यह समस्या दोहराई जाएगी।

## परीक्षण के दौरान ठीक की गई पर्यावरणीय समस्याएँ (उत्पाद कोड परिवर्तन नहीं)

1. **m2/m3/m4 माइग्रेशन तालिकाओं के `id` में AUTO_INCREMENT नहीं (अवरोधक, ठीक किया गया)**: `service/database/m2.sql`/`m3.sql`/`m4.sql` द्वारा बनाई गई `social_follows`, `social_notifications` का `id BIGINT UNSIGNED NOT NULL` बिना `AUTO_INCREMENT` है; हर INSERT में `1364 Field 'id' doesn't have a default value` त्रुटि होती है, जो फ़ॉलो/नोटिफिकेशन/IM/वॉइस की सभी लेखन पथों को अवरुद्ध करती है। स्थानीय रूप से `ALTER TABLE ... MODIFY id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` निष्पादित किया गया (शेष 8 तालिकाओं में पहले से ऑटो-इंक्रीमेंट है)। **माइग्रेशन स्क्रिप्ट में स्वयं ऑटो-इंक्रीमेंट जोड़ने की अनुशंसा है।**
2. **service/.env अगम्य डेटाबेस की ओर इंगित करता है (अवरोधक)**: `DB_PORT=13306` और कोई पासवर्ड नहीं, जबकि मुख्य MySQL वास्तव में `127.0.0.1:3306 (root/root)` पर है; webman का `createUnsafeMutable` CLI पर्यावरण चरों को ओवरराइड करता है। परीक्षण के दौरान `.env` को `service/.env.api-test-bak` में स्थानांतरित किया गया (सामग्री ज्यों की त्यों रखी गई) और सेवा पर्यावरण चर इंजेक्ट करके शुरू की गई; .env फ़ाइल एक्सेस नीति प्रतिबंधों के कारण पुनर्स्थापना नहीं की जा सकी, मैन्युअल `mv service/.env.api-test-bak service/.env` आवश्यक है (ध्यान दें: पुनर्स्थापना के बाद सेवा पुनः आरंभ करने पर अगम्य डेटाबेस से फिर टकराएगी)।
3. **admin के पास .env नहीं, पर्यावरण चरों पर निर्भर है**: `DB_PASSWORD=root ENCRYPTABLE_KEY(16B) ENCRYPTION_KEY(32B)` की आवश्यकता है। webman कंटेनर में provider पंजीकृत न होने पर `encryptable` प्लगइन `EnvEncryptableConfig` पर फॉलबैक करता है (`ENCRYPTION_KEY` पढ़ता है, डिफ़ॉल्ट cipher aes-256-gcm); कुंजी लंबाई मेल न खाने पर खाता निर्माण/आयात/निर्यात में `MissingEncryptionKeyException` आती है।
4. **Elasticsearch प्रारंभ नहीं**: `GET /api/v1/search/posts` 503 लौटाता है (डिज़ाइन किया गया डिग्रेडेशन); S समूह के सर्च मामले अपेक्षित रूप से संभाले गए (0 या 503 स्वीकार), फेल नहीं गिने गए।

## कॉन्ट्रैक्ट/दस्तावेज़ीकरण असमानताएँ (संशोधन का सुझाव, गैर-अवरोधक)

- कैप्चा दस्तावेज़ीकरण (apidoc और CaptchaController टिप्पणियाँ) `clicks=[{x,y}]` को ऑब्जेक्ट सरणी के रूप में लिखता है, जबकि `poster-php` कार्यान्वयन `[[x,y]]` निर्देशांक-जोड़ी सरणी चाहता है; दस्तावेज़ के अनुसार ऑब्जेक्ट पास करने पर व्यवहार में हमेशा विफलता होती है।
- वॉइस अपलोड `voice_url` को `/voice/{md5}.m4a` लौटाता है (API रूट के सापेक्ष, `/api/v1` उपसर्ग नहीं); क्लाइंट को स्वयं `/api/v1` जोड़ना पड़ता है; फ़ाइल एक्सेस प्रमाणित रूटों से होती है (टोकन आवश्यक)।

## पर्यावरण और पुनरुत्पादन

- परीक्षण प्रमाण-पत्र: परीक्षण खाता `e2e_smoke` (admin, केवल परीक्षण के लिए पासवर्ड) + `apitest_*@test.dev` (service, चलने के बाद स्वतः साफ़); सभी `tests/api/run.php` स्थिरांकों में लिखे गए, कोई वास्तविक कुंजी उपयोग नहीं हुई।
- पुनरुत्पादन:

```bash
cd /home/wwwroot/social/admin && DB_PASSWORD=root ENCRYPTABLE_KEY='apitest-enc-16b!!' \
  ENCRYPTION_KEY='apitest-db-encrypt-key-32-byte!!' php start.php start   # admin :8791
cd /home/wwwroot/social/service && DB_HOST=127.0.0.1 DB_PORT=3306 DB_USERNAME=root \
  DB_PASSWORD=root php start.php start                                     # service :8788
php /home/wwwroot/social/tests/api/run.php                                  # पुनः चलाएँ (116 मामले)
```

## एंडपॉइंट सूची (route.php / apidoc के अनुसार)

- service `config/route.php`: 39 HTTP रूट (प्रमाणीकरण 5, उपयोगकर्ता 2, फ़ॉलो 5, पोस्ट 7, कमेंट 2, नोटिफिकेशन 4, सर्च 2, IM 4, वॉइस/कॉल/रूम 5, हेल्थ/दस्तावेज़ 3)
- admin `config/route.php`: 33 HTTP रूट (प्रमाणीकरण/कैप्चा 4, उपयोगकर्ता CRUD 5, भूमिकाएँ 5, अनुमतियाँ 2, कॉन्फ़िगरेशन 4, लॉग 1, प्रोफ़ाइल 4, निर्यात 2, आयात 1, अपलोड 1, हेल्थ/दस्तावेज़ 4)
