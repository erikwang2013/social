# পরিবর্তনের লগ

**语言 / Languages:** [中文](CHANGELOG.md) · [English](CHANGELOG.en.md) · [한국어](CHANGELOG.ko.md) · [Русский](CHANGELOG.ru.md) · [Deutsch](CHANGELOG.de.md) · [Français](CHANGELOG.fr.md) · [Español](CHANGELOG.es.md) · [Português](CHANGELOG.pt.md) · [हिन्दी](CHANGELOG.hi.md) · [العربية](CHANGELOG.ar.md) · [বাংলা](CHANGELOG.bn.md) · [Bahasa Indonesia](CHANGELOG.id.md) · [日本語](CHANGELOG.ja.md)

## [1.0.6] — 2026-08-07

### যোগ করা হয়েছে
- `bee_cli`-এর বাস্তব ইমপ্লিমেন্টেশন: `new` (প্রজেক্ট স্ক্যাফোল্ডিং), `generate controller/model`, `--watch` হট রিলোডসহ `run`, `pack` (রিলিজ বিল্ড + `dist/`-এ কপি)
- স্ক্যাফোল্ডিং ও কোড জেনারেশনের জন্য CLI ইউনিট টেস্ট (৭টি নতুন টেস্ট)

### স্থির করা হয়েছে
- `bee_rust::init()` এখন `logs` ফিচারের পেছনে গেট করা হয়েছে — হ্রাসকৃত ফিচার বিল্ড (যেমন `--no-default-features --features kv`) আবার কম্পাইল হয়
- `bee_kv::InMemoryKvStore::exists`-এ Clippy `unnecessary_map_or` লিন্ট
- `rustfmt.toml` থেকে শুধু-nightly বিকল্পগুলো সরানো হয়েছে যেগুলো stable-এ নীরবে উপেক্ষা করা হতো; ওয়ার্কস্পেস এখন `cargo fmt --all --check` পাস করে
- `bee_cli` বাইনারিতে `doc = false` — `bee_rust`-এর সাথে rustdoc আউটপুট ফাইলনেম সংঘর্ষ দূর করা হয়েছে
- `hello` উদাহরণের পোর্ট এখন `PORT` এনভায়রনমেন্ট ভেরিয়েবলের মাধ্যমে কনফিগারযোগ্য

### পরিবর্তিত হয়েছে
- `bee-rust migrate` "not implemented" রিপোর্ট করে এবং নন-জিরো কোডে বেরিয়ে যায় (পরিকল্পিত)
- README / README.en প্রকৃত CLI আচরণ বর্ণনা করতে আপডেট করা হয়েছে

## [1.0.4] — 2026-07-29

### যোগ করা হয়েছে
- `security-rust`-এর মাধ্যমে নিরাপত্তা আক্রমণ শনাক্তকরণ ফিল্টার (২৭টি ডিটেক্টর)
- XSS, SQL ইনজেকশন, কমান্ড ইনজেকশন, পাথ ট্রাভার্সাল কভারেজসহ `SecurityFilter`
- `bee_rust` এবং `bee_router`-এ `security` ফিচার ফ্ল্যাগ

### পরিবর্তিত হয়েছে
- নিরাপত্তা ফিচার ডকুমেন্টেশনসহ README আপডেট করা হয়েছে
- পেমেন্ট সাপোর্ট সেকশন (WeChat Pay / Alipay) সহ README আপডেট করা হয়েছে

### স্থির করা হয়েছে
- Rust 2024 এডিশনের জন্য `bee_template`-এ Tera raw আইডেন্টিফায়ার সিনট্যাক্স

## [1.0.3] — 2026-07-29

### যোগ করা হয়েছে
- ১৩টি ক্রেটসহ প্রাথমিক ওয়ার্কস্পেস কাঠামো
- `Controller` ট্রেইট ও `Router`-সহ MVC রাউটিং
- `QuerySet` বিল্ডার ও `Model` ডিরাইভ ম্যাক্রোসহ ORM
- Redis ও Memory ব্যাকএন্ডসহ KV/Cache ট্রেইট অ্যাবস্ট্রাকশন
- Memory/Redis ব্যাকএন্ডসহ সেশন ম্যানেজমেন্ট
- INI/YAML/ENV সাপোর্ট ও হট-রিলোডসহ কনফিগ ম্যানেজমেন্ট
- Tera-র মাধ্যমে টেমপ্লেট রেন্ডারিং
- tracing ইন্টিগ্রেশনসহ লগিং
- CLI স্ক্যাফোল্ডিং ও কোড জেনারেশন
- সার্চ, গ্রাফ, টাইম-সিরিজ ইঞ্জিন ট্রেইট স্টাব (ড্রাইভার পরিকল্পিত)

[1.0.4]: https://github.com/erikwang2013/bee-rust/compare/v1.0.3...v1.0.4
[1.0.3]: https://github.com/erikwang2013/bee-rust/releases/tag/v1.0.3
