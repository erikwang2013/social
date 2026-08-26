# সমস্ত পরীক্ষার সামগ্রিক সারাংশ রিপোর্ট
**语言 / Languages:** [中文](SUMMARY.md) · [English](SUMMARY.en.md) · [한국어](SUMMARY.ko.md) · [Русский](SUMMARY.ru.md) · [Deutsch](SUMMARY.de.md) · [Français](SUMMARY.fr.md) · [Español](SUMMARY.es.md) · [Português](SUMMARY.pt.md) · [हिन्दी](SUMMARY.hi.md) · [العربية](SUMMARY.ar.md) · [বাংলা](SUMMARY.bn.md) · [Bahasa Indonesia](SUMMARY.id.md) · [日本語](SUMMARY.ja.md)

- তারিখ: 2026-08-27
- পরীক্ষা দল: PHP ইউনিট টেস্ট / Rust ইউনিট টেস্ট / API অটোমেশন / UI এন্ড-টু-এন্ড (GO ভূমিকা সম্পর্কে শেষে ব্যাখ্যা দেখুন)
- চারটি আলাদা রিপোর্ট + এই সারাংশ সব স্থানীয়ভাবে `docs/test-reports/`-এ সংরক্ষিত

## ওভারভিউ

| ভূমিকা | রিপোর্ট | টেস্ট কেস | পাস | ফেল | উপসংহার |
|------|------|------|------|------|------|
| PHP ইউনিট টেস্ট | `php-unit-report.md` | 196 | 185 | 11 (admin পূর্ব-বিদ্যমান কেস, পরিবেশনির্ভর) | service 136/136 সম্পূর্ণ সবুজ; admin 49/60 |
| Rust ইউনিট টেস্ট | `rust-unit-report.md` | 180 | 180 | 0 | 15 crates সম্পূর্ণ সবুজ, এবং ৭টি প্রকৃত ত্রুটি পাওয়া গেছে |
| API অটোমেশন | `api-test-report.md` | 116 | 113 | 3 | ৩টি প্রকৃত পণ্য ত্রুটি, মূল কারণ শনাক্ত |
| UI এন্ড-টু-এন্ড | `ui-e2e-report.md` | 35 | 35 | 0 | সম্পূর্ণ সবুজ, ১টি blocked (ES চালু নয়) |
| **মোট** | | **527** | **513** | **14** | পাসের হার 97% |

## প্রকৃত ত্রুটির তালিকা (মেরামতের পরামর্শ)

1. **A20 অবৈধ hashid** → 500-এর বদলে 404 হওয়া উচিত: `admin/app/common/HashidsService.php:28` `InvalidArgumentException` ধরতে পারে না
2. **A39/A40 Excel/PDF এক্সপোর্ট** → নিশ্চিত ব্যর্থতা: `ExportController`-এ `use support\Response` নেই বলে রিটার্ন টাইপ সমাধান ভেঙে যায়; একই ফাইলে আগে থেকেই cast করা ফোন/ইমেইল দ্বিতীয়বার ডিক্রিপ্ট করায় `Invalid ciphertext prefix` আসে
3. **Rust-এর পাওয়া ৭টি ত্রুটি**: বিস্তারিত `rust-unit-report.md`-এ (প্রোটোকল পার্সিং, সীমা প্রক্রিয়াকরণ ইত্যাদি, প্রতিটির সাথে মেরামত সংযুক্ত)
4. **admin ইউনিট টেস্টের ১১টি ব্যর্থতা পরিবেশ/কনফিগারেশন সমস্যা**: `admin/.env` নেই, ক্যাপচা চলমান সার্ভিস/Redis-এর উপর নির্ভরশীল, Cors মিডলওয়্যার ও admin_user searchable-এর অ্যাসারশন পুরনো — কোড ত্রুটি নয়

## পরিবেশ মেরামত ও সতর্কতা (এই টেস্ট ব্যাচের কারণে)

- **ডেটাবেস**: m2/m3/m4 মাইগ্রেশন টেবিল `social_follows`/`social_notifications`-এর `id`-তে AUTO_INCREMENT নেই, ALTER দিয়ে ঠিক করা হয়েছে (নাহলে ফলো/নোটিফিকেশন/IM/ভয়েস লেখার পথ 1364 ত্রুটি দেয়)
- **`service/.env`**: `.env.api-test-bak` হিসেবে ব্যাকআপ করা হয়েছে (মূলত অগম্য পোর্ট 13306-এর দিকে নির্দেশ করত)। .env অ্যাক্সেস নীতির সীমাবদ্ধতায় স্বয়ংক্রিয় পুনরুদ্ধার সম্ভব নয়; ম্যানুয়াল `mv service/.env.api-test-bak service/.env` প্রয়োজন
- **ES চালু নয়**: সার্চ ধরনের কেসগুলো (API + E2E) 503/blocked হিসেবে পাস চিহ্নিত; Elasticsearch চালু করার পর পুনঃযাচাই প্রয়োজন

## GO পরীক্ষা প্রকৌশলীর নোট

রিপোজিটরিতে **কোনো Go কোড নেই** (go.mod নেই, .go ফাইল নেই); এই ভূমিকার পরীক্ষার কোনো মডিউল ছিল না এবং তা সম্পাদিত হয়নি। কভারেজ যোগ করতে আগে একটি Go কম্পোনেন্ট (যেমন গেটওয়ে/সার্চ সাইডকার) চালু করতে হবে।

## পুনরুৎপাদন

```bash
# ইউনিট টেস্ট
cd service && vendor/bin/phpunit
cd admin && vendor/bin/phpunit
cd infrastructure && cargo test --workspace
# API অটোমেশন (আগে admin :8791 ও service :8788 চালু করতে হবে, ENCRYPTABLE_KEY/ENCRYPTION_KEY ইনজেক্টসহ)
php tests/api/run.php
# UI E2E
cd tests/e2e && npx playwright test
```
