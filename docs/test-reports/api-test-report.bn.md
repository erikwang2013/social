# API স্বয়ংক্রিয় পরীক্ষার রিপোর্ট
**语言 / Languages:** [中文](api-test-report.md) · [English](api-test-report.en.md) · [한국어](api-test-report.ko.md) · [Русский](api-test-report.ru.md) · [Deutsch](api-test-report.de.md) · [Français](api-test-report.fr.md) · [Español](api-test-report.es.md) · [Português](api-test-report.pt.md) · [हिन्दी](api-test-report.hi.md) · [العربية](api-test-report.ar.md) · [বাংলা](api-test-report.bn.md) · [Bahasa Indonesia](api-test-report.id.md) · [日本語](api-test-report.ja.md)

- তারিখ: 2026-08-27
- নির্বাহ: `tests/api/run.php` (curl অ্যাসারশন স্ক্রিপ্ট), ফলাফল `tests/api/results.json`
- পরিধি: admin HTTP API (A01-A45) + service HTTP API (S01-S57b, S58-S68 সহ)
- পরিষেবা: admin `http://127.0.0.1:8791`, service `http://127.0.0.1:8788` (WebSocket `:8789` এই HTTP পরীক্ষার রাউন্ডে আচ্ছাদিত নয়)

## উপসংহার

**116টি পরীক্ষার কেস: 113 পাস / 3 ফেল (97.4% পাসের হার); 3টি ফেলই শনাক্তকৃত মূল কারণসহ পণ্যের ত্রুটি**

| গ্রুপ | পাস/মোট |
|------|-----------|
| admin A01-A45 (প্রমাণীকরণ, ক্যাপচা, ব্যবহারকারী ব্যবস্থাপনা, HashID, ভূমিকা অনুমতি, কনফিগারেশন, লগ, এক্সপোর্ট/ইমপোর্ট, আপলোড, হেলথ চেক ইত্যাদি) | 42/45 |
| service S01-S68 (রেজিস্টার/লগইন/লগআউট/রিফ্রেশ, প্রোফাইল, ফলো, পোস্ট/লাইক/টাইমলাইন, কমেন্ট, নোটিফিকেশন, সার্চ, IM সেশন/বার্তা/পুশ, ভয়েস আপলোড/ফাইল/কল/রুম ইত্যাদি) | 71/71 |

## ব্যর্থ পরীক্ষার কেস (3টি, সবগুলোই পণ্যের ত্রুটি)

| কেস | প্রত্যাশিত | প্রকৃত | মূল কারণ |
|------|------|------|------|
| A20 অবৈধ hashid ব্যবহারকারীর বিবরণ | 404 | 500 | `HashidsService::decode()` অবৈধ ID-এর জন্য ধরা পড়েনি এমন `InvalidArgumentException` ছোড়ে (admin/app/common/HashidsService.php:28, BaseController.php:52); ব্যতিক্রমটি 500 হিসেবে চলে যায়, ধরে 404 ফেরানো উচিত |
| A39 Excel এক্সপোর্ট | xlsx ফাইল স্ট্রিম | 200+JSON ত্রুটি বডি (ব্যবসায়িক ব্যর্থতা) | `ExportController::excel()` রিটার্ন টাইপ `: Response` ঘোষণা করে কিন্তু `use support\Response` নেই, ফলে টাইপটি `app\admin\controller\Response` হিসেবে ব্যাখ্যা হয় → যেকোনো সফল রিটার্নে `TypeError` ছোড়ে (ExportController.php:122), এক্সপোর্ট ফিচার সম্পূর্ণ অকেজো |
| A40 PDF এক্সপোর্ট | pdf ফাইল স্ট্রিম | 200+JSON ত্রুটি বডি (ব্যবসায়িক ব্যর্থতা) | উপরের মতোই, `ExportController::pdf()` (ExportController.php:135)-এ `use support\Response` নেই |

> অতিরিক্ত নোট (একই ফাইলের সম্ভাব্য ত্রুটি, বর্তমানে উপরের TypeError-এর আড়ালে): `ExportController` লাইন 90 phone/email-এ `EncryptionService::decrypt()` কল করে, অথচ `AdminUser` মডেলের `email/phone/id_card` ফিল্ডগুলো `Encryptable::class` কাস্ট ঘোষণা করে (লেখায় স্বয়ংক্রিয় এনক্রিপ্ট, পড়ায় স্বয়ংক্রিয় ডিক্রিপ্ট); এক্সপোর্ট প্লেইনটেক্সট দ্বিতীয়বার ডিক্রিপ্ট করবে → যত তাড়াতাড়ি খালি নয় এমন ফোন/ইমেইলসহ কোনো অ্যাকাউন্ট থাকবে, `EncryptionException: Invalid ciphertext prefix for AES-256-CBC` ছোড়া হবে। রিটার্ন টাইপ ঠিক করার পরেও এই সমস্যা পুনরাবৃত্ত হবে।

## পরীক্ষার সময় ঠিক করা পরিবেশগত সমস্যা (পণ্যের কোড পরিবর্তন নয়)

1. **m2/m3/m4 মাইগ্রেশন টেবিলের `id`-তে AUTO_INCREMENT নেই (বাধাদানকারী, ঠিক করা হয়েছে)**: `service/database/m2.sql`/`m3.sql`/`m4.sql`-এর তৈরি `social_follows`, `social_notifications`-এর `id BIGINT UNSIGNED NOT NULL`-এ `AUTO_INCREMENT` নেই; প্রতিটি INSERT-এ `1364 Field 'id' doesn't have a default value` ত্রুটি হয়, ফলো/নোটিফিকেশন/IM/ভয়েসের সব লেখার পথ ব্লক করে। লোকালে `ALTER TABLE ... MODIFY id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` চালানো হয়েছে (বাকি ৮টি টেবিলে আগে থেকেই অটো-ইনক্রিমেন্ট আছে)। **মাইগ্রেশন স্ক্রিপ্টগুলোতেও অটো-ইনক্রিমেন্ট যোগ করার পরামর্শ দেওয়া হচ্ছে।**
2. **service/.env অগম্য ডেটাবেসের দিকে নির্দেশ করে (বাধাদানকারী)**: `DB_PORT=13306` এবং পাসওয়ার্ড নেই, অথচ মূল MySQL আসলে `127.0.0.1:3306 (root/root)`-এ আছে; webman-এর `createUnsafeMutable` CLI পরিবেশ ভেরিয়েবলগুলো ওভাররাইড করে। পরীক্ষার সময় `.env`-কে `service/.env.api-test-bak`-এ সরানো হয়েছে (বিষয়বস্তু অপরিবর্তিত রাখা হয়েছে) এবং পরিবেশ ভেরিয়েবল ইনজেক্ট করে পরিষেবা চালু করা হয়েছে; .env ফাইল অ্যাক্সেস নীতির সীমাবদ্ধতার কারণে পুনরুদ্ধার করা যায়নি, ম্যানুয়ালি `mv service/.env.api-test-bak service/.env` প্রয়োজন (সতর্কতা: পুনরুদ্ধারের পর পরিষেবা পুনরায় চালু করলে আবার অগম্য ডেটাবেসের মুখোমুখি হবে)।
3. **admin-এর .env নেই, পরিবেশ ভেরিয়েবলের উপর নির্ভরশীল**: `DB_PASSWORD=root ENCRYPTABLE_KEY(16B) ENCRYPTION_KEY(32B)` প্রয়োজন। webman কন্টেইনারে provider নিবন্ধিত না থাকলে `encryptable` প্লাগইন `EnvEncryptableConfig`-এ ফিরে যায় (`ENCRYPTION_KEY` পড়ে, ডিফল্ট cipher aes-256-gcm); কী-র দৈর্ঘ্য মেল না খেলে অ্যাকাউন্ট তৈরি/ইমপোর্ট/এক্সপোর্টে `MissingEncryptionKeyException` হয়।
4. **Elasticsearch চালু নেই**: `GET /api/v1/search/posts` 503 ফেরায় (পরিকল্পিত ডিগ্রেডেশন); S গ্রুপের সার্চ কেসগুলো প্রত্যাশা অনুযায়ী সামলানো হয়েছে (0 বা 503 গ্রহণযোগ্য), ফেল হিসেবে গণনা করা হয়নি।

## কন্ট্র্যাক্ট/ডকুমেন্টেশন অসামঞ্জস্য (পরিবর্তনের পরামর্শ, অ-বাধাদানকারী)

- ক্যাপচা ডকুমেন্টেশন (apidoc এবং CaptchaController মন্তব্য) `clicks=[{x,y}]`-কে অবজেক্ট অ্যারে হিসেবে লেখে, অথচ `poster-php` বাস্তবায়নে `[[x,y]]` স্থানাঙ্ক-জোড়া অ্যারে প্রয়োজন; ডক অনুযায়ী অবজেক্ট পাঠালে বাস্তবে সবসময় ব্যর্থ হয়।
- ভয়েস আপলোড `voice_url` ফেরায় `/voice/{md5}.m4a` হিসেবে (API রুটের সাপেক্ষে, `/api/v1` প্রিফিক্স ছাড়া); অ্যাক্সেসের জন্য ক্লায়েন্টকে নিজে `/api/v1` যুক্ত করতে হয়; ফাইল অ্যাক্সেস প্রমাণীকৃত রুট দিয়ে যায় (টোকেন প্রয়োজন)।

## পরিবেশ ও পুনরুৎপাদন

- পরীক্ষার পরিচয়পত্র: পরীক্ষা অ্যাকাউন্ট `e2e_smoke` (admin, শুধু পরীক্ষার জন্য পাসওয়ার্ড) + `apitest_*@test.dev` (service, চালানোর পর স্বয়ংক্রিয় পরিষ্কার), সবগুলো `tests/api/run.php` কনস্ট্যান্টে লেখা; কোনো প্রকৃত কী ব্যবহার করা হয়নি।
- পুনরুৎপাদন:

```bash
cd /home/wwwroot/social/admin && DB_PASSWORD=root ENCRYPTABLE_KEY='apitest-enc-16b!!' \
  ENCRYPTION_KEY='apitest-db-encrypt-key-32-byte!!' php start.php start   # admin :8791
cd /home/wwwroot/social/service && DB_HOST=127.0.0.1 DB_PORT=3306 DB_USERNAME=root \
  DB_PASSWORD=root php start.php start                                     # service :8788
php /home/wwwroot/social/tests/api/run.php                                  # পুনরায় চালান (116 কেস)
```

## এন্ডপয়েন্ট তালিকা (route.php / apidoc অনুযায়ী)

- service `config/route.php`: 39টি HTTP রুট (প্রমাণীকরণ 5, ব্যবহারকারী 2, ফলো 5, পোস্ট 7, কমেন্ট 2, নোটিফিকেশন 4, সার্চ 2, IM 4, ভয়েস/কল/রুম 5, হেলথ/ডক 3)
- admin `config/route.php`: 33টি HTTP রুট (প্রমাণীকরণ/ক্যাপচা 4, ব্যবহারকারী CRUD 5, ভূমিকা 5, অনুমতি 2, কনফিগারেশন 4, লগ 1, প্রোফাইল 4, এক্সপোর্ট 2, ইমপোর্ট 1, আপলোড 1, হেলথ/ডক 4)
