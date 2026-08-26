# PHP ইউনিট টেস্ট রিপোর্ট
**语言 / Languages:** [中文](php-unit-report.md) · [English](php-unit-report.en.md) · [한국어](php-unit-report.ko.md) · [Русский](php-unit-report.ru.md) · [Deutsch](php-unit-report.de.md) · [Français](php-unit-report.fr.md) · [Español](php-unit-report.es.md) · [Português](php-unit-report.pt.md) · [हिन्दी](php-unit-report.hi.md) · [العربية](php-unit-report.ar.md) · [বাংলা](php-unit-report.bn.md) · [Bahasa Indonesia](php-unit-report.id.md) · [日本語](php-unit-report.ja.md)

- তারিখ: 2026-08-27
- নির্বাহ: `vendor/bin/phpunit` (PHPUnit 10.5.64, PHP 8.3.7)
- পরিধি: admin/ (webman অ্যাডমিন প্যানেল) + service/ (webman মূল পরিষেবা)

## ফলাফল সংক্ষিপ্ত বিবরণ

| প্রকল্প | টেস্ট কেস | অ্যাসারশন | ফলাফল |
|------|------|------|------|
| service | 136 | 348 | ✅ সব পাস (OK) |
| admin | 60 | 136 | ⚠️ 49 পাস / 4 এরর / 7 ফেল |

## service (সম্পূর্ণ সবুজ)

- নতুন টেস্ট ফাইল (এই ব্যাচে): AuthMiddlewareTest, UserBriefTest, SearchSyncTest, ActionHandlerTest, JwtHelperTest, VoiceControllerTest, MonitorTest, ModelRelationTest ইত্যাদি; বিদ্যমান 24টি টেস্ট ফাইলের সাথে মিশে মোট 136টি কেস, সব পাস
- আচ্ছাদিত মডিউল: প্রমাণীকরণ/মিডলওয়্যার/JWT, ব্যবহারকারী, পোস্ট, কমেন্ট, ফলো, নোটিফিকেশন, সার্চ সিংক, IM, রুম, কল (CallCenter/CallState), ভয়েস, মডেল সম্পর্ক, অ্যাকশন হ্যান্ডলিং (WS)

### সমাধান: টেস্ট স্যুটের এলোমেলো হ্যাং (গুরুত্বপূর্ণ)

- লক্ষণ: পূর্ণ রানে প্রসেস এলোমেলোভাবে জমে যায়; একক ফাইল/উপসেট চালালে পাস
- মূল কারণ: `ActionHandlerTest::setUp`-এর `new Worker()` ইনস্ট্যান্সকে `Worker::$workers` **স্ট্যাটিক রেজিস্ট্রিতে** নিবন্ধিত করে; এরপর যেকোনো `CallCenter::start` "Worker আছে" দেখে `Timer::add` কল করে → `pcntl_alarm(1)` SIGALRM টাইমার স্থাপন করে, প্রসেস বের হওয়ার সময় হ্যাং
- সমাধান: setUp রেজিস্ট্রির স্ন্যাপশট নেয়, tearDown পুনরুদ্ধার করে (`ReflectionProperty` দিয়ে `workers`/`pidMap` ফিরিয়ে লেখে)
- অবস্থান: `service/tests/ActionHandlerTest.php`

## admin (49/60; ফেলগুলো সব পূর্ব-বিদ্যমান টেস্ট এবং পরিবেশ/কনফিগারেশন সমস্যা)

| টেস্ট কেস | ব্যর্থতার কারণ | শ্রেণি |
|------|----------|------|
| EnvConfigTest (4 ফেল + 1 এরর) | `admin/.env` নেই, getenv/dotenv অ্যাসারশন ব্যর্থ | টেস্ট পরিবেশে .env নেই |
| CaptchaTest (3 এরর + 1 ফেল + 1 risky) | ক্যাপচা চলমান সার্ভিস/Redis-এর উপর নির্ভরশীল, ইউনিট টেস্ট পরিবেশ null ফেরায় | পরিবেশ নির্ভরতা |
| BackendEnhancementTest (2 ফেল) | `app/middleware/Cors`-এর অস্তিত্ব ও admin_user-এ searchable থাকার অ্যাসারশন — বর্তমান কনফিগারেশন অ্যাসারশনের সাথে মেলে না | কনফিগারেশন অ্যাসারশন পুরনো |

দ্রষ্টব্য: admin/tests সব ঐতিহাসিক পূর্ব-বিদ্যমান ফাইল; এই ব্যাচে admin-এর নতুন ইউনিট টেস্ট যোগ করা হয়নি (ফোকাস service-এ ছিল)।

## আচ্ছাদিত নয় / পরে যোগ করতে হবে

- admin-এর মডিউলগুলোতে (model/middleware/view) ইউনিট টেস্টের অভাব
- বাহ্যিক সার্ভিস (ES/gRPC) নির্ভর service পথগুলোতে শুধু ইউনিট-স্তরের stub যাচাই হয়েছে; ইন্টিগ্রেশন-স্তর API টেস্ট দিয়ে কভার করার পরামর্শ
