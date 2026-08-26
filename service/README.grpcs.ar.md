# بيئة تشغيل PHP gRPC

**语言 / Languages:** [中文](README.grpcs.md) · [English](README.grpcs.en.md) · [한국어](README.grpcs.ko.md) · [Русский](README.grpcs.ru.md) · [Deutsch](README.grpcs.de.md) · [Français](README.grpcs.fr.md) · [Español](README.grpcs.es.md) · [Português](README.grpcs.pt.md) · [हिन्दी](README.grpcs.hi.md) · [العربية](README.grpcs.ar.md) · [বাংলা](README.grpcs.bn.md) · [Bahasa Indonesia](README.grpcs.id.md) · [日本語](README.grpcs.ja.md)

هذا الدليل مشروع **عميل** webman gRPC (أكواد العقود الجاهزة (stubs) في `generated/`، وتُولَّد بواسطة `scripts/gen-contracts.sh`).

## التبعيات

- حزم Composer: `grpc/grpc` (مكتبة عميل PHP)، `google/protobuf` (بيئة تشغيل الرسائل) — مضافة بالفعل إلى `composer.json`.
- امتدادات PHP: `grpc` (إلزامي؛ اتصالات العميل تعتمد على امتداد C). امتداد `google/protobuf` اختياري (فضّل الامتداد إن وُجد، وإلا استخدم حزمة Composer).

## تثبيت امتداد grpc

```bash
php -m | grep grpc || sudo pecl install grpc   # php_dir 属 root，pecl 需 sudo
```

بعد التثبيت، تأكد أن `php -i | grep grpc` يعرض `grpc support => enabled`.

جهاز التطوير الحالي (2026-08-17): حزم Composer مثبتة (grpc/grpc 1.82، google/protobuf 5.35)، **امتداد grpc غير مثبت** (pecl بلا صلاحيات كتابة وsudo يتطلب كلمة مرور). يجب تثبيته قبل تشغيل gRPC محليًا؛ CI (T10) يثبّته عبر `extensions: grpc` من shivammathur/setup-php.

## ملاحظات

في هذا المستودع، يؤدي `composer require` إلى خطأ فادح في تحميل الفئات المكرر في إضافة `erikwang2013/security-php`
(يُحمَّل Installer.php داخل vendor مرة عبر آلية الإضافات ومرة عبر autoload)؛ أضف `--no-plugins` لتجاوز ذلك:

```bash
composer require grpc/grpc google/protobuf --no-interaction --no-plugins
```

## سكربت فحص الاتصال

`php scripts/probe_ping.php` (مقدَّم من T5) يرسل `InfraService.Ping` إلى `127.0.0.1:50051` الخاصة بـ infrastructure.
