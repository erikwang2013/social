# قبول خط الأساس Admin (M0، 2026-08-17)

**语言 / Languages:** [中文](ADMIN_BASELINE.md) · [English](ADMIN_BASELINE.en.md) · [한국어](ADMIN_BASELINE.ko.md) · [Русский](ADMIN_BASELINE.ru.md) · [Deutsch](ADMIN_BASELINE.de.md) · [Français](ADMIN_BASELINE.fr.md) · [Español](ADMIN_BASELINE.es.md) · [Português](ADMIN_BASELINE.pt.md) · [हिन्दी](ADMIN_BASELINE.hi.md) · [العربية](ADMIN_BASELINE.ar.md) · [বাংলা](ADMIN_BASELINE.bn.md) · [Bahasa Indonesia](ADMIN_BASELINE.id.md) · [日本語](ADMIN_BASELINE.ja.md)

حالة خط الأساس ونقاط دخول التحويل لـ open-admin (webman v2 + لوحة إدارة Flutter).

## النسخة الحالية وحالة التشغيل

| العنصر | القيمة |
|---|---|
| الإطار | webman v2 (workerman/webman-framework **v2.2.3**) |
| PHP | 8.3.7 (CLI) |
| التبعيات | `composer install` نجح، 69 حزمة |
| .env | **غير موجود** (لا يحتوي المستودع على `.env` ولا `.env.example`؛ يجب إنشاؤه محليًا وفقًا لـ MySQL/Redis) |
| نقطة دخول الترحيلات | لا توجد (لا `think`/`artisan`؛ webman لا يتضمن ترحيلات، وM0 لا توجد به مهام ترحيل) |
| الاختبارات | `vendor/bin/phpunit`: 60 tests / 136 assertions، **4 errors / 7 failures / 6 warnings / 1 risky — ليست خضراء بالكامل** |

## الوحدات المفعّلة (مؤكَّد في README)

- **مصادقة JWT**: تسجيل الدخول/التحديث/الخروج، كابتشا بالنقر، قفل الحساب (5 محاولات فاشلة → قفل 15 دقيقة)، حد الجلسات المتزامنة (≤3 رموز لكل مستخدم)
- **RBAC**: شجرة الأدوار/الصلاحيات، تفويض بدرجة دقة method.path
- **تدقيق العمليات**: الاستعلام عن السجلات + تحديد 8 مصادر منصات
- **إدارة الملفات**: رفع / تصدير Excel / تصدير PDF (مع إخفاء البيانات)
- **i18n**: التبديل بين الصينية والإنجليزية (Accept-Language / ?lang=)
- أخرى: لوحة البيانات (ذاكرة Redis)، إعدادات النظام، فحص الصحة/metrics/OpenAPI 3.0، حماية أمنية من 18 طبقة

## تفاصيل فشل الاختبارات (كلها ثغرات مشروع قائمة، ولم تُدخلها هذه التغييرات)

| مجموعة الاختبارات | الفشل | السبب |
|---|---|---|
| `EnvConfigTest` (5 بنود) | 4 failure + 1 error | تفرض الاختبارات وجود `.env`/`.env.example` وأن تكون قيم getenv لـ `APP_NAME`/`JWT_SECRET_KEY`/`DB_HOST` وغيرها مضبوطة؛ المستودع لا يتضمن env نموذجيًا |
| `CaptchaTest` (4 بنود) | 3 error + 1 failure (بالإضافة إلى 1 risky بلا تأكيدات) | كابتشا النقر تعتمد على تخزين Redis، غير متوفر محليًا |
| `BackendEnhancementTest` (بندان) | 2 failure | يؤكد أن مصدر البيانات `user` يحتوي searchable وأن الوسيط يحتوي cors/rate_limit — انحراف بين الإعدادات وتأكيدات الاختبار |

خطوات استعادة اللون الأخضر محليًا: إنشاء `.env` وفقًا لمفاتيح الإعدادات داخل `config/` (استكمال المفاتيح التي يعتمد عليها EnvConfigTest)، وتوفير MySQL + Redis (لـ CaptchaTest)، ثم يفصل المسؤول في انحرافي الإعدادات في BackendEnhancementTest.

## حالة جاهزية gRPC (T3)

- حزم Composer مثبتة: `grpc/grpc 1.82.0` و`google/protobuf 5.35` (`--no-plugins` يتجاوز خطأ التحميل المكرر لإضافة security-php)
- أكواد PHP الجاهزة (stubs) مولّدة: `admin/generated/` (`Social/Admin/V1/AdminServiceClient.php` وغيرها، بما فيها مجموعات العقود الثلاث: infra/user)
- **امتداد grpc لـ PHP غير مثبت**: pecl بلا صلاحيات كتابة وsudo يتطلب كلمة مرور؛ يلزم `sudo pecl install grpc` قبل تشغيل عميل gRPC

## نقاط دخول التحويل (ثمانية بنود جديدة من §3.4 في وثيقة التصميم)

1. منصة مراجعة المحتوى: مراجعة ثنائية اللغة جنبًا إلى جنب للمنشورات/التعليقات/الصور، قوالب متعددة اللغات لأسباب الرفض، عقوبات المستخدمين
2. قائمة انتظار معالجة البلاغات
3. مكتب طلبات GDPR (تذاكر التصدير/الحذف)
4. ربط لوحة البيانات بـ bee_tsdb
5. إدارة مدخلات i18n (CRUD مشترك بين الأطراف الأربعة)
6. إدارة مكتبة الهدايا (SKU والسعر والتأثيرات والأسماء متعددة اللغات)
7. إعداد مزودي البث المباشر (استراتيجية التوجيه، ترتيب التبديل)
8. مراجعة طلبات السحب

**نقاط تكامل gRPC**: أكواد العقود الجاهزة في جانب admin موجودة في `admin/generated/` (إعادة استخدام `Social/Admin/V1` لفحص الاتصال + رسائل الأعمال اللاحقة)؛ استدعاءات service تمر عبر `Social\User\V1\UserServiceClient` واستدعاءات infrastructure عبر `Social\Infra\V1\InfraServiceClient`؛ سلسلة فحص الاتصال مع service/infrastructure موصوفة في `service/README.grpcs.md` وفحوصات التكامل T10.
