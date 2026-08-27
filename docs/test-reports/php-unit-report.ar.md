# تقرير اختبارات الوحدة PHP
**语言 / Languages:** [中文](php-unit-report.md) · [English](php-unit-report.en.md) · [한국어](php-unit-report.ko.md) · [Русский](php-unit-report.ru.md) · [Deutsch](php-unit-report.de.md) · [Français](php-unit-report.fr.md) · [Español](php-unit-report.es.md) · [Português](php-unit-report.pt.md) · [हिन्दी](php-unit-report.hi.md) · [العربية](php-unit-report.ar.md) · [বাংলা](php-unit-report.bn.md) · [Bahasa Indonesia](php-unit-report.id.md) · [日本語](php-unit-report.ja.md)

- التاريخ: 2026-08-27
- التنفيذ: `vendor/bin/phpunit` (PHPUnit 10.5.64, PHP 8.3.7)
- النطاق: admin/ (لوحة إدارة webman) + service/ (الخدمة الرئيسية webman)

## نظرة عامة على النتائج

| المشروع | حالات الاختبار | التأكيدات | النتيجة |
|------|------|------|------|
| service | 159 | 408 | ✅ كلها ناجحة (OK) |
| admin | 67 | 180 | ✅ كلها ناجحة (OK) |

## ملاحظات البيئة

- MySQL 127.0.0.1:3306 (root، بدون كلمة مرور)، قاعدة `social` (social_*) و`open_admin` (erik_*) منشأتان ومزوّدتان بالبيانات (دور super_admin، 39 صلاحية)
- Redis 127.0.0.1:6379 قيد التشغيل (تخزين التحقق `poster:captcha:*`)؛ Elasticsearch غير مشغّل (يفحص الصحة بتحويله إلى unavailable، ولا يُحتسب فشلًا)
- service يعمل على 8788 وadmin على 8791
- لا يملك service ولا admin ملف `.env` (أزال المستودع ملفات env المرفوعة بالخطأ، commit e5379fc)؛ يعمل التطبيقان على الإرجاع الافتراضي `getenv('X') ?: القيمة الافتراضية` في `config/*.php`
- **امتداد Imagick محمّل لكن الثابتة `RESOURCETYPE_PIXELS` مفقودة** (هذا البناء يحتوي فقط على مجموعة ثوابت RESOURCETYPE_* الجديدة)؛ مُنشئ ImagickDriver في poster-php يستشهد بهذه الثابتة فينهار فورًا

## service (159/159 أخضر بالكامل)

- مطابق لخط الأساس للدفعة السابقة؛ يغطي: المصادقة/الوسائط/JWT، المستخدمون، المنشورات، التعليقات، المتابعات، الإشعارات، مزامنة البحث، IM، الغرف، المكالمات (CallCenter/CallState)، الصوت، علاقات النماذج، معالجة الإجراءات (WS)
- أضاف M5 وحدة البث المباشر (LiveCenter: إنشاء/تفاصيل/دانماكو/ربط الميكروفون/إغلاق)، 23 حالة، بلا تراجعات

## admin (الدفعة السابقة 49/60 ← هذه الدفعة 67/67 أخضر بالكامل)

### إصلاح: خلل فعلي في الكود (موضع واحد)

| الموضع | السبب الجذري | الإصلاح |
|------|------|------|
| `config/poster.php` | `image.driver` افتراضيًا `auto`؛ يختار DriverFactory ImagickDriver عند رصد امتداد Imagick، لكن Imagick هذا الجهاز يفتقد الثابتة `RESOURCETYPE_PIXELS` ← توليد التحقق/الملصقات يعطي 500 مباشرة (الخدمة عبر الإنترنت متأثرة بالمثل) | أُضيف حارس ثابتة في كشف المحرك: `getenv('POSTER_IMAGE_DRIVER') ?: (defined('Imagick::RESOURCETYPE_PIXELS') ? 'auto' : 'gd')`؛ ارتداد تلقائي إلى GD عند غياب الثابتة |

### إصلاح: تأكيدات قديمة (حُدّثت بعد مطابقة الكود الحالي)

| ملف الاختبار | الحالة | السبب الجذري | التصحيح |
|----------|------|------|------|
| EnvConfigTest | env_file_exists / env_example_file_exists / getenv_reads_env_variables / config_env_keys_exist_in_dotenv (4 فاشلة + 1 خطأ) | يؤكد وجود `.env`/`.env.example` وقيم getenv؛ لكن المستودع أزال ملفات env ولا يمكن إعادة بنائها | أُعيدت كتابته كعقد «العمل بدون .env»: كل مفتاح `getenv()` يجب أن يملك قيمة افتراضية `?:`، والإعداد الافتراضي يشير إلى الخدمات المحلية (127.0.0.1:3306/open_admin)، وأنواع الإعدادات الحرجة صحيحة |
| BackendEnhancementTest | test_admin_user_source_contains_searchable | لم يعد AdminUser يستخدم trait Searchable (يستخدم الآن `Erikwang2013\Encryptable\Encryptable` لتشفير/فك تشفير الحقول بشفافية؛ `toSearchableArray()` محفوظ) | تغيّر التأكيد إلى trait Encryptable؛ تأكيد toSearchableArray كان ناجحًا أصلًا فأُبقي |
| BackendEnhancementTest | test_middleware_config_contains_cors_and_rate_limit | أصبح `config/middleware.php` بصيغة مفتاح المجموعة العام `'@'`؛ لم يعد المصفوفة العليا تحتوي فئات الوسائط مباشرة | تغيّر التأكيد إلى فحص أن `$middlewares['@']` يحتوي على Cors وRateLimit |
| CaptchaTest | جميع الحالات الـ 7 (أصلًا 6 أخطاء + 1 فاشلة) | تقادم مزدوج: (أ) ثابتة Imagick مفقودة (أُصلحت بالفعل عبر poster.php)؛ (ب) التأكيدات مبنية على عقد poster-php القديم — تحوّل `extra.targets` (مع x/y) إلى `extra.texts` (text+order فقط)، والإحداثيات تعيش في طبقة التخزين فقط؛ تغيّر صيغة التحقق من النقر من `['x'=>, 'y'=>]` إلى أزواج أرقام `[x, y]` | أُعيدت كتابته وفق العقد الحالي: البنية/عدد مستويات الصعوبة (2/3/4)/التحقق من الحقول؛ النقر الصحيح يقرأ الإحداثيات من Redis (`poster:captcha:{key}` → `data.targets`) للتحقق، والنقر الخاطئ يفشل، وبعد تجاوز max_attempts (3) يُستهلك المفتاح ويُحذف، وفرادة المفتاح |

### اختبارات جديدة (ملف واحد، 12 حالة)

`tests/AdminControllerTest.php` (مع ترويسة حقوق النشر)، يغطي:

- **BaseController::decodeId** (سلوك 404 المُصلح حديثًا): دورات encode/decode متسقة؛ hashid غير صالح يرمي `support\exception\NotFoundException` برمز 404؛ encodeIds يعدّل حقول ID فقط
- **RoleController**: تحديث دور super_admin يعيد 403 (بيانات DB حقيقية)
- **PermissionController::buildTree**: تداخل شجرة الصلاحيات (مستويان) + جميع معرفات العقد محوّلة إلى hashid
- **ConfigController**: غياب group/key/value ← تحقق 422؛ hashid غير صالح ← 404
- **ExportController**: قائمة حقول التصدير الحساسة لـ `admin_user` هي phone/email/id_card (بقية الجداول فارغة)؛ HTML الخاص بـ PDF يهرب العنوان/قيم الخلايا عبر htmlspecialchars (حماية من XSS) ويتضمن بيان حقوق النشر

### ملاحظات معروفة

- طلب webman المُنشأ في الاختبارات يُمرَّر كرسالة HTTP خام (buffer) — معامل مُنشئ workerman Request هو buffer؛ تمرير method/uri فقط لا يكفي لتحليل جسم POST؛ راجع تعليقات AdminControllerTest
- حالة النقر الصحيح للتحقق تقرأ الأهداف المخزنة من Redis؛ إذا لم يكن Redis متاحًا تُعلَّم الحالة markTestSkipped ولا تؤثر على نتيجة المجموعة

## غير مغطى / يُستكمل لاحقًا

- تشفير/فك تشفير Encryptable لنماذج admin، ووسائط OperationLog/AdminPermission ومسارات ذاكرة RBAC ما زالت تفتقر إلى اختبارات الوحدة؛ يُوصى بتغطيتها عبر اختبارات API أو دفعة لاحقة
- مسارات service التي تعتمد على خدمات خارجية (ES/gRPC) ما زالت بتحقق وحدة فقط عبر stub؛ مستوى التكامل يُغطى عبر اختبارات API
