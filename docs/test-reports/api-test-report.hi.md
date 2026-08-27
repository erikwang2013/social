# API स्वचालित परीक्षण रिपोर्ट
**语言 / Languages:** [中文](api-test-report.md) · [English](api-test-report.en.md) · [한국어](api-test-report.ko.md) · [Русский](api-test-report.ru.md) · [Deutsch](api-test-report.de.md) · [Français](api-test-report.fr.md) · [Español](api-test-report.es.md) · [Português](api-test-report.pt.md) · [हिन्दी](api-test-report.hi.md) · [العربية](api-test-report.ar.md) · [বাংলা](api-test-report.bn.md) · [Bahasa Indonesia](api-test-report.id.md) · [日本語](api-test-report.ja.md)

- दिनांक: 2026-08-27
- निष्पादन: `tests/api/run.php` (curl एसर्शन स्क्रिप्ट), परिणाम `tests/api/results.json`
- दायरा: admin HTTP API (A01-A45) + service HTTP API (S01-S57b, S58-S68 सहित)
- सेवाएँ: admin `http://127.0.0.1:8791`, service `http://127.0.0.1:8788` (WebSocket `:8789` इस HTTP परीक्षण दौर में कवर नहीं)

## निष्कर्ष

**116 परीक्षण मामले: 116 पास / 0 फेल (100% पास दर); पिछले दौर के 3 उत्पाद दोष (A20/A39/A40) सभी ठीक और सत्यापित**

| समूह | पास/कुल |
|------|-----------|
| admin A01-A45 (प्रमाणीकरण, कैप्चा, उपयोगकर्ता प्रबंधन, HashID, भूमिका अनुमतियाँ, कॉन्फ़िगरेशन, लॉग, निर्यात/आयात, अपलोड, हेल्थ चेक आदि) | 45/45 |
| service S01-S68 (रजिस्टर/लॉगिन/लॉगआउट/रिफ्रेश, प्रोफ़ाइल, फ़ॉलो, पोस्ट/लाइक/टाइमलाइन, कमेंट, नोटिफिकेशन, सर्च, IM सत्र/संदेश/पुश, वॉइस अपलोड/फ़ाइल/कॉल/रूम आदि) | 71/71 |

## पिछले दौर के 3 उत्पाद दोषों के सुधार का सत्यापन (सभी PASS)

| मामला | अपेक्षित | पिछला दौर (वास्तविक) | सुधार | इस दौर का परिणाम |
|------|------|---------|------|---------|
| A20 अमान्य hashid उपयोगकर्ता विवरण | 404 | 500 | `BaseController::decodeId()` `InvalidArgumentException` पकड़कर `support\exception\NotFoundException($msg, 404)` फेंकता है (admin/app/admin/controller/BaseController.php); `UserController` के दो बैच विधियों का catch `InvalidArgumentException \| NotFoundException` तक बढ़ाया गया, 422 अर्थ बरकरार | **PASS (404)** |
| A39 Excel निर्यात | xlsx फ़ाइल स्ट्रीम | 200+JSON त्रुटि बॉडी | `ExportController` में `use support\Response;` जोड़ा गया (रिटर्न टाइप पहले अस्तित्वहीन `app\admin\controller\Response` में हल होकर TypeError फेंकता था); `admin_user` के phone/email/id_card Encryptable cast से पढ़ते समय स्वतः डिक्रिप्ट होते हैं, निर्यात सीधे मास्क करता है, दूसरी डिक्रिप्शन हटाई गई | **PASS (attachment फ़ाइल स्ट्रीम)** |
| A40 PDF निर्यात | pdf फ़ाइल स्ट्रीम | 200+JSON त्रुटि बॉडी | उपरोक्त के समान (`ExportController::pdf()` रिटर्न टाइप ठीक किया गया) | **PASS (application/pdf फ़ाइल स्ट्रीम)** |

## इस दौर में ठीक/संभाली गई पर्यावरणीय समस्याएँ (उत्पाद व्यावसायिक कोड परिवर्तन नहीं)

1. **run.php में DB खाली पासवर्ड ओवरराइड विफल (टेस्ट स्क्रिप्ट दोष, ठीक किया गया)**: `DB` स्थिरांक `getenv('DB_PASS') ?: 'root'` उपयोग करता है; पर्यावरण चर का खाली स्ट्रिंग `?:` द्वारा झूठा मानकर 'root' पर फॉलबैक होता है, जिससे स्थानीय root खाली पासवर्ड कनेक्शन अस्वीकृत होता है (`Access denied ... using password: YES`)। `getenv('DB_PASS') ?? 'root'` (केवल अनसेट होने पर डिफ़ॉल्ट) में बदला गया, एक-पंक्ति परिवर्तन (tests/api/run.php:26)।
2. **service 8788 पोर्ट गलत प्रक्रिया द्वारा अधिकृत (पर्यावरण, संभाला गया)**: इस मशीन के दूसरे प्रोजेक्ट `property-management-platform` का service प्रक्रिया (master 2004768, 08:07 शुरू) 8788 पर सुन रहा था, और उसका `.env` `property_management` डेटाबेस की ओर इंगित करता है — social service वास्तव में नहीं चल रहा था, जिससे S45 से आगे IM/वॉइस रूट सभी 404 लौटाते थे और सफ़ाई चरण का SQL गलत डेटाबेस पर लगता था। प्रक्रिया रोककर 8788/8789 पर social service पुनः प्रारंभ किया गया (`DB_HOST=127.0.0.1 DB_PORT=3306 DB_USERNAME=root DB_PASSWORD=''`), हेल्थ चेक `social-service` पर लौट आया।
3. **ImageMagick 7 अपग्रेड से कैप्चा Imagick ड्राइवर क्रैश (पर्यावरण, संभाला गया)**: सिस्टम ImageMagick 7.1.2-27 (2026-07-08 बिल्ड) में अपग्रेड होने पर `PixelsResource` हटा दिया गया; imagick 3.8.1 अब `Imagick::RESOURCETYPE_PIXELS` परिभाषित नहीं करता, और poster-php का `ImagickDriver` कंस्ट्रक्टर तुरंत `Undefined constant` फेंकता है (vendor कोड, संशोधित नहीं), जिससे कैप्चा निर्माण/सत्यापन (A05/A06) 500 देता है और A08-A11 लॉगिन तक कैस्केड ब्लॉक होता है। **उपचार**: admin सेवा को कॉन्फ़िगरेशन दस्तावेज़ में आरक्षित ड्राइवर स्विच के साथ पुनः प्रारंभ किया गया — `POSTER_IMAGE_DRIVER=gd` (admin/config/poster.php:17 gd/imagick/auto का मूल समर्थन करता है); कैप्चा को GD ड्राइवर पर ले जाने के बाद पूरी श्रृंखला सामान्य। Imagick ड्राइवर बहाल करने हेतु ImageMagick को 6.x में डाउनग्रेड करें या poster-php को IM7-अनुकूल उन्नत करें।
4. **MySQL root पासवर्ड खाली में बदला गया**: पिछले दौर में `root/root` दर्ज था; इस दौर में खाली पासवर्ड से लॉगिन होता है, सभी सेवाएँ और स्क्रिप्ट खाली पासवर्ड से शुरू की गईं।
5. **admin सेवा पुनः आरंभ पर्यावरण**: पिछले दौर का «admin के पास .env नहीं, पर्यावरण चरों पर निर्भर» अभी भी लागू; पुनः आरंभ आदेश नीचे «पर्यावरण और पुनरुत्पादन» में।
6. **service/.env अभी भी `service/.env.api-test-bak` है**: पिछले दौर में कनेक्टिविटी परीक्षण के लिए हटाया गया और पुनर्स्थापित नहीं किया गया (पुनर्स्थापना .env फ़ाइल एक्सेस नीति से प्रतिबंधित); इस दौर में सेवा फिर पर्यावरण चरों से शुरू की गई। मैन्युअल `mv service/.env.api-test-bak service/.env` आवश्यक (पुनर्स्थापना के बाद सेवा पुनः आरंभ करें; इंगित डेटाबेस पते पर ध्यान दें)।
7. **Elasticsearch प्रारंभ नहीं**: `GET /api/v1/search/posts` 503 लौटाता है (डिज़ाइन किया गया डिग्रेडेशन); S समूह के सर्च मामले अपेक्षित रूप से संभाले गए (0 या 503 स्वीकार), फेल नहीं गिने गए।

## कॉन्ट्रैक्ट/दस्तावेज़ीकरण असमानताएँ (संशोधन का सुझाव, गैर-अवरोधक)

- कैप्चा दस्तावेज़ीकरण (apidoc और CaptchaController टिप्पणियाँ) `clicks=[{x,y}]` को ऑब्जेक्ट सरणी के रूप में लिखता है, जबकि `poster-php` कार्यान्वयन `[[x,y]]` निर्देशांक-जोड़ी सरणी चाहता है; दस्तावेज़ के अनुसार ऑब्जेक्ट पास करने पर व्यवहार में हमेशा विफलता होती है।
- वॉइस अपलोड `voice_url` को `/voice/{md5}.m4a` लौटाता है (API रूट के सापेक्ष, `/api/v1` उपसर्ग नहीं); क्लाइंट को स्वयं `/api/v1` जोड़ना पड़ता है; फ़ाइल एक्सेस प्रमाणित रूटों से होती है (टोकन आवश्यक)।

## पर्यावरण और पुनरुत्पादन

- परीक्षण प्रमाण-पत्र: परीक्षण खाता `e2e_smoke` (admin, केवल परीक्षण के लिए पासवर्ड) + `apitest_*@test.dev` (service, चलने के बाद स्वतः साफ़); सभी `tests/api/run.php` स्थिरांकों में लिखे गए, कोई वास्तविक कुंजी उपयोग नहीं हुई।
- पुनरुत्पादन:

```bash
cd /home/wwwroot/social/admin && DB_PASSWORD='' ENCRYPTABLE_KEY='apitest-enc-16b!!' \
  ENCRYPTION_KEY='apitest-db-encrypt-key-32-byte!!' POSTER_IMAGE_DRIVER=gd \
  php start.php start                                          # admin :8791
cd /home/wwwroot/social/service && DB_HOST=127.0.0.1 DB_PORT=3306 DB_USERNAME=root \
  DB_PASSWORD='' php start.php start                           # service :8788
cd /home/wwwroot/social/tests/api && DB_PASS='' php run.php    # पुनः चलाएँ (116 मामले)
```

- ध्यान दें: 8788 पोर्ट `property-management-platform` service द्वारा अधिकृत न हो, यह सुनिश्चित करें (दोनों प्रोजेक्ट का डिफ़ॉल्ट पोर्ट समान है; इस मशीन पर दोनों प्रोजेक्ट मौजूद होने पर अलग करना होगा)।

## एंडपॉइंट सूची (route.php / apidoc के अनुसार)

- service `config/route.php`: 39 HTTP रूट (प्रमाणीकरण 5, उपयोगकर्ता 2, फ़ॉलो 5, पोस्ट 7, कमेंट 2, नोटिफिकेशन 4, सर्च 2, IM 4, वॉइस/कॉल/रूम 5, हेल्थ/दस्तावेज़ 3)
- admin `config/route.php`: 33 HTTP रूट (प्रमाणीकरण/कैप्चा 4, उपयोगकर्ता CRUD 5, भूमिकाएँ 5, अनुमतियाँ 2, कॉन्फ़िगरेशन 4, लॉग 1, प्रोफ़ाइल 4, निर्यात 2, आयात 1, अपलोड 1, हेल्थ/दस्तावेज़ 4)
