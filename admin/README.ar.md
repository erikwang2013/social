# لوحة الإدارة المفتوحة (open-admin)
**语言 / Languages:** [中文](README.md) · [English](README.en.md) · [한국어](README.ko.md) · [Русский](README.ru.md) · [Deutsch](README.de.md) · [Français](README.fr.md) · [Español](README.es.md) · [Português](README.pt.md) · [हिन्दी](README.hi.md) · [العربية](README.ar.md) · [বাংলা](README.bn.md) · [Bahasa Indonesia](README.id.md) · [日本語](README.ja.md)

نظام لوحة إدارة شامل (Full-Stack) مبني على webman v2 + Flutter.

> [English version](README_EN.md) | [مخططات البنية](docs/ARCHITECTURE.md) | [وثيقة التصميم](docs/DESIGN.md) | [بنية الأمان](docs/SECURITY.md) | [مرجع API](docs/API.md)

## قائمة الميزات

| المجال | الوظيفة | الوصف |
|--------|------|------|
| 🔐 المصادقة | تسجيل الدخول / تحديث التوكن / تسجيل الخروج | كابتشا النقر + JWT + القائمة السوداء |
| | قفل الحساب | 5 محاولات فاشلة تقفل الحساب 15 دقيقة |
| | حد الجلسات المتزامنة | 3 توكنات صالحة كحد أقصى لكل مستخدم |
| 📊 لوحة القيادة | إحصائيات فورية / مخطط اتجاهات / توزيع / أحدث العمليات | كاش Redis لمدة 5 دقائق |
| 👥 إدارة المستخدمين | CRUD + حذف جماعي / تفعيل-تعطيل | حذف ناعم + تأكيد كلمة المرور |
| | استيراد Excel جماعي | تحقق سطر بسطر + تقرير أخطاء |
| 🔒 الأدوار والصلاحيات | CRUD للأدوار + شجرة الصلاحيات | تفويض RBAC بدقة method.path |
| ⚙ إعدادات النظام | CRUD لأزواج المفاتيح-القيم | إدارة مجمّعة |
| 📋 تدقيق العمليات | الاستعلام عن السجلات + كشف المصدر | تعرّف تلقائي على 8 منصات |
| 📁 إدارة الملفات | رفع / تصدير Excel / تصدير PDF | إخفاء تلقائي للبيانات الحساسة |
| 🛡 الحماية | دفاع متعمق من 18 طبقة | XSS/حقن SQL/عبور المسار/حقن الأوامر/CSRF/تحديد المعدل/CSP... |
| 🏥 التشغيل | فحص الصحة / metrics / توثيق API / security.txt | Prometheus + OpenAPI 3.0 + توثيق تفاعلي hg/apidoc |
| 🌐 التدويل | التبديل بين الصينية والإنجليزية | ترويسة Accept-Language / معامل ?lang= |

## التقنيات المستخدمة

| الطبقة | التقنية | الوصف |
|---|------|------|
| إطار الواجهة الخلفية | webman v2 (workerman) | إطار PHP عالي الأداء بعمليات مقيمة |
| إصدار PHP | 8.3+ | |
| قاعدة البيانات | MySQL 8.0+ | بادئة الجداول `erik_`، مفاتيح أساسية BIGINT بدون تزايد تلقائي |
| محرك البحث | Elasticsearch | المزامنة والاستعلام عبر `webman-scout` |
| واجهة الإدارة | Flutter 3.x | نسخة الويب بأسلوب لوحة إدارة للحاسوب (`apps/flutter/`) |
| الجوال | HarmonyOS ArkTS | عميل هارموني أو إس أصلي (`apps/harmonyos/`)، يدعم الهاتف/الجهاز اللوحي/2في1 |

## التبعيات الأساسية

| الحزمة | الغرض |
|---|------|
| `erikwang2013/snowflake-php` | توليد مفاتيح أساسية BIGINT فريدة عالميًا بخوارزمية Snowflake |
| `erikwang2013/hashids` | تشفير وفك تشفير المعرفات في طبقة API، يخفي المعرفات الحقيقية في قاعدة البيانات |
| `erikwang2013/jwt-webman` | إصدار توكنات JWT والتحقق منها |
| `erikwang2013/encryption` | تشفير البيانات الحساسة في طبقة النقل |
| `erikwang2013/encryptable` | تشفير وفك تلقائي للحقول الحساسة في قاعدة البيانات |
| `erikwang2013/webman-scout` | مزامنة البيانات والبحث النصي الكامل في Elasticsearch |
| `erikwang2013/season` | بيانات أعلام الدول |
| `erikwang2013/poster-php` | توليد كابتشا النقر والتحقق منها + توليد الملصقات |
| `phpoffice/phpspreadsheet` | تصدير Excel |
| `barryvdh/laravel-dompdf` | تصدير PDF (استنادًا إلى Dompdf) |

## هيكل المشروع

```
open-admin/
├── app/
│   ├── admin/controller/       # 管理端控制器
│   │   ├── DashboardController.php # 仪表盘（Redis缓存）
│   │   ├── UserController.php      # 用户 CRUD + 批量操作
│   │   ├── RoleController.php      # 角色 CRUD
│   │   ├── PermissionController.php# 权限 CRUD
│   │   ├── ConfigController.php    # 系统配置 CRUD
│   │   ├── LogController.php       # 操作日志查询
│   │   ├── ProfileController.php   # 个人中心 + 登出
│   │   ├── ExportController.php    # Excel/PDF 导出
│   │   ├── ImportController.php    # Excel 导入用户
│   │   ├── UploadController.php    # 文件上传
│   │   ├── HealthController.php    # 健康检查
│   │   ├── DocsController.php      # OpenAPI 文档
│   │   └── BaseController.php      # 基础控制器
│   ├── api/
│   │   └── v1/controller/          # API v1 控制器（版本由请求头 API-Version 控制）
│   │       ├── CaptchaController.php # 点击验证码
│   │       └── AuthController.php    # 登录/刷新令牌
│   ├── common/                 # 公共工具类
│   │   ├── HashidsService.php  # ID 编解码
│   │   ├── SnowflakeService.php# Snowflake ID 生成
│   │   └── EncryptionService.php # 数据加解密 + 脱敏
│   ├── middleware/             # 中间件
│   │   ├── Cors.php            # 跨域
│   │   ├── SecurityFilter.php  # 攻击检测拦截（HTTP方法限制/XSS/SQL注入/路径遍历/命令注入/CSRF）
│   │   ├── RateLimit.php       # Redis 限流（滑动窗口 + 响应头）
│   │   ├── ApiVersion.php      # API 版本校验
│   │   ├── AdminAuth.php       # JWT 认证 + 黑名单
│   │   ├── AdminPermission.php # RBAC 权限校验
│   │   └── OperationLog.php    # 操作日志自动记录（含来源端检测）
│   └── model/                  # 数据模型
├── apps/
│   ├── flutter/                # Flutter Web 管理后台（PC 风格）
│   │   └── lib/app/
│   │       ├── pages/          # 5 个完整页面（仪表盘/用户/角色/配置/日志/个人中心）
│   │       ├── services/       # ApiService（JWT 拦截器）+ AuthService（Token 持久化）
│   │       └── layouts/        # 响应式管理后台布局（侧边栏+顶栏+内容区）
│   └── harmonyos/              # HarmonyOS 原生客户端（Token 无感刷新）
├── config/                     # 配置文件（含中文注释）
│   ├── route.php               # 路由 + API 版本策略
│   ├── middleware.php           # 全局中间件注册
│   └── ...                     # 各组件配置
├── database/migrations/        # SQL 迁移文件（含权限种子数据）
├── public/                     # 公共入口
├── runtime/                    # 运行时文件
└── vendor/                     # Composer 依赖
```

## متطلبات البيئة

- PHP >= 8.3
- Composer 2.x
- MySQL >= 8.0
- Flutter >= 3.41 (مطلوب فقط لتطوير الواجهة الأمامية)
- Elasticsearch >= 7.x (اختياري، مطلوب لوظيفة البحث)

## بدء سريع

### 1. تثبيت التبعيات

```bash
composer install
```

### 2. إعداد متغيرات البيئة

انسخ متغيرات البيئة وعدّلها (اختياري؛ إذا لم تُضبط تُستخدم القيم الافتراضية في `config/*.php`):

```bash
cp .env.example .env
```

عناصر الإعداد الرئيسية:

| متغير البيئة | الوصف | القيمة الافتراضية |
|---------|------|--------|
| `JWT_SECRET` | مفتاح توقيع JWT | `open-admin-jwt-secret-change-in-production` |
| `HASHIDS_SALT` | ملح Hashids | `open-admin-hashids-salt-2026` |
| `ENCRYPTION_KEY` | مفتاح تشفير API | قيمة افتراضية من 32 بايت |
| `SNOWFLAKE_DATACENTER_ID` | معرف مركز البيانات (0-31) | `1` |
| `SNOWFLAKE_WORKER_ID` | معرف عقدة العمل (0-31) | `1` |
| `SCOUT_HOSTS` | عنوان ES | `http://localhost:9200` |

**في بيئة الإنتاج، غيّر جميع المفاتيح إلى سلاسل عشوائية إلزاميًا.**

### 3. تثبيت بنقرة واحدة

بعد تشغيل الخدمة، افتح معالج التثبيت في المتصفح لإكمال تهيئة قاعدة البيانات وإنشاء حساب المسؤول:

```bash
php start.php start
```

يستمع افتراضيًا على `http://0.0.0.0:8787` (يمكن تغيير المنفذ في `config/server.php`).

افتح **`http://localhost:8787/install`** في المتصفح واملأ الحقول وفق المعالج:

| الخطوة | المحتوى |
|------|------|
| ① إعداد قاعدة البيانات | عنوان المضيف والمنفذ واسم قاعدة البيانات واسم المستخدم وكلمة المرور |
| ② إعداد المسؤول | اسم المستخدم وكلمة المرور للمسؤول (الافتراضي: admin / admin888) |

بعد النقر على «بدء التثبيت» يتم تلقائيًا إنشاء الجداول وبذر بيانات الصلاحيات وإنشاء حساب المسؤول وكتابة إعداد قاعدة البيانات في `.env`.

> بعد التثبيت يتم إنشاء ملف قفل `runtime/install.lock`. لإعادة التثبيت احذف هذا الملف فقط.

### 4. تسجيل الدخول

تفضل إلى `http://localhost:8787` وسجّل الدخول ببيانات المسؤول التي ضُبطت أثناء التثبيت.

### 5. تشغيل الواجهة الأمامية (اختياري)

**لوحة إدارة Flutter (الويب):**

```bash
cd apps/flutter
flutter pub get
flutter run -d chrome    # Web 端（PC 管理后台风格）
```

**عميل HarmonyOS (الجوال):**

افتح مجلد `apps/harmonyos/` باستخدام DevEco Studio وشغّله على جهاز حقيقي أو محاكٍ.

### 6. نشر بنقرة واحدة عبر Docker Compose (موصى به للإنتاج)

يوفر المشروع تنسيق Docker كاملًا يتضمن 5 خدمات: Nginx وPHP (webman app) وMySQL وRedis وElasticsearch.

```bash
# 1. 配置 Docker 环境变量
cp .env.docker .env

# 2. 启动所有服务
docker-compose up -d

# 3. 浏览器访问安装向导完成初始化
# http://localhost:8787/install  (填入数据库和管理员信息)
# 或手动执行 SQL 迁移（进入 app 容器）:
# docker-compose exec app mysql -h mysql -u root -p < database/migrations/open_admin.sql

# 4. 访问
# http://localhost:8787  (webman)
# http://localhost:8080  (Nginx 反向代理)
```

- `Dockerfile`: PHP 8.3 + OPcache + Composer، استنادًا إلى `php:8.3-cli`
- `docker-compose.yml`: تنسيق 5 خدمات، عزل الشبكة، استمرارية مجلدات البيانات
- `.env.docker`: متغيرات بيئة مخصصة لـ Docker


## قواعد قاعدة البيانات

- **بادئة الجدول**: `erik_`
- **المفتاح الأساسي**: كل الجداول تستخدم `id BIGINT UNSIGNED NOT NULL`، **AUTO_INCREMENT معطل**
- **توليد المعرف**: تولد المفاتيح الأساسية في طبقة التطبيق عبر `SnowflakeService::generate()`، فريدة في البيئة الموزعة
- **الحقول الإلزامية**: يجب أن يحتوي كل جدول على `id` و`created_at` و`updated_at`
- **الحذف الناعم**: الجداول التي تحتاج حذفًا ناعمًا تضيف `deleted_at DATETIME DEFAULT NULL`
- **الحقول الحساسة**: رقم الهاتف والبريد الإلكتروني ورقم الهوية إلخ تُشفَّر تلقائيًا عبر إضافة `encryptable`، ويُخزَّن النص المشفر في عمود `VARCHAR(500)`

## مرجع API

المواصفات الكاملة لـ API (تنسيق الاستجابة الموحد، رموز أخطاء الأعمال، معالجة المعرفات، إصدارات API، تحديد المعدل، بنية الوسائط الوسيطة، تدفقات المصادقة والكابتشا) وقائمة جميع الواجهات موجودة في **[مرجع API](docs/API.md)**.

## ملاحظات الواجهة الأمامية

### لوحة إدارة Flutter (أسلوب الحاسوب)

- **التخطيط**: شريط جانبي قابل للطي (64px/240px) + شريط علوي + منطقة محتوى، ثلاث نقاط توقف تفاعلية (هاتف/جهاز لوحي/حاسوب)
- **الصفحات**: تسجيل الدخول، لوحة القيادة، إدارة المستخدمين، الأدوار والصلاحيات، إعدادات النظام، سجلات العمليات، الملف الشخصي
- **إدارة الحالة**: GetX (مفرد `ApiService` + استمرارية التوكن في `AuthService`)
- **لوحة القيادة**: بطاقات الإحصائيات، مخطط خطي للاتجاهات (fl_chart)، مخطط دائري، سجلات أحدث العمليات
- **التصدير**: تصدير Excel/PDF؛ ملفات PDF تحتوي معلومات حقوق نشر غير قابلة للإزالة
- **العمليات الجماعية**: حذف جماعي متعدد التحديد، تفعيل/تعطيل جماعي
- **المظهر**: Material 3، مظهران فاتح/داكن

### عميل HarmonyOS للجوال

- **الصفحات**: تسجيل الدخول، لوحة القيادة، قائمة/تفاصيل المستخدم، الملف الشخصي
- **المصادقة**: JWT Bearer + تجديد تلقائي صامت للتوكن عند 401، وإعادة توجيه تلقائية لصفحة الدخول عند فشل التجديد
- **التخزين**: يُدار التوكن عبر AppStorage

## قواعد التطوير

- لا تُسبق الدوال/الفئات العامة بـ `\` وتُستورد جميعها عبر `use`
- يجب أن تتضمن جميع ملفات PHP إشعار حقوق النشر في رأس الملف
- يجب أن تتضمن جميع ملفات الإعداد تعليقات توضيحية بالصينية
- يجب توليد المفاتيح الأساسية عبر snowflake في طبقة التطبيق، والتزايد التلقائي ممنوع
- يجب تشفير وفك تشفير جميع المعرفات في معاملات واستجابات API عبر hashids
- تخزّن الوسيطة AdminPermission صلاحيات المستخدم في كاش Redis (TTL=60s)، مما يزيل اختناق استعلامات N+1

## النشر

### Docker Compose (موصى به)

يوفر جذر المشروع `docker-compose.yml` لتنسيق 5 خدمات:

| الخدمة | الصورة | المنفذ |
|------|------|------|
| `nginx` | nginx:alpine | 80, 443 |
| `app` | بناء من `Dockerfile` المحلي | 8787 |
| `mysql` | mysql:8.0 | 3306 |
| `redis` | redis:7-alpine | 6379 |
| `elasticsearch` | elasticsearch:8.x | 9200 |

تُبنى صورة PHP عبر `Dockerfile`، الصورة الأساسية `php:8.3-cli`، مع تفعيل OPcache.

```bash
cp .env.docker .env
docker-compose up -d
```

### CI/CD

خط أنابيب التكامل المستمر GitHub Actions: `.github/workflows/ci.yml`

- فحص صياغة PHP (`php -l`)
- اختبارات الوحدة PHPUnit
- التحليل الساكن لـ Flutter (`flutter analyze`)

### النسخ الاحتياطي لقاعدة البيانات

مجلد `database/backup/`:

- `backup.sh` — نسخ احتياطي mysqldump + gzip، تنظيف تلقائي للنسخ الأقدم من 30 يومًا
- `restore.sh` — استعادة تفاعلية، يعرض النسخ المتاحة للاختيار

### إعداد أمان Nginx

للاستخدام في الإنتاج، راجع `docs/nginx-security.conf` لتقوية أمان الوكيل العكسي.

## البرمجيات مفتوحة المصدر طريق شاق — دعمكم مرحب به

| وي شات | علي باي |
|:---:|:---:|
| ![وي شات](./docs/weixinpay.png "وي شات") | ![علي باي](./docs/alipay.png "علي باي") |

---

## License

MIT

Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
