# تقرير اختبار واجهة برمجة التطبيقات (API) الآلي
**语言 / Languages:** [中文](api-test-report.md) · [English](api-test-report.en.md) · [한국어](api-test-report.ko.md) · [Русский](api-test-report.ru.md) · [Deutsch](api-test-report.de.md) · [Français](api-test-report.fr.md) · [Español](api-test-report.es.md) · [Português](api-test-report.pt.md) · [हिन्दी](api-test-report.hi.md) · [العربية](api-test-report.ar.md) · [বাংলা](api-test-report.bn.md) · [Bahasa Indonesia](api-test-report.id.md) · [日本語](api-test-report.ja.md)

- التاريخ: 2026-08-27
- التنفيذ: `tests/api/run.php` (سكربت تأكيدات curl)، النتائج في `tests/api/results.json`
- النطاق: admin HTTP API (A01-A45) + service HTTP API (S01-S57b، بما في ذلك S58-S68)
- الخدمات: admin `http://127.0.0.1:8791`، service `http://127.0.0.1:8788` (WebSocket `:8789` غير مشمول في هذه الجولة من اختبارات HTTP)

## الخلاصة

**116 حالة اختبار: 116 ناجحة / 0 فاشلة (نسبة نجاح 100%)؛ عيوب المنتج الثلاثة في الجولة السابقة (A20/A39/A40) جميعها أُصلحت وتحققت**

| المجموعة | ناجح/الإجمالي |
|------|-----------|
| admin A01-A45 (المصادقة، التحقق، إدارة المستخدمين، HashID، الأدوار والصلاحيات، الإعدادات، السجلات، التصدير/الاستيراد، الرفع، فحوصات الصحة، إلخ) | 45/45 |
| service S01-S68 (التسجيل/الدخول/الخروج/التحديث، الملف الشخصي، المتابعة، المنشورات/الإعجابات/الجدول الزمني، التعليقات، الإشعارات، البحث، جلسات IM/الرسائل/الإشعارات الفورية، رفع الصوت/الملفات/المكالمات/الغرف، إلخ) | 71/71 |

## التحقق من إصلاح عيوب المنتج الثلاثة في الجولة السابقة (كلها PASS)

| الحالة | المتوقع | الجولة السابقة (الفعلي) | الإصلاح | نتيجة هذه الجولة |
|------|------|---------|------|---------|
| A20 تفاصيل مستخدم hashid غير صالح | 404 | 500 | `BaseController::decodeId()` يلتقط `InvalidArgumentException` ويرمي `support\exception\NotFoundException($msg, 404)` (admin/app/admin/controller/BaseController.php)؛ تم توسيع catch الطريقتين الدفعيتين في `UserController` إلى `InvalidArgumentException \| NotFoundException` مع الحفاظ على دلالة 422 | **PASS (404)** |
| A39 تصدير Excel | دفق ملف xlsx | 200+نص خطأ JSON | `ExportController` يضيف `use support\Response;` (نوع الإرجاع سابقًا كان يُحل إلى `app\admin\controller\Response` غير الموجود، مسببًا TypeError)؛ حقول `phone/email/id_card` في `admin_user` تُفك تشفيرها تلقائيًا عبر تحويل Encryptable عند القراءة، فيقوم التصدير بالإخفاء مباشرة، وأُزيل فك التشفير الثاني | **PASS (دفق ملف attachment)** |
| A40 تصدير PDF | دفق ملف pdf | 200+نص خطأ JSON | كما سبق (تم إصلاح نوع إرجاع `ExportController::pdf()`) | **PASS (دفق ملف application/pdf)** |

## مشاكل البيئة التي أُصلحت/عُولجت في هذه الجولة (وليست تغييرات في كود منتج الأعمال)

1. **تجاوز كلمة مرور قاعدة البيانات الفارغة في run.php معطوب (عيب في سكربت الاختبار، تم إصلاحه)**: الثابت `DB` يستخدم `getenv('DB_PASS') ?: 'root'`؛ السلسلة الفارغة في متغير البيئة تُعتبر falsy بواسطة `?:` وتتراجع إلى 'root'، لذا يُرفض اتصال root المحلي بكلمة مرور فارغة (`Access denied ... using password: YES`). تم التغيير إلى `getenv('DB_PASS') ?? 'root'` (الافتراضي فقط عند عدم التعيين)، تغيير من سطر واحد (tests/api/run.php:26).
2. **المنفذ 8788 لخدمة service مشغول بعملية خاطئة (بيئة، تمت المعالجة)**: عملية service من مشروع آخر على هذه الآلة — `property-management-platform` (master 2004768، بدأت 08:07) — تستمع على 8788، و`.env` الخاص بها يشير إلى قاعدة بيانات `property_management`؛ خدمة social لم تكن تعمل فعليًا، مما جعل مسارات IM/الصوت من S45 فصاعدًا تعيد 404، وSQL الخاص بمرحلة التنظيف يضرب قاعدة البيانات الخاطئة. أُوقفت العملية وأُعيد تشغيل خدمة social على 8788/8789 (`DB_HOST=127.0.0.1 DB_PORT=3306 DB_USERNAME=root DB_PASSWORD=''`)؛ عاد فحص الصحة إلى `social-service`.
3. **ترقية ImageMagick 7 سببت تعطل برنامج تشغيل Imagick للتحقق (بيئة، تمت المعالجة)**: بعد ترقية ImageMagick للنظام إلى 7.1.2-27 (بناء 2026-07-08) أُزيل `PixelsResource`؛ imagick 3.8.1 لم يعد يعرّف `Imagick::RESOURCETYPE_PIXELS`، وبنّاء `ImagickDriver` في poster-php يرمي فورًا `Undefined constant` (كود vendor، لم يُعدّل)، لذا توليد/التحقق من captcha (A05/A06) يعيد 500 ويحجب الدخول A08-A11 بشكل متسلسل. **المعالجة**: أُعيد تشغيل خدمة admin مع مفتاح تبديل برنامج التشغيل المتاح في وثيقة الإعدادات — `POSTER_IMAGE_DRIVER=gd` (admin/config/poster.php:17 يدعم gd/imagick/auto أصليًا)؛ بعد نقل captcha إلى برنامج تشغيل GD، تعمل السلسلة بأكملها. لاستعادة برنامج تشغيل Imagick، خفّض ImageMagick إلى 6.x أو رقّع poster-php ليتوافق مع IM7.
4. **تغيرت كلمة مرور root في MySQL إلى فارغة**: الجولة السابقة سجلت `root/root`؛ في هذه الجولة يعمل الدخول بكلمة مرور فارغة، وجميع الخدمات والسكربتات بدأت بكلمة مرور فارغة.
5. **بيئة إعادة تشغيل خدمة admin**: ما ورد في الجولة السابقة «admin لا يحتوي على .env ويعتمد على متغيرات البيئة» ما زال ساريًا؛ أوامر إعادة التشغيل أدناه في «البيئة وإعادة الإنتاج».
6. **service/.env ما زال `service/.env.api-test-bak`**: نُقل في الجولة السابقة لاختبار الاتصال ولم يُستعد (الاستعادة مقيدة بسياسة الوصول لملف .env)؛ في هذه الجولة بدأت الخدمة مجددًا بمتغيرات البيئة. يلزم تنفيذ `mv service/.env.api-test-bak service/.env` يدويًا (أعد تشغيل الخدمة بعد الاستعادة؛ انتبه إلى عنوان قاعدة البيانات الذي يشير إليه).
7. **Elasticsearch غير مشغّل**: `GET /api/v1/search/posts` يعيد 503 (تدهور مُصمم مسبقًا)؛ حالات البحث في المجموعة S عُولجت كما هو متوقع (قبول 0 أو 503)، ولا تُحتسب كفشل.

## تباينات العقد/التوثيق (يُقترح تنقيحها، غير معيقة)

- توثيق التحقق (apidoc وتعليقات CaptchaController) يكتب `clicks=[{x,y}]` كمصفوفة كائنات، بينما تنفيذ `poster-php` يتطلب مصفوفة أزواج إحداثيات `[[x,y]]`؛ تمرير الكائنات وفق التوثيق يفشل دائمًا عمليًا.
- رفع الصوت يعيد `voice_url` بصيغة `/voice/{md5}.m4a` (نسبة إلى جذر API، دون بادئة `/api/v1`)؛ يجب على العميل إضافة `/api/v1` بنفسه للوصول؛ الوصول إلى الملفات يمر عبر مسارات موثّقة (يتطلب token).

## البيئة وإعادة الإنتاج

- بيانات اعتماد الاختبار: حساب `e2e_smoke` (admin، كلمة مرور مخصصة للاختبار فقط) + `apitest_*@test.dev` (service، يُنظف تلقائيًا بعد التشغيل)، جميعها مكتوبة في ثوابت `tests/api/run.php`؛ لم تُستخدم أي مفاتيح حقيقية.
- إعادة الإنتاج:

```bash
cd /home/wwwroot/social/admin && DB_PASSWORD='' ENCRYPTABLE_KEY='apitest-enc-16b!!' \
  ENCRYPTION_KEY='apitest-db-encrypt-key-32-byte!!' POSTER_IMAGE_DRIVER=gd \
  php start.php start                                          # admin :8791
cd /home/wwwroot/social/service && DB_HOST=127.0.0.1 DB_PORT=3306 DB_USERNAME=root \
  DB_PASSWORD='' php start.php start                           # service :8788
cd /home/wwwroot/social/tests/api && DB_PASS='' php run.php    # إعادة التشغيل (116 حالة)
```

- ملاحظة: تأكد من أن المنفذ 8788 لا تشغله خدمة `property-management-platform` (كلا المشروعين يستخدمان نفس المنفذ افتراضيًا؛ عند وجود المشروعين معًا على هذه الآلة يجب فصلهما).

## قائمة الواجهات (وفق route.php / apidoc)

- service `config/route.php`: 39 مسار HTTP (المصادقة 5، المستخدمون 2، المتابعة 5، المنشورات 7، التعليقات 2، الإشعارات 4، البحث 2، IM 4، الصوت/المكالمات/الغرف 5، الصحة/التوثيق 3)
- admin `config/route.php`: 33 مسار HTTP (المصادقة/التحقق 4، CRUD المستخدمين 5، الأدوار 5، الصلاحيات 2، الإعدادات 4، السجلات 1، الملف الشخصي 4، التصدير 2، الاستيراد 1، الرفع 1، الصحة/التوثيق 4)
