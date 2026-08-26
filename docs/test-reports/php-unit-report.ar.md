# تقرير اختبارات الوحدة PHP
**语言 / Languages:** [中文](php-unit-report.md) · [English](php-unit-report.en.md) · [한국어](php-unit-report.ko.md) · [Русский](php-unit-report.ru.md) · [Deutsch](php-unit-report.de.md) · [Français](php-unit-report.fr.md) · [Español](php-unit-report.es.md) · [Português](php-unit-report.pt.md) · [हिन्दी](php-unit-report.hi.md) · [العربية](php-unit-report.ar.md) · [বাংলা](php-unit-report.bn.md) · [Bahasa Indonesia](php-unit-report.id.md) · [日本語](php-unit-report.ja.md)

- التاريخ: 2026-08-27
- التنفيذ: `vendor/bin/phpunit` (PHPUnit 10.5.64, PHP 8.3.7)
- النطاق: admin/ (لوحة إدارة webman) + service/ (الخدمة الرئيسية webman)

## نظرة عامة على النتائج

| المشروع | حالات الاختبار | التأكيدات | النتيجة |
|------|------|------|------|
| service | 136 | 348 | ✅ كلها ناجحة (OK) |
| admin | 60 | 136 | ⚠️ 49 ناجحة / 4 أخطاء / 7 فاشلة |

## service (أخضر بالكامل)

- ملفات اختبار جديدة (في هذه الدفعة): AuthMiddlewareTest وUserBriefTest وSearchSyncTest وActionHandlerTest وJwtHelperTest وVoiceControllerTest وMonitorTest وModelRelationTest وغيرها؛ وبعد دمجها مع ملفات الاختبار الـ 24 الموجودة بلغ المجموع 136 حالة، كلها ناجحة
- الوحدات المشمولة: المصادقة/الوسائط/JWT، المستخدمون، المنشورات، التعليقات، المتابعات، الإشعارات، مزامنة البحث، IM، الغرف، المكالمات (CallCenter/CallState)، الصوت، علاقات النماذج، معالجة الإجراءات (WS)

### إصلاح: تعلّق عشوائي لمجموعة الاختبارات (مهم)

- العَرَض: أثناء التشغيل الكامل تتجمد العملية بشكل عشوائي؛ تشغيل ملف واحد/مجموعة فرعية ينجح
- السبب الجذري: `new Worker()` في `ActionHandlerTest::setUp` يسجّل المثيل في **السجل الثابت** `Worker::$workers`؛ بعد ذلك يرى أي `CallCenter::start` أن «يوجد Worker» فيستدعي `Timer::add` ← `pcntl_alarm(1)` يثبّت مؤقت SIGALRM، وتتجمد العملية عند الخروج
- الإصلاح: setUp يلتقط لقطة للسجل، وtearDown يستعيده (`ReflectionProperty` يعيد كتابة `workers`/`pidMap`)
- الموقع: `service/tests/ActionHandlerTest.php`

## admin (49/60؛ حالات الفشل كلها اختبارات مسبقة وهي مشاكل بيئة/إعدادات)

| حالة الاختبار | سبب الفشل | التصنيف |
|------|----------|------|
| EnvConfigTest (4 فاشلة + 1 خطأ) | `admin/.env` غير موجود؛ تأكيدات getenv/dotenv تفشل | بيئة الاختبار تفتقر إلى .env |
| CaptchaTest (3 أخطاء + 1 فاشلة + 1 risky) | التحقق يعتمد على خدمة/Redis قيد التشغيل؛ بيئة اختبار الوحدة تُرجع null | اعتماد على البيئة |
| BackendEnhancementTest (2 فاشلة) | يؤكد وجود `app/middleware/Cors` واحتواء admin_user على searchable — الإعداد الحالي لا يطابق التأكيدات | تأكيدات إعدادات قديمة |

ملاحظة: admin/tests كلها ملفات تاريخية مسبقة؛ لم تُضف اختبارات وحدة جديدة لـ admin في هذه الدفعة (كان التركيز على service).

## غير مغطى / يُستكمل لاحقًا

- وحدات admin (model/middleware/view) تفتقر إلى اختبارات الوحدة
- مسارات service التي تعتمد على خدمات خارجية (ES/gRPC) خضعت لتحقق وحدة فقط عبر stub؛ يُوصى بتغطية مستوى التكامل عبر اختبارات API
