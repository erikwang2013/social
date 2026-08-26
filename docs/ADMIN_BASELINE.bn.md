# Admin বেসলাইন গ্রহণযোগ্যতা (M0, 2026-08-17)

**语言 / Languages:** [中文](ADMIN_BASELINE.md) · [English](ADMIN_BASELINE.en.md) · [한국어](ADMIN_BASELINE.ko.md) · [Русский](ADMIN_BASELINE.ru.md) · [Deutsch](ADMIN_BASELINE.de.md) · [Français](ADMIN_BASELINE.fr.md) · [Español](ADMIN_BASELINE.es.md) · [Português](ADMIN_BASELINE.pt.md) · [हिन्दी](ADMIN_BASELINE.hi.md) · [العربية](ADMIN_BASELINE.ar.md) · [বাংলা](ADMIN_BASELINE.bn.md) · [Bahasa Indonesia](ADMIN_BASELINE.id.md) · [日本語](ADMIN_BASELINE.ja.md)

open-admin (webman v2 + Flutter অ্যাডমিন কনসোল)-এর বেসলাইন অবস্থা ও রূপান্তরের প্রবেশ বিন্দু।

## বর্তমান সংস্করণ ও রানটাইম অবস্থা

| আইটেম | মান |
|---|---|
| ফ্রেমওয়ার্ক | webman v2 (workerman/webman-framework **v2.2.3**) |
| PHP | 8.3.7 (CLI) |
| নির্ভরতা | `composer install` সফল, ৬৯টি প্যাকেজ |
| .env | **অস্তিত্ব নেই** (রিপোজিটরিতে `.env` বা `.env.example` নেই; MySQL/Redis অনুযায়ী লোকালি তৈরি করতে হবে) |
| মাইগ্রেশন প্রবেশ | নেই (`think`/`artisan` নেই; webman-এ বিল্ট-ইন মাইগ্রেশন নেই, M0-তে মাইগ্রেশন কাজ নেই) |
| টেস্ট | `vendor/bin/phpunit`: 60 tests / 136 assertions, **4 errors / 7 failures / 6 warnings / 1 risky — সম্পূর্ণ সবুজ নয়** |

## সক্রিয় মডিউল (README-তে নিশ্চিত)

- **JWT প্রমাণীকরণ**: লগইন/রিফ্রেশ/লগআউট, ক্লিক ক্যাপচা, অ্যাকাউন্ট লক (৫টি ব্যর্থ প্রচেষ্টায় ১৫ মিনিট লক), সমান্তরাল সেশন সীমা (প্রতি ব্যবহারকারী ≤3 টোকেন)
- **RBAC**: ভূমিকা/অনুমতি ট্রি, method.path গ্র্যানুলারিটিতে অনুমোদন
- **অপারেশন অডিট**: লগ কুয়েরি + ৮টি প্ল্যাটফর্ম সোর্স শনাক্তকরণ
- **ফাইল ব্যবস্থাপনা**: আপলোড / Excel এক্সপোর্ট / PDF এক্সপোর্ট (মাস্ক করা)
- **i18n**: চীনা/ইংরেজি সুইচিং (Accept-Language / ?lang=)
- অন্যান্য: ড্যাশবোর্ড (Redis ক্যাশ), সিস্টেম কনফিগারেশন, হেলথ চেক/metrics/OpenAPI 3.0, ১৮ স্তরের নিরাপত্তা সুরক্ষা

## টেস্ট ব্যর্থতার বিবরণ (সবগুলোই বিদ্যমান প্রজেক্ট ফাঁক, এই পরিবর্তনে আনা হয়নি)

| টেস্ট গ্রুপ | ব্যর্থতা | কারণ |
|---|---|---|
| `EnvConfigTest` (৫টি) | 4 failure + 1 error | টেস্টগুলো দাবি করে `.env`/`.env.example` অবশ্যই থাকতে হবে এবং `APP_NAME`/`JWT_SECRET_KEY`/`DB_HOST` ইত্যাদির getenv মান সেট থাকতে হবে; রিপোজিটরিতে উদাহরণ env নেই |
| `CaptchaTest` (৪টি) | 3 error + 1 failure (আরও 1 risky অ্যাসারশনহীন) | ক্লিক ক্যাপচা Redis স্টোরেজের উপর নির্ভরশীল, লোকালি প্রদান করা হয়নি |
| `BackendEnhancementTest` (২টি) | 2 failure | দাবি করে `user` ডেটা সোর্সে searchable এবং মিডলওয়্যারে cors/rate_limit থাকবে — কনফিগ ও টেস্ট অ্যাসারশনের মধ্যে ড্রিফট |

সবুজ অবস্থা ফিরিয়ে আনতে লোকাল ধাপ: `config/`-এর কনফিগ কী অনুযায়ী `.env` তৈরি করুন (EnvConfigTest যেসব কী-এর উপর নির্ভর করে সেগুলো যোগ করুন), MySQL + Redis প্রদান করুন (CaptchaTest-এর জন্য), তারপর দায়িত্বপ্রাপ্ত ব্যক্তি BackendEnhancementTest-এর দুটি কনফিগ ড্রিফটের সিদ্ধান্ত নেবেন।

## gRPC প্রস্তুতি অবস্থা (T3)

- Composer প্যাকেজ ইনস্টল: `grpc/grpc 1.82.0`, `google/protobuf 5.35` (`--no-plugins` দিয়ে security-php প্লাগইনের ডুপ্লিকেট-লোডিং বাগ এড়ানো হয়েছে)
- PHP স্টাব তৈরি: `admin/generated/` (`Social/Admin/V1/AdminServiceClient.php` ইত্যাদি, যাতে infra/user তিন সেট কন্ট্রাক্ট আছে)
- **grpc PHP এক্সটেনশন ইনস্টল নেই**: pecl-এর লেখার অনুমতি নেই এবং sudo-তে পাসওয়ার্ড লাগে; gRPC ক্লায়েন্ট চালানোর আগে `sudo pecl install grpc` প্রয়োজন

## রূপান্তরের প্রবেশ বিন্দু (ডিজাইন ডক §3.4-এর আটটি নতুন আইটেম)

1. কনটেন্ট মডারেশন ওয়ার্কবেঞ্চ: পোস্ট/কমেন্ট/ছবির দ্বিভাষিক পাশাপাশি পর্যালোচনা, প্রত্যাখ্যান-কারণ বহুভাষিক টেমপ্লেট, ব্যবহারকারী শাস্তি
2. রিপোর্ট প্রসেসিং কিউ
3. GDPR অনুরোধ ডেস্ক (এক্সপোর্ট/ডিলিট টিকিট)
4. bee_tsdb-এর সাথে ডেটা ড্যাশবোর্ড ইন্টিগ্রেশন
5. i18n এন্ট্রি ম্যানেজমেন্ট (চার ক্লায়েন্টের জন্য শেয়ার্ড CRUD)
6. গিফট লাইব্রেরি ম্যানেজমেন্ট (SKU, দাম, ইফেক্ট, বহুভাষিক নাম)
7. লাইভ provider কনফিগ (রাউটিং কৌশল, সুইচ অর্ডার)
8. উইথড্রয়াল অনুরোধ পর্যালোচনা

**gRPC ইন্টিগ্রেশন পয়েন্ট**: admin পাশের কন্ট্রাক্ট স্টাব `admin/generated/`-এ আছে (প্রোব + পরবর্তী বিজনেস মেসেজের জন্য `Social/Admin/V1` পুনঃব্যবহার); service-এর কল `Social\User\V1\UserServiceClient` দিয়ে এবং infrastructure-এর কল `Social\Infra\V1\InfraServiceClient` দিয়ে যায়; service/infrastructure-এর সাথে প্রোব চেইন `service/README.grpcs.md` ও T10 ইন্টিগ্রেশন প্রোবে বর্ণিত।
