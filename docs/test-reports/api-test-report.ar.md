# تقرير اختبار واجهة برمجة التطبيقات (API) الآلي
**语言 / Languages:** [中文](api-test-report.md) · [English](api-test-report.en.md) · [한국어](api-test-report.ko.md) · [Русский](api-test-report.ru.md) · [Deutsch](api-test-report.de.md) · [Français](api-test-report.fr.md) · [Español](api-test-report.es.md) · [Português](api-test-report.pt.md) · [हिन्दी](api-test-report.hi.md) · [العربية](api-test-report.ar.md) · [বাংলা](api-test-report.bn.md) · [Bahasa Indonesia](api-test-report.id.md) · [日本語](api-test-report.ja.md)

- التاريخ: 2026-08-27
- التنفيذ: `tests/api/run.php` (سكربت تأكيدات curl)، النتائج في `tests/api/results.json`
- النطاق: admin HTTP API (A01-A45) + service HTTP API (S01-S57b، بما في ذلك S58-S68)
- الخدمات: admin `http://127.0.0.1:8791`، service `http://127.0.0.1:8788` (WebSocket `:8789` غير مشمول في هذه الجولة من اختبارات HTTP)

## الخلاصة

**116 حالة اختبار: 113 ناجحة / 3 فاشلة (نسبة نجاح 97.4%)؛ حالات الفشل الثلاث كلها عيوب منتج تم تحديد أسبابها الجذرية**

| المجموعة | ناجح/الإجمالي |
|------|-----------|
| admin A01-A45 (المصادقة، التحقق، إدارة المستخدمين، HashID، الأدوار والصلاحيات، الإعدادات، السجلات، التصدير/الاستيراد، الرفع، فحوصات الصحة، إلخ) | 42/45 |
| service S01-S68 (التسجيل/الدخول/الخروج/التحديث، الملف الشخصي، المتابعة، المنشورات/الإعجابات/الجدول الزمني، التعليقات، الإشعارات، البحث، جلسات IM/الرسائل/الإشعارات الفورية، رفع الصوت/الملفات/المكالمات/الغرف، إلخ) | 71/71 |

## حالات الاختبار الفاشلة (3، جميعها عيوب منتج)

| الحالة | المتوقع | الفعلي | السبب الجذري |
|------|------|------|------|
| A20 تفاصيل مستخدم hashid غير صالح | 404 | 500 | `HashidsService::decode()` يرمي `InvalidArgumentException` غير ملتقطة للمعرفات غير الصالحة (admin/app/common/HashidsService.php:28، BaseController.php:52)؛ الاستثناء ينتشر كرمز 500، ويجب التقاطه وإرجاع 404 |
| A39 تصدير Excel | دفق ملف xlsx | 200+نص خطأ JSON (فشل تجاري) | `ExportController::excel()` يعلن نوع الإرجاع `: Response` لكنه يفتقد `use support\Response`، فيُحل النوع إلى `app\admin\controller\Response` ← أي إرجاع ناجح يرمي `TypeError` (ExportController.php:122)، مما يجعل التصدير غير قابل للاستخدام نهائيًا |
| A40 تصدير PDF | دفق ملف pdf | 200+نص خطأ JSON (فشل تجاري) | كما سبق، `ExportController::pdf()` (ExportController.php:135) يفتقد `use support\Response` |

> ملاحظة إضافية (عيب محتمل في نفس الملف، يحجبه حاليًا TypeError أعلاه): السطر 90 في `ExportController` يستدعي `EncryptionService::decrypt()` على phone/email، بينما حقول `email/phone/id_card` في نموذج `AdminUser` تعلن تحويل `Encryptable::class` (تشفير تلقائي عند الكتابة وفك تلقائي عند القراءة)، فسيقوم التصدير بفك تشفير النص الصريح مرة ثانية ← بمجرد وجود حساب برقم هاتف/بريد غير فارغ، سيتم رمي `EncryptionException: Invalid ciphertext prefix for AES-256-CBC`. هذه المشكلة ستستمر في الظهور حتى بعد إصلاح أنواع الإرجاع.

## مشاكل البيئة التي أُصلحت أثناء الاختبار (وليست تغييرات في كود المنتج)

1. **عمود `id` في جداول الترحيل m2/m3/m4 بدون AUTO_INCREMENT (معيق، تم إصلاحه)**: جداول `social_follows` و`social_notifications` التي ينشئها `service/database/m2.sql`/`m3.sql`/`m4.sql` تحتوي على `id BIGINT UNSIGNED NOT NULL` بدون `AUTO_INCREMENT`؛ أي INSERT يفشل برسالة `1364 Field 'id' doesn't have a default value`، مما يعيق جميع مسارات الكتابة للمتابعة/الإشعارات/IM/الصوت. تم تنفيذ `ALTER TABLE ... MODIFY id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` محليًا (الجداول الثمانية الأخرى بها زيادة تلقائية أصلًا). **يُوصى بإضافة الزيادة التلقائية إلى نصوص الترحيل نفسها.**
2. **service/.env يشير إلى قاعدة بيانات لا يمكن الوصول إليها (معيق)**: `DB_PORT=13306` بدون كلمة مرور، بينما MySQL الرئيسي فعليًا على `127.0.0.1:3306 (root/root)`؛ `createUnsafeMutable` في webman يتجاوز متغيرات بيئة CLI. أثناء الاختبار، نُقل `.env` إلى `service/.env.api-test-bak` (مع الحفاظ على المحتوى كما هو) وتم تشغيل الخدمة بحقن متغيرات البيئة؛ لم تتم الاستعادة بسبب قيود سياسة الوصول لملف .env، ويتطلب الأمر تنفيذ `mv service/.env.api-test-bak service/.env` يدويًا (ملاحظة: بعد الاستعادة، ستصطدم إعادة تشغيل الخدمة بقاعدة البيانات غير القابلة للوصول مجددًا).
3. **admin لا يحتوي على .env ويعتمد على متغيرات البيئة**: يتطلب `DB_PASSWORD=root ENCRYPTABLE_KEY(16B) ENCRYPTION_KEY(32B)`. يعود إضافة `encryptable` إلى `EnvEncryptableConfig` (يقرأ `ENCRYPTION_KEY`، والتشفير الافتراضي aes-256-gcm) عند عدم تسجيل provider في حاوية webman؛ عدم تطابق طول المفتاح يسبب `MissingEncryptionKeyException` عند إنشاء/استيراد/تصدير الحسابات.
4. **Elasticsearch غير مشغّل**: `GET /api/v1/search/posts` يعيد 503 (تدهور مُصمم مسبقًا)؛ حالات البحث في المجموعة S عُولجت كما هو متوقع (قبول 0 أو 503)، ولا تُحتسب كفشل.

## تباينات العقد/التوثيق (يُقترح تنقيحها، غير معيقة)

- توثيق التحقق (apidoc وتعليقات CaptchaController) يكتب `clicks=[{x,y}]` كمصفوفة كائنات، بينما تنفيذ `poster-php` يتطلب مصفوفة أزواج إحداثيات `[[x,y]]`؛ تمرير الكائنات وفق التوثيق يفشل دائمًا عمليًا.
- رفع الصوت يعيد `voice_url` بصيغة `/voice/{md5}.m4a` (نسبة إلى جذر API، دون بادئة `/api/v1`)؛ يجب على العميل إضافة `/api/v1` بنفسه للوصول؛ الوصول إلى الملفات يمر عبر مسارات موثّقة (يتطلب token).

## البيئة وإعادة الإنتاج

- بيانات اعتماد الاختبار: حساب `e2e_smoke` (admin، كلمة مرور مخصصة للاختبار فقط) + `apitest_*@test.dev` (service، يُنظف تلقائيًا بعد التشغيل)، جميعها مكتوبة في ثوابت `tests/api/run.php`؛ لم تُستخدم أي مفاتيح حقيقية.
- إعادة الإنتاج:

```bash
cd /home/wwwroot/social/admin && DB_PASSWORD=root ENCRYPTABLE_KEY='apitest-enc-16b!!' \
  ENCRYPTION_KEY='apitest-db-encrypt-key-32-byte!!' php start.php start   # admin :8791
cd /home/wwwroot/social/service && DB_HOST=127.0.0.1 DB_PORT=3306 DB_USERNAME=root \
  DB_PASSWORD=root php start.php start                                     # service :8788
php /home/wwwroot/social/tests/api/run.php                                  # إعادة التشغيل (116 حالة)
```

## قائمة الواجهات (وفق route.php / apidoc)

- service `config/route.php`: 39 مسار HTTP (المصادقة 5، المستخدمون 2، المتابعة 5، المنشورات 7، التعليقات 2، الإشعارات 4، البحث 2، IM 4، الصوت/المكالمات/الغرف 5، الصحة/التوثيق 3)
- admin `config/route.php`: 33 مسار HTTP (المصادقة/التحقق 4، CRUD المستخدمين 5، الأدوار 5، الصلاحيات 2، الإعدادات 4، السجلات 1، الملف الشخصي 4، التصدير 2، الاستيراد 1، الرفع 1، الصحة/التوثيق 4)
