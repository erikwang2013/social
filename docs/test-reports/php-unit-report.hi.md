# PHP यूनिट परीक्षण रिपोर्ट
**语言 / Languages:** [中文](php-unit-report.md) · [English](php-unit-report.en.md) · [한국어](php-unit-report.ko.md) · [Русский](php-unit-report.ru.md) · [Deutsch](php-unit-report.de.md) · [Français](php-unit-report.fr.md) · [Español](php-unit-report.es.md) · [Português](php-unit-report.pt.md) · [हिन्दी](php-unit-report.hi.md) · [العربية](php-unit-report.ar.md) · [বাংলা](php-unit-report.bn.md) · [Bahasa Indonesia](php-unit-report.id.md) · [日本語](php-unit-report.ja.md)

- दिनांक: 2026-08-27
- निष्पादन: `vendor/bin/phpunit` (PHPUnit 10.5.64, PHP 8.3.7)
- दायरा: admin/ (webman एडमिन पैनल) + service/ (webman मुख्य सेवा)

## निष्कर्ष अवलोकन

| प्रोजेक्ट | परीक्षण मामले | एसर्शन | परिणाम |
|------|------|------|------|
| service | 136 | 348 | ✅ सभी पास (OK) |
| admin | 67 | 180 | ✅ सभी पास (OK) |

## पर्यावरण नोट

- MySQL 127.0.0.1:3306 (root, खाली पासवर्ड); डेटाबेस `social` (social_*) और `open_admin` (erik_*) बनकर डेटा से भरे हुए (super_admin भूमिका, 39 अनुमतियाँ)
- Redis 127.0.0.1:6379 चालू (कैप्चा भंडारण `poster:captcha:*`); Elasticsearch शुरू नहीं (हेल्थ चेक unavailable पर डाउनग्रेड होता है, विफलता नहीं माना जाता)
- service 8788 पर, admin 8791 पर चल रहा है
- service और admin दोनों के पास `.env` नहीं (रिपॉजिटरी ने गलती से जोड़े गए env हटा दिए, commit e5379fc); ऐप्स `config/*.php` में `getenv('X') ?: डिफ़ॉल्ट मान` फ़ॉलबैक पर चलते हैं
- **Imagick एक्सटेंशन लोड है लेकिन `RESOURCETYPE_PIXELS` कॉन्स्टेंट गायब है** (इस मशीन के बिल्ड में केवल नया RESOURCETYPE_* कॉन्स्टेंट सेट है); poster-php का ImagickDriver कंस्ट्रक्टर इस कॉन्स्टेंट को रेफर करता है और तुरंत क्रैश होता है

## service (136/136 पूर्ण रूप से हरा)

- पिछले बैच की बेसलाइन के अनुरूप; कवर: प्रमाणीकरण/मिडलवेयर/JWT, उपयोगकर्ता, पोस्ट, कमेंट, फ़ॉलो, नोटिफिकेशन, सर्च सिंक, IM, रूम, कॉल (CallCenter/CallState), वॉइस, मॉडल संबंध, एक्शन हैंडलिंग (WS)
- इस बैच में कोई कोड बदलाव नहीं, कोई विफलता नहीं

## admin (पिछला बैच 49/60 → यह बैच 67/67 पूर्ण रूप से हरा)

### सुधार: वास्तविक कोड दोष (1 स्थान)

| स्थान | मूल कारण | सुधार |
|------|------|------|
| `config/poster.php` | `image.driver` डिफ़ॉल्ट `auto`; DriverFactory Imagick एक्सटेंशन का पता लगते ही ImagickDriver चुनता है, लेकिन इस मशीन के Imagick में `RESOURCETYPE_PIXELS` कॉन्स्टेंट नहीं → कैप्चा जनरेशन/पोस्टर सीधे 500 (लाइव सेवा भी उतनी ही प्रभावित) | ड्राइवर डिटेक्शन में कॉन्स्टेंट गार्ड जोड़ा: `getenv('POSTER_IMAGE_DRIVER') ?: (defined('Imagick::RESOURCETYPE_PIXELS') ? 'auto' : 'gd')`; कॉन्स्टेंट गायब होने पर स्वतः GD पर फ़ॉलबैक |

### सुधार: पुराने एसर्शन (वर्तमान कोड से मिलान करने के बाद अपडेट)

| परीक्षण फ़ाइल | मामला | मूल कारण | संशोधन |
|----------|------|------|------|
| EnvConfigTest | env_file_exists / env_example_file_exists / getenv_reads_env_variables / config_env_keys_exist_in_dotenv (4 फेल + 1 त्रुटि) | `.env`/`.env.example` के अस्तित्व और getenv मानों का एसर्शन; लेकिन रिपॉजिटरी ने env फ़ाइलें हटा दीं और उन्हें दोबारा नहीं बनाया जा सकता | "बिना .env चलने" वाले कॉन्ट्रैक्ट के रूप में फिर से लिखा: हर `getenv()` कुंजी पर `?:` डिफ़ॉल्ट फ़ॉलबैक होना चाहिए, डिफ़ॉल्ट कॉन्फ़िगरेशन स्थानीय सेवाओं (127.0.0.1:3306/open_admin) की ओर इशारा करता है, प्रमुख कॉन्फ़िगरेशन के प्रकार सही |
| BackendEnhancementTest | test_admin_user_source_contains_searchable | AdminUser अब Searchable ट्रेट का उपयोग नहीं करता (अब `Erikwang2013\Encryptable\Encryptable` से फ़ील्ड्स का पारदर्शी एन्क्रिप्शन/डिक्रिप्शन; `toSearchableArray()` बरकरार) | Encryptable ट्रेट का एसर्शन करने के लिए बदला; toSearchableArray एसर्शन पहले से पास होता था, बरकरार |
| BackendEnhancementTest | test_middleware_config_contains_cors_and_rate_limit | `config/middleware.php` अब `'@'` ग्लोबल ग्रुप की फॉर्मेट का उपयोग करता है; टॉप-लेवल ऐरे में मिडलवेयर क्लासेस सीधे नहीं रहीं | एसर्शन बदलकर जाँच करता है कि `$middlewares['@']` में Cors और RateLimit शामिल हैं |
| CaptchaTest | सभी 7 मामले (मूल रूप से 6 त्रुटियाँ + 1 फेल) | दोहरी पुरानापन: (क) Imagick कॉन्स्टेंट गायब (poster.php द्वारा पहले ही ठीक); (ख) एसर्शन पुराने poster-php कॉन्ट्रैक्ट पर आधारित — `extra.targets` (x/y सहित) बदलकर `extra.texts` (केवल text+order) हुआ, निर्देशांक सिर्फ़ स्टोरेज लेयर में रहते हैं; क्लिक फ़ॉर्मेट `['x'=>, 'y'=>]` से `[x, y]` संख्या जोड़े में बदला | वर्तमान कॉन्ट्रैक्ट के अनुसार फिर से लिखा: संरचना/कठिनाई संख्या (2/3/4)/फ़ील्ड सत्यापन; सही क्लिक Redis (`poster:captcha:{key}` के `data.targets`) से निर्देशांक पढ़कर सत्यापित करता है, गलत क्लिक फेल, max_attempts (3) पार करने पर key खपत/हट जाती है, key की विशिष्टता |

### नए परीक्षण (1 फ़ाइल, 12 मामले)

`tests/AdminControllerTest.php` (कॉपीराइट हेडर सहित), कवर:

- **BaseController::decodeId** (अभी-अभी ठीक किया गया 404 व्यवहार): encode/decode राउंडट्रिप संगत; अमान्य hashid `support\exception\NotFoundException` को code=404 के साथ फेंकता है; encodeIds केवल ID फ़ील्ड बदलता है
- **RoleController**: super_admin भूमिका का update 403 लौटाता है (वास्तविक DB डेटा)
- **PermissionController::buildTree**: अनुमति ट्री नेस्टिंग (2 स्तर) + सभी नोड id hashid-कृत
- **ConfigController**: group/key/value गायब होने पर वैलिडेशन 422; अमान्य hashid 404 फेंकता है
- **ExportController**: `admin_user` निर्यात संवेदनशील फ़ील्ड सूची phone/email/id_card है (अन्य टेबल खाली); PDF HTML शीर्षक/सेल मानों को htmlspecialchars से escape करता है (XSS सुरक्षा) और कॉपीराइट घोषणा शामिल करता है

### ज्ञात नोट

- परीक्षणों में बनाया गया webman Request कच्चे HTTP संदेश (buffer) के रूप में भेजा जाता है — workerman Request कंस्ट्रक्टर पैरामीटर buffer है, केवल method/uri देने से POST बॉडी पार्स नहीं हो सकती; AdminControllerTest टिप्पणियाँ देखें
- कैप्चा सही-क्लिक मामला Redis से संग्रहीत लक्ष्य पढ़ता है; Redis उपलब्ध न होने पर मामला markTestSkipped होता है, सूट परिणाम पर प्रभाव नहीं

## अकवर्ड / जोड़ने योग्य

- admin मॉडल्स का Encryptable एन्क्रिप्शन/डिक्रिप्शन, OperationLog/AdminPermission मिडलवेयर और RBAC कैश रास्ते अभी भी यूनिट टेस्ट से रहित हैं; API परीक्षणों या अगले बैच से कवर करने की अनुशंसा
- बाहरी सेवाओं (ES/gRPC) पर निर्भर service के रास्ते अभी भी केवल यूनिट-स्तरीय stub सत्यापन हैं; एकीकरण स्तर API परीक्षणों से कवर होता है
