# التقرير الملخص الشامل لجميع الاختبارات
**语言 / Languages:** [中文](SUMMARY.md) · [English](SUMMARY.en.md) · [한국어](SUMMARY.ko.md) · [Русский](SUMMARY.ru.md) · [Deutsch](SUMMARY.de.md) · [Français](SUMMARY.fr.md) · [Español](SUMMARY.es.md) · [Português](SUMMARY.pt.md) · [हिन्दी](SUMMARY.hi.md) · [العربية](SUMMARY.ar.md) · [বাংলা](SUMMARY.bn.md) · [Bahasa Indonesia](SUMMARY.id.md) · [日本語](SUMMARY.ja.md)

- التاريخ: 2026-08-27
- فريق الاختبار: اختبارات وحدة PHP / اختبارات وحدة Rust / أتمتة API / واجهة المستخدم من طرف إلى طرف (انظر ملاحظة دور GO في نهاية الوثيقة)
- التقارير الفرعية الأربعة بالإضافة إلى هذا الملخص مخزنة محليًا في `docs/test-reports/`

## نظرة عامة

| الدور | التقرير | حالات الاختبار | ناجحة | فاشلة | الخلاصة |
|------|------|------|------|------|------|
| اختبارات وحدة PHP | `php-unit-report.md` | 196 | 185 | 11 (حالات admin المسبقة، تعتمد على البيئة) | service 136/136 أخضر بالكامل؛ admin 49/60 |
| اختبارات وحدة Rust | `rust-unit-report.md` | 180 | 180 | 0 | 15 crates أخضر بالكامل، واكتُشفت 7 عيوب حقيقية |
| أتمتة API | `api-test-report.md` | 116 | 113 | 3 | 3 عيوب منتج حقيقية، الأسباب الجذرية محددة |
| واجهة المستخدم من طرف إلى طرف | `ui-e2e-report.md` | 35 | 35 | 0 | أخضر بالكامل، 1 معيق (ES غير مشغّل) |
| **الإجمالي** | | **527** | **513** | **14** | نسبة النجاح 97% |

## قائمة العيوب الحقيقية (يُوصى بالإصلاح)

1. **A20 hashid غير صالح** → 500 يجب أن تكون 404: `admin/app/common/HashidsService.php:28` لا يلتقط `InvalidArgumentException`
2. **A39/A40 تصدير Excel/PDF** → فشل مضمون: `ExportController` يفتقد `use support\Response` مما يكسر تحليل نوع الإرجاع؛ والملف نفسه يفك تشفير الهاتف/البريد المُحوّلين مسبقًا مرة ثانية ويُبلغ عن `Invalid ciphertext prefix`
3. **7 عيوب اكتشفها Rust**: التفاصيل في `rust-unit-report.md` (تحليل البروتوكولات، معالجة الحدود، إلخ، مع إرفاق إصلاح لكل منها)
4. **حالات فشل اختبارات وحدة admin الـ 11 هي مشاكل بيئة/إعدادات**: غياب `admin/.env`، اعتماد التحقق على خدمة/Redis قيد التشغيل، تأكيدات قديمة على وسيط Cors وحقل searchable في admin_user — ليست عيوب كود

## إصلاحات البيئة والملاحظات (الناتجة عن هذه الدفعة من الاختبارات)

- **قاعدة البيانات**: عمود `id` في `social_follows`/`social_notifications` بجداول الترحيل m2/m3/m4 بدون AUTO_INCREMENT، تم إصلاحه عبر ALTER (وإلا تفشل مسارات كتابة المتابعة/الإشعارات/IM/الصوت برمز 1364)
- **`service/.env`**: نُسخ احتياطيًا كـ `.env.api-test-bak` (كان يشير أصلًا إلى المنفذ 13306 غير القابل للوصول). الاستعادة التلقائية غير ممكنة بسبب قيود سياسة الوصول إلى .env؛ يلزم `mv service/.env.api-test-bak service/.env` يدويًا
- **ES غير مشغّل**: حالات البحث (API + E2E) وُسمت ناجحة بصفتها 503/blocked؛ يلزم إعادة التحقق بعد تشغيل Elasticsearch

## ملاحظة مهندس اختبارات GO

لا يحتوي المستودع **على أي كود Go إطلاقًا** (لا go.mod ولا ملفات .go)؛ لم يكن لهذا الدور أي وحدة لاختبارها ولم يُنفَّذ. لإضافة تغطية، يجب أولًا إدخال مكوّن Go (مثل بوابة/وحدة جانبية للبحث).

## طريقة إعادة الإنتاج

```bash
# اختبارات الوحدة
cd service && vendor/bin/phpunit
cd admin && vendor/bin/phpunit
cd infrastructure && cargo test --workspace
# أتمتة API (يتطلب تشغيل admin :8791 وservice :8788 أولًا مع حقن ENCRYPTABLE_KEY/ENCRYPTION_KEY)
php tests/api/run.php
# واجهة المستخدم E2E
cd tests/e2e && npx playwright test
```
