# PHP ইউনিট টেস্ট রিপোর্ট
**语言 / Languages:** [中文](php-unit-report.md) · [English](php-unit-report.en.md) · [한국어](php-unit-report.ko.md) · [Русский](php-unit-report.ru.md) · [Deutsch](php-unit-report.de.md) · [Français](php-unit-report.fr.md) · [Español](php-unit-report.es.md) · [Português](php-unit-report.pt.md) · [हिन्दी](php-unit-report.hi.md) · [العربية](php-unit-report.ar.md) · [বাংলা](php-unit-report.bn.md) · [Bahasa Indonesia](php-unit-report.id.md) · [日本語](php-unit-report.ja.md)

- তারিখ: 2026-08-27
- নির্বাহ: `vendor/bin/phpunit` (PHPUnit 10.5.64, PHP 8.3.7)
- পরিধি: admin/ (webman অ্যাডমিন প্যানেল) + service/ (webman মূল পরিষেবা)

## ফলাফল সংক্ষিপ্ত বিবরণ

| প্রকল্প | টেস্ট কেস | অ্যাসারশন | ফলাফল |
|------|------|------|------|
| service | 136 | 348 | ✅ সব পাস (OK) |
| admin | 67 | 180 | ✅ সব পাস (OK) |

## পরিবেশের বিবরণ

- MySQL 127.0.0.1:3306 (root, খালি পাসওয়ার্ড); ডেটাবেস `social` (social_*) ও `open_admin` (erik_*) তৈরি ও ডেটা দেওয়া আছে (super_admin ভূমিকা, ৩৯টি অনুমতি)
- Redis 127.0.0.1:6379 চলছে (ক্যাপচা সংরক্ষণ `poster:captcha:*`); Elasticsearch চালু নেই (হেলথ চেক unavailable-এ ডাউনগ্রেড হয়, ব্যর্থতা হিসেবে ধরা হয় না)
- service 8788-এ, admin 8791-এ চলছে
- service ও admin উভয়েরই `.env` নেই (রিপোজিটরি ভুলবশত যোগ করা env মুছে দিয়েছে, commit e5379fc); অ্যাপগুলো `config/*.php`-এর `getenv('X') ?: ডিফল্ট মান` ফলব্যাকে চলে
- **Imagick এক্সটেনশন লোড আছে কিন্তু `RESOURCETYPE_PIXELS` কনস্ট্যান্ট নেই** (এই মেশিনের বিল্ডে শুধু নতুন RESOURCETYPE_* কনস্ট্যান্ট সেট আছে); poster-php-এর ImagickDriver কনস্ট্রাক্টর এই কনস্ট্যান্ট রেফার করে বলে সঙ্গে সঙ্গে ক্র্যাশ

## service (136/136 সম্পূর্ণ সবুজ)

- আগের ব্যাচের বেসলাইনের সাথে সামঞ্জস্যপূর্ণ; কভার: প্রমাণীকরণ/মিডলওয়্যার/JWT, ব্যবহারকারী, পোস্ট, কমেন্ট, ফলো, নোটিফিকেশন, সার্চ সিংক, IM, রুম, কল (CallCenter/CallState), ভয়েস, মডেল সম্পর্ক, অ্যাকশন হ্যান্ডলিং (WS)
- এই ব্যাচে কোড পরিবর্তন নেই, কোনো ব্যর্থতা নেই

## admin (আগের ব্যাচ 49/60 → এই ব্যাচ 67/67 সম্পূর্ণ সবুজ)

### সমাধান: প্রকৃত কোড ত্রুটি (1 স্থান)

| অবস্থান | মূল কারণ | সমাধান |
|------|------|------|
| `config/poster.php` | `image.driver` ডিফল্ট `auto`; DriverFactory Imagick এক্সটেনশন শনাক্ত করলেই ImagickDriver বেছে নেয়, কিন্তু এই মেশিনের Imagick-এ `RESOURCETYPE_PIXELS` কনস্ট্যান্ট নেই → ক্যাপচা তৈরি/পোস্টার সরাসরি 500 (লাইভ সার্ভিসও সমানভাবে ক্ষতিগ্রস্ত) | ড্রাইভার শনাক্তকরণে কনস্ট্যান্ট গার্ড যোগ: `getenv('POSTER_IMAGE_DRIVER') ?: (defined('Imagick::RESOURCETYPE_PIXELS') ? 'auto' : 'gd')`, কনস্ট্যান্ট না থাকলে স্বয়ংক্রিয় GD-তে ফলব্যাক |

### সমাধান: পুরনো অ্যাসারশন (বর্তমান কোড মিলিয়ে আপডেট)

| টেস্ট ফাইল | কেস | মূল কারণ | সংশোধন |
|----------|------|------|------|
| EnvConfigTest | env_file_exists / env_example_file_exists / getenv_reads_env_variables / config_env_keys_exist_in_dotenv (4 ফেল + 1 এরর) | `.env`/`.env.example`-এর অস্তিত্ব ও getenv মানের অ্যাসারশন; কিন্তু রিপোজিটরি env ফাইলগুলো মুছে ফেলেছে এবং সেগুলো পুনর্নির্মাণ করা যায় না | "`.env` ছাড়া চলা" চুক্তি হিসেবে পুনর্লিখিত: প্রতিটি `getenv()` কী-তে `?:` ডিফল্ট ফলব্যাক থাকতে হবে, ডিফল্ট কনফিগারেশন স্থানীয় সার্ভিসগুলোতে (127.0.0.1:3306/open_admin) নির্দেশ করে, গুরুত্বপূর্ণ কনফিগারেশনের টাইপ সঠিক |
| BackendEnhancementTest | test_admin_user_source_contains_searchable | AdminUser আর Searchable ট্রেইট ব্যবহার করে না (এখন `Erikwang2013\Encryptable\Encryptable` দিয়ে ফিল্ডের স্বচ্ছ এনক্রিপশন/ডিক্রিপশন; `toSearchableArray()` রয়ে গেছে) | Encryptable ট্রেইটের অ্যাসারশনে পরিবর্তন; toSearchableArray অ্যাসারশন আগে থেকেই পাস করত, রাখা হয়েছে |
| BackendEnhancementTest | test_middleware_config_contains_cors_and_rate_limit | `config/middleware.php` এখন `'@'` গ্লোবাল গ্রুপ কী ফরম্যাটে পরিবর্তিত, টপ-লেভেল অ্যারেতে আর সরাসরি মিডলওয়্যার ক্লাস থাকে না | `$middlewares['@']`-এ Cors ও RateLimit আছে কিনা তা যাচাই করতে পরিবর্তন |
| CaptchaTest | সব ৭টি কেস (মূলত ৬ এরর + ১ ফেল) | দ্বৈত পুরাতনতা: (ক) Imagick কনস্ট্যান্ট নেই (ইতিমধ্যে poster.php দিয়ে ঠিক); (খ) অ্যাসারশন পুরনো poster-php চুক্তির উপর ভিত্তি করে — `extra.targets` (x/y সহ) বদলে `extra.texts` (শুধু text+order), স্থানাঙ্ক শুধু স্টোরেজ লেয়ারে থাকে; ক্লিক ফরম্যাট `['x'=>, 'y'=>]` থেকে `[x, y]` সংখ্যা জোড়ায় পরিবর্তিত | বর্তমান চুক্তি অনুযায়ী পুনর্লিখিত: গঠন/কঠিনতার সংখ্যা (2/3/4)/ফিল্ড যাচাই, সঠিক ক্লিক Redis (`poster:captcha:{key}`-এর `data.targets`) থেকে স্থানাঙ্ক পড়ে যাচাই করে, ভুল ক্লিক ব্যর্থ, max_attempts (3) অতিক্রম করলে key খরচ/মুছে যায়, key-এর স্বতন্ত্রতা |

### নতুন টেস্ট (১টি ফাইল, ১২টি কেস)

`tests/AdminControllerTest.php` (কপিরাইট হেডারসহ), কভার:

- **BaseController::decodeId** (সদ্য ঠিক করা 404 আচরণ): encode/decode রাউন্ডট্রিপ সামঞ্জস্যপূর্ণ; অবৈধ hashid `support\exception\NotFoundException` code=404 সহ ছোড়ে; encodeIds শুধু ID ফিল্ড পরিবর্তন করে
- **RoleController**: super_admin ভূমিকার update 403 ফেরায় (বাস্তব DB ডেটা)
- **PermissionController::buildTree**: অনুমতি ট্রি নেস্টিং (২ স্তর) + সব নোড id hashid-কৃত
- **ConfigController**: group/key/value না থাকলে ভ্যালিডেশন 422; অবৈধ hashid 404 ছোড়ে
- **ExportController**: `admin_user` এক্সপোর্টের সংবেদনশীল ফিল্ড তালিকা phone/email/id_card (বাকি টেবিল খালি); PDF HTML শিরোনাম/সেল মান htmlspecialchars দিয়ে escape করে (XSS সুরক্ষা) এবং কপিরাইট ঘোষণা অন্তর্ভুক্ত করে

### জানা বিবরণ

- টেস্টে তৈরি করা webman Request কাঁচা HTTP মেসেজ (buffer) হিসেবে পাঠানো হয় — workerman-এর Request কনস্ট্রাক্টর প্যারামিটার buffer; শুধু method/uri দিলে POST বডি পার্স করা যায় না, AdminControllerTest মন্তব্য দেখুন
- ক্যাপচা সঠিক-ক্লিক কেস Redis থেকে সংরক্ষিত টার্গেট পড়ে; Redis অনুপলব্ধ হলে কেসটি markTestSkipped হয়, স্যুটের ফলাফলে প্রভাব পড়ে না

## আচ্ছাদিত নয় / পরে যোগ করতে হবে

- admin মডেলগুলোর Encryptable এনক্রিপশন/ডিক্রিপশন, OperationLog/AdminPermission মিডলওয়্যার ও RBAC ক্যাশ পথগুলোতে এখনও ইউনিট টেস্টের অভাব; API টেস্ট বা পরবর্তী ব্যাচে কভার করার পরামর্শ
- বাহ্যিক সার্ভিস (ES/gRPC) নির্ভর service পথগুলো এখনও শুধু ইউনিট-স্তরের stub যাচাই; ইন্টিগ্রেশন-স্তর API টেস্ট দিয়ে কভার হয়
