# سجل التغييرات

**语言 / Languages:** [中文](CHANGELOG.md) · [English](CHANGELOG.en.md) · [한국어](CHANGELOG.ko.md) · [Русский](CHANGELOG.ru.md) · [Deutsch](CHANGELOG.de.md) · [Français](CHANGELOG.fr.md) · [Español](CHANGELOG.es.md) · [Português](CHANGELOG.pt.md) · [हिन्दी](CHANGELOG.hi.md) · [العربية](CHANGELOG.ar.md) · [বাংলা](CHANGELOG.bn.md) · [Bahasa Indonesia](CHANGELOG.id.md) · [日本語](CHANGELOG.ja.md)

## [1.0.6] — 2026-08-07

### إضافات
- تطبيقات حقيقية لـ `bee_cli`: `new` (هيكلة المشاريع)، `generate controller/model`، `run` مع إعادة التحميل الساخن `--watch`، `pack` (بناء الإصدار + النسخ إلى `dist/`)
- اختبارات وحدة CLI لهيكلة المشاريع وتوليد الكود (7 اختبارات جديدة)

### إصلاحات
- `bee_rust::init()` أصبح الآن خلف ميزة `logs` — عادت البنيات المخففة (مثل `--no-default-features --features kv`) إلى الترجمة من جديد
- فحص Clippy `unnecessary_map_or` في `bee_kv::InMemoryKvStore::exists`
- أُزيلت من `rustfmt.toml` خيارات خاصة بـ nightly كانت تُتجاهل بصمت على stable؛ أصبح workspace الآن يجتاز `cargo fmt --all --check`
- ثنائي `bee_cli` مع `doc = false` لإزالة تعارض اسم ملف مخرجات rustdoc مع `bee_rust`
- أصبح منفذ مثال `hello` قابلًا للضبط عبر متغير البيئة `PORT`

### تغييرات
- `bee-rust migrate` يبلغ «not implemented» ويخرج برمز غير صفري (مخطط له)
- تحديث README / README.en لوصف سلوك CLI الفعلي

## [1.0.4] — 2026-07-29

### إضافات
- مرشح كشف الهجمات الأمنية عبر `security-rust` (27 كاشفًا)
- `SecurityFilter` مع تغطية XSS وحقن SQL وحقن الأوامر وتجاوز المسار
- علم الميزة `security` في `bee_rust` و `bee_router`

### تغييرات
- تحديث README بتوثيق ميزات الأمان
- تحديث README بقسم دعم الدفع (WeChat Pay / Alipay)

### إصلاحات
- صيغة المعرّفات الأولية (raw) لـ Tera في `bee_template` لإصدار Rust 2024

## [1.0.3] — 2026-07-29

### إضافات
- هيكل workspace أولي يضم 13 crate
- توجيه MVC مع trait `Controller` و `Router`
- ORM مع باني `QuerySet` وماكرو derive `Model`
- تجريد trait KV/Cache مع خلفيتي Redis و Memory
- إدارة الجلسات مع خلفيتي Memory/Redis
- إدارة الإعدادات مع دعم INI/YAML/ENV وإعادة التحميل الساخن
- عرض القوالب عبر Tera
- تسجيل مع تكامل tracing
- هيكلة CLI وتوليد الكود
- نماذج traits أولية لمحركات البحث والرسوم البيانية والسلاسل الزمنية (برامج التشغيل مخطط لها)

[1.0.4]: https://github.com/erikwang2013/bee-rust/compare/v1.0.3...v1.0.4
[1.0.3]: https://github.com/erikwang2013/bee-rust/releases/tag/v1.0.3
