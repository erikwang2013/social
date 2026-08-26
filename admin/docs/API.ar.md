# مرجع واجهة برمجة التطبيقات (API)
**语言 / Languages:** [中文](API.md) · [English](API.en.md) · [한국어](API.ko.md) · [Русский](API.ru.md) · [Deutsch](API.de.md) · [Français](API.fr.md) · [Español](API.es.md) · [Português](API.pt.md) · [हिन्दी](API.hi.md) · [العربية](API.ar.md) · [বাংলা](API.bn.md) · [Bahasa Indonesia](API.id.md) · [日本語](API.ja.md)

> Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz

## 1. نظرة عامة

open-admin مبني على webman v2 ويوفر واجهة API بنمط RESTful JSON. جميع نقاط نهاية الإدارة تتطلب مصادقة JWT والتحقق من الصلاحيات RBAC؛ بينما تُوجَّه نقاط النهاية العامة إلى وحدات التحكم المنسوخة عبر رأس إصدار API.

- **عنوان URL الأساسي**: `http://localhost:8787`
- **إصدار API**: يُتحكم به عبر رأس الطلب `API-Version: v1` (الافتراضي v1 عند غيابه)
- **اللغة**: تُبدَّل عبر رأس `Accept-Language` أو معامل `?lang=zh_CN|en` (الافتراضي zh_CN)، ويتم اكتشافها تلقائيًا بواسطة وسيط Locale

> **ملخص نقاط النهاية**: المصادقة(5) | لوحة المعلومات(1) | المستخدمون(7) | الأدوار(4) | الصلاحيات(4) | الإعدادات(4) | السجلات(1) | المركز الشخصي(3) | الاستيراد/التصدير(3) | الرفع(1) | التشغيل(4: health/metrics/docs/security.txt) | إجمالي 37 نقطة نهاية
- **المصادقة**: `Authorization: Bearer <token>` (JWT)
- **تنسيق الاستجابة**: `{ "code": 0, "message": "success", "data": {...} }`
- **نقطة نهاية التوثيق**: `GET /api/docs` تُرجع مواصفة JSON OpenAPI 3.0

### متطلبات الطلب

- يُسمح فقط بأساليب `GET` / `POST` / `PUT` / `DELETE` / `OPTIONS` / `HEAD`؛ استخدام أساليب HTTP أخرى (مثل TRACE وCONNECT وPATCH) يُرجع 405
- يجب أن يضبط جميع طلبات `POST` / `PUT` رأس `Content-Type: application/json` (باستثناء رفع الملفات)، وإلا يُرجع 415
- يجب ألا يتجاوز حجم جسم الطلب 10MB، وإلا يُرجع 413
- يقوم SecurityFilter بفحص جميع مدخلات الطلب بحثًا عن XSS وحقن SQL وتجاوز المسار وحقن الأوامر؛ عند الإصابة يُرجع 403
- يؤدي فشل تسجيل الدخول 5 مرات متتالية إلى قفل الحساب (15 دقيقة)؛ أثناء القفل يُرجع طلب تسجيل الدخول 429
- يمكن للمستخدم الواحد الاحتفاظ بما يصل إلى 3 رموز صالحة في وقت واحد؛ عند التجاوز يُضاف الرمز الأقدم تلقائيًا إلى القائمة السوداء

## 2. رموز الخطأ

| code | المعنى | سيناريو الإطلاق |
|------|------|---------|
| 0 | نجاح | |
| 400 | خطأ في معاملات الطلب | تنسيق الطلب غير صحيح |
| 401 | غير مصادَق | الرمز مفقود / منتهي الصلاحية / في القائمة السوداء |
| 403 | لا صلاحية / اعتراض أمني | صلاحيات RBAC غير كافية / SecurityFilter مصاب |
| 404 | المورد غير موجود | هدف الاستعلام/التحديث/الحذف غير موجود |
| 405 | طريقة الطلب غير مسموحة | يُسمح فقط بـ GET/POST/PUT/DELETE/OPTIONS/HEAD؛ الطرق غير القياسية تُرفض مباشرة |
| 413 | جسم الطلب كبير جدًا | Content-Length يتجاوز 10MB |
| 415 | نوع وسائط غير مدعوم | Content-Type لطلب POST/PUT ليس JSON وليس رفع ملفات |
| 422 | فشل التحقق من المعاملات | حقل مطلوب مفقود، تنسيق غير مطابق، فشل التحقق التجاري |
| 429 | عدد طلبات مفرط | RateLimit مصاب / الحساب مقفل (فشل تسجيل الدخول 5 مرات متتالية يقفل 15 دقيقة) |
| 500 | خطأ داخلي في الخادم | |

## 3. نقاط النهاية العامة

جميع نقاط النهاية العامة مثبتة ضمن مجموعة `/api`، وتُوزَّع بواسطة وسيط `ApiVersion` وفق رأس `API-Version` إلى وحدة التحكم المنسوخة المقابلة (مثل `app\api\v1\controller\AuthController`).

### 3.1 فحص الصحة

```
GET /health
```

- **المصادقة**: غير مطلوبة
- **تحديد المعدل**: لا يوجد

**مثال على الاستجابة**:
```json
{
  "code": 0,
  "message": "success",
  "data": {
    "app": "open-admin",
    "version": "1.0",
    "php": "8.3.0",
    "database": "ok",
    "redis": "ok",
    "elasticsearch": "ok",
    "timestamp": 1716249600
  }
}
```

قيم `database` و`redis` و`elasticsearch`: `"ok"` | `"unavailable"`. يُرجع `elasticsearch` القيمة `"unavailable"` عندما يكون ES غير قابل للوصول؛ وإذا كانت حالة صحة المجموعة غير green/yellow يُرجع قيمة status الفعلية (مثل `"red"`).

### 3.2 توثيق API

```
GET /api/docs
```

- **المصادقة**: غير مطلوبة
- **تحديد المعدل**: الافتراضي العام (60 مرة/الدقيقة)
- **الاستجابة**: مواصفة JSON OpenAPI 3.0.3، تتضمن تعريفات جميع نقاط النهاية والمعاملات وSchema

### 3.3 توليد كلمة التحقق (Captcha)

```
POST /api/captcha/generate
```

- **المصادقة**: غير مطلوبة
- **رأس الطلب**: `API-Version: v1` (إلزامي)
- **تحديد المعدل**: الافتراضي العام (60 مرة/الدقيقة)

**جسم الطلب**:
```json
{
  "difficulty": "medium"
}
```

| الحقل | النوع | مطلوب | الوصف |
|------|------|------|------|
| difficulty | string | لا | `easy` / `medium` / `hard`، الافتراضي `medium` |

**مثال على الاستجابة** — نوع النقر (`type: "click"`):
```json
{
  "code": 0,
  "message": "success",
  "data": {
    "key": "abc123def456",
    "type": "click",
    "image": "data:image/png;base64,iVBORw0KGgo...",
    "extra": {
      "targets": [
        { "order": 1, "text": "A", "x": 120, "y": 85 },
        { "order": 2, "text": "B", "x": 310, "y": 42 }
      ]
    }
  }
}
```

**مثال على الاستجابة** — نوع السحب (`type: "slider"`):
```json
{
  "code": 0,
  "message": "success",
  "data": {
    "key": "def456abc789",
    "type": "slider",
    "image": "data:image/png;base64,iVBORw0KGgo...",
    "extra": {
      "x": 120,
      "y": 60,
      "puzzle_w": 50,
      "puzzle_h": 50,
      "puzzle": "data:image/png;base64,iVBORw0KGgo..."
    }
  }
}
```

**مثال على الاستجابة** — نوع التدوير (`type: "rotate"`):
```json
{
  "code": 0,
  "message": "success",
  "data": {
    "key": "ghi789abc012",
    "type": "rotate",
    "image": "data:image/png;base64,iVBORw0KGgo...",
    "extra": {
      "angle": 45
    }
  }
}
```

| الحقل | النوع | الوصف |
|------|------|------|
| key | string | معرّف كلمة التحقق، يُعاد عند التحقق |
| type | string | نوع كلمة التحقق: `click` / `slider` / `rotate` |
| image | string | صورة data URI بصيغة base64 |
| extra | object | بيانات إضافية مرتبطة بالنوع (انظر أدناه) |

**شرح `extra` حسب النوع**:

| type | حقول extra | النوع | الوصف |
|------|-----------|------|------|
| click | targets | array | أهداف النقر، تتضمن `order`(الترتيب) `text`(نص التلميح) `x` `y`(الإحداثيات) |
| slider | x, y | int | إحداثيات الزاوية العلوية اليسرى للفجوة (استنادًا إلى لوحة 300×200) |
| slider | puzzle_w, puzzle_h | int | عرض وارتفاع صورة اللغز |
| slider | puzzle | string | صورة اللغز data URI بصيغة base64 |
| rotate | angle | int | زاوية التدوير الصحيحة (0-359)، يجب تدوير `360-angle` لتصحيح الصورة |

### 3.4 التحقق من كلمة التحقق

```
POST /api/captcha/verify
```

- **المصادقة**: غير مطلوبة
- **رأس الطلب**: `API-Version: v1` (إلزامي)
- **تحديد المعدل**: الافتراضي العام (60 مرة/الدقيقة)

**جسم الطلب** — نوع النقر (`type: "click"`):
```json
{
  "key": "abc123def456",
  "type": "click",
  "clicks": [
    { "x": 120, "y": 85 },
    { "x": 310, "y": 42 }
  ]
}
```

**جسم الطلب** — نوع السحب (`type: "slider"`):
```json
{
  "key": "def456abc789",
  "type": "slider",
  "clicks": 120
}
```

**جسم الطلب** — نوع التدوير (`type: "rotate"`):
```json
{
  "key": "ghi789abc012",
  "type": "rotate",
  "clicks": 315
}
```

| الحقل | النوع | مطلوب | الوصف |
|------|------|------|------|
| key | string | نعم | مفتاح كلمة التحقق، الذي يُرجعه generate |
| type | string | نعم | نوع كلمة التحقق، يجب أن يطابق `type` الذي يُرجعه generate |
| clicks | متغير | نعم | بيانات الإجابة، يتغير التنسيق حسب type (انظر أدناه) |

**شرح `clicks` حسب النوع**:

| type | نوع clicks | الوصف | هامش الخطأ |
|------|------------|------|---------|
| click | `[{x:int, y:int}]` | مصفوفة إحداثيات النقر، بترتيب order | نصف قطر 18px |
| slider | `int` | إزاحة محور X للمنزلق | ±4px |
| rotate | `int` | زاوية التدوير (0-359) | ±5° |

**مثال على الاستجابة**:
```json
{
  "code": 0,
  "message": "验证通过",
  "data": { "valid": true }
}
```

بعد اجتياز التحقق، يكتب الخادم الخلفي `captcha_verified:{key}` في Redis (TTL 300 ثانية)، وعليه يسمح طرف تسجيل الدخول بالمرور.
عند فشل التحقق يكون `code` هو 422، و`message` هو `"验证失败，请重试"`، و`data.valid` هو `false`.

### 3.5 تسجيل الدخول

```
POST /api/auth/login
```

- **المصادقة**: غير مطلوبة
- **رأس الطلب**: `API-Version: v1` (إلزامي)
- **تحديد المعدل**: 10 مرات/الدقيقة (حسب IP + المسار)

**جسم الطلب**:
```json
{
  "username": "admin",
  "password": "djGYscnyS5V6mW6KyDFjB8vGwjBBnB3Odpyxu8LY...",
  "captcha_key": "abc123def456"
}
```

| الحقل | النوع | مطلوب | قاعدة التحقق | الوصف |
|------|------|------|---------|------|
| username | string | نعم | min:3, max:50 | اسم المستخدم |
| password | string | نعم | min:6, max:32 (نص عادي) | تشفير AES-256-CBC-HMAC ثم ترميز Base64 (متوافق مع النص العادي) |
| captcha_key | string | نعم | | مفتاح كلمة التحقق (يجب اجتياز التحقق `/api/captcha/verify` أولاً) |

### بروتوكول تشفير كلمة المرور

يستخدم **التشفير غير المتماثل RSA-2048**؛ المفتاح العام مخزَّن في كود الواجهة الأمامية (يمكن كشفه بأمان)، والمفتاح الخاص بحوزة الخادم فقط.

```
عملية التشفير (العميل):
  المفتاح العام RSA (PEM) → تشفير PKCS1v1.5 → ترميز Base64 → الإرسال

عملية فك التشفير (الخادم، تراجع تدريجي):
  1. فك تشفير المفتاح الخاص RSA → نجاح وكان UTF-8 صالحًا → استخدام النتيجة المفكوكة
  2. فك تشفير AES-256-CBC-HMAC → نجاح → استخدام النتيجة المفكوكة (توافق مع العملاء القدامى)
  3. تراجع النص العادي → استخدام المدخل الأصلي مباشرة
```

المفتاح العام مدمج في تطبيق الواجهة الأمامية، ولا حاجة لنقله عبر الشبكة. المفتاح الخاص مخزَّن فقط في `RSA_PRIVATE_KEY` داخل `.env`، ولا يجوز تسريبه.

> تشفير AES المتماثل هو حل توافق للإصدارات القديمة، وسيُزال بعد ترحيل جميع العملاء إلى RSA.

**مثال على الاستجابة**:
```json
{
  "code": 0,
  "message": "登录成功",
  "data": {
    "access_token": "eyJhbGciOiJIUzI1NiIs...",
    "refresh_token": "eyJhbGciOiJIUzI1NiIs...",
    "expires_in": 7200,
    "user": {
      "id": "a1b2c3d4",
      "username": "admin",
      "real_name": "管理员"
    }
  }
}
```

| الحقل | النوع | الوصف |
|------|------|------|
| access_token | string | رمز الوصول JWT |
| refresh_token | string | رمز التحديث JWT |
| expires_in | int | مدة صلاحية رمز الوصول (بالثواني)، الافتراضي 7200 |
| user.id | string | معرف المستخدم المشفر بـ hashid |
| user.username | string | اسم المستخدم |
| user.real_name | string | الاسم الحقيقي |

**الأخطاء المحتملة**:
- 422: فشل التحقق من المعاملات (حقل مطلوب مفقود، تنسيق غير مطابق)
- 422: يرجى إكمال التحقق من كلمة التحقق أولاً (لم يجتز captcha_key `/api/captcha/verify`)
- 401: اسم المستخدم أو كلمة المرور غير صحيحة
- 403: الحساب معطَّل
- 429: الحساب مقفل، يرجى المحاولة بعد 15 دقيقة (يُطلق بعد فشل تسجيل الدخول 5 مرات متتالية)

### 3.6 التسجيل

```
POST /api/auth/register
```

- **المصادقة**: غير مطلوبة
- **رأس الطلب**: `API-Version: v1` (إلزامي)
- **تحديد المعدل**: 5 مرات/الدقيقة (حسب IP + المسار)

**جسم الطلب**:
```json
{
  "username": "newuser",
  "password": "djGYscnyS5V6mW6KyDFjB8vGwjBBnB3Odpyxu8LY...",
  "real_name": "新用户",
  "captcha_key": "abc123def456"
}
```

| الحقل | النوع | مطلوب | قاعدة التحقق | الوصف |
|------|------|------|---------|------|
| username | string | نعم | min:3, max:50 | اسم المستخدم (فريد) |
| password | string | نعم | min:6, max:32 (نص عادي) | تشفير AES-256-CBC-HMAC ثم ترميز Base64 |
| real_name | string | نعم | max:50 | الاسم الحقيقي |
| captcha_key | string | نعم | | مفتاح كلمة التحقق (يجب اجتياز التحقق `/api/captcha/verify` أولاً) |

**مثال على الاستجابة**:
```json
{
  "code": 0,
  "message": "注册成功",
  "data": {
    "access_token": "eyJhbGciOiJIUzI1NiIs...",
    "refresh_token": "eyJhbGciOiJIUzI1NiIs...",
    "expires_in": 7200,
    "user": {
      "id": "e5f6g7h8",
      "username": "newuser",
      "real_name": "新用户"
    }
  }
}
```

بعد نجاح التسجيل يُرجع رمز JWT مباشرة؛ حالة المستخدم مفعَّلة افتراضيًا (status=1).

### 3.7 تحديث الرمز

```
POST /api/auth/refresh
```

- **المصادقة**: غير مطلوبة
- **رأس الطلب**: `API-Version: v1` (إلزامي)
- **تحديد المعدل**: الافتراضي العام (60 مرة/الدقيقة)

**جسم الطلب**:
```json
{
  "refresh_token": "eyJhbGciOiJIUzI1NiIs..."
}
```

| الحقل | النوع | مطلوب | الوصف |
|------|------|------|------|
| refresh_token | string | نعم | refresh_token الذي حصلت عليه عند تسجيل الدخول/التسجيل |

**مثال على الاستجابة**:
```json
{
  "code": 0,
  "message": "success",
  "data": {
    "access_token": "eyJhbGciOiJIUzI1NiIs...",
    "refresh_token": "eyJhbGciOiJIUzI1NiIs...",
    "expires_in": 7200
  }
}
```

يعيد التحديث الناجح access_token وrefresh_token جديدين معًا؛ الرمز القديم يبطل تلقائيًا. يحدّث التحديث أيضًا آخر وقت تسجيل دخول وعنوان IP للمستخدم.

**الأخطاء المحتملة**:
- 422: رمز التحديث مفقود
- 401: رمز التحديث غير صالح أو منتهي الصلاحية

### 3.8 مقاييس مراقبة Prometheus

```
GET /metrics
```

- **المصادقة**: غير مطلوبة
- **تحديد المعدل**: لا يوجد
- **تنسيق الاستجابة**: تنسيق نص Prometheus (`text/plain; version=0.0.4`)

نقطة نهاية عامة لمقاييس مراقبة Prometheus، تُجمع بواسطة Grafana/Prometheus.

**مثال على الاستجابة**:
```
# HELP openadmin_http_requests_total Total number of HTTP requests.
# TYPE openadmin_http_requests_total gauge
openadmin_http_requests_total 15234

# HELP openadmin_active_users Number of active users.
# TYPE openadmin_active_users gauge
openadmin_active_users 156

# HELP openadmin_db_connection_status Database connection status (1=ok, 0=fail).
# TYPE openadmin_db_connection_status gauge
openadmin_db_connection_status 1

# HELP openadmin_redis_connection_status Redis connection status (1=ok, 0=fail).
# TYPE openadmin_redis_connection_status gauge
openadmin_redis_connection_status 1

# HELP openadmin_memory_usage_bytes Memory usage in bytes.
# TYPE openadmin_memory_usage_bytes gauge
openadmin_memory_usage_bytes 18874368
```

| اسم المقياس | النوع | الوصف |
|------|------|------|
| `openadmin_http_requests_total` | gauge | إجمالي عدد طلبات HTTP التراكمية |
| `openadmin_active_users` | gauge | عدد المستخدمين النشطين حاليًا (سجلوا الدخول خلال 24 ساعة) |
| `openadmin_db_connection_status` | gauge | حالة اتصال قاعدة البيانات، 1=طبيعي، 0=غير طبيعي |
| `openadmin_redis_connection_status` | gauge | حالة اتصال Redis، 1=طبيعي، 0=غير طبيعي |
| `openadmin_memory_usage_bytes` | gauge | استخدام الذاكرة الحالي لعملية PHP (بالبايت) |

## 4. لوحة المعلومات

جميع نقاط نهاية الإدارة مثبتة ضمن مجموعة `/admin`، وتمر عبر ثلاثة وسائط: `AdminAuth` (مصادقة JWT)، و`AdminPermission` (التحقق من صلاحيات RBAC)، و`OperationLog` (تسجيل العمليات).

### 4.1 بيانات لوحة المعلومات

```
GET /admin/dashboard
```

- **المصادقة**: JWT + RBAC
- **التخزين المؤقت**: Redis لمدة 5 دقائق

**مثال على الاستجابة**:
```json
{
  "code": 0,
  "message": "success",
  "data": {
    "stats": [
      {
        "label": "用户总数",
        "value": "1280",
        "icon": "people",
        "color": "#1677FF",
        "trend": 12.5
      },
      {
        "label": "今日新增",
        "value": "23",
        "icon": "person_add",
        "color": "#52C41A"
      },
      {
        "label": "活跃用户",
        "value": "156",
        "icon": "bolt",
        "color": "#FA8C16"
      },
      {
        "label": "操作日志",
        "value": "432",
        "icon": "description",
        "color": "#722ED1"
      }
    ],
    "trends": {
      "dates": ["2026-04-22", "2026-04-23", "..."],
      "series": [
        { "name": "累计用户", "data": [1200, 1210, "..."], "color": "#1677FF" },
        { "name": "操作日志", "data": [45, 52, "..."], "color": "#52C41A" }
      ]
    },
    "distribution": {
      "user_status": [
        { "name": "启用", "value": 1250 },
        { "name": "禁用", "value": 30 }
      ]
    },
    "recent_logs": [
      {
        "id": "hashid...",
        "action": "用户登录",
        "method": "POST",
        "path": "/api/auth/login",
        "ip": "192.168.1.1",
        "user_name": "admin",
        "created_at": "2026-05-21 10:30:00"
      }
    ]
  }
}
```

| حقول stats | النوع | الوصف |
|------|------|------|
| label | string | اسم المقياس |
| value | string | قيمة المقياس (نوع سلسلة) |
| icon | string | اسم أيقونة Material |
| color | string | قيمة لون البطاقة |
| trend | float? | معدل النمو اليومي (نسبة مئوية)؛ حقل "إجمالي المستخدمين" فقط يحمل هذا الحقل |

| حقول trends | النوع | الوصف |
|------|------|------|
| dates | array{string} | تسلسل تواريخ آخر 30 يومًا |
| series | array{object} | بيانات خط الاتجاه، كل عنصر يتضمن name (الاسم) وdata (مصفوفة القيم) وcolor (اللون) |

## 5. إدارة المستخدمين

جميع `id` التي تُرجعها نقاط نهاية إدارة المستخدمين هي سلاسل مشفرة بـ hashid. حقل كلمة المرور مستثنى من الاستجابة. أرقام الهواتف والبريد الإلكتروني تُعرض مع إخفاء في نقاط نهاية القائمة، وتُعاد كنص عادي في نقاط نهاية التفاصيل (الحقول المشفرة في قاعدة البيانات تُفك تلقائيًا بواسطة trait Encryptable).

### 5.1 قائمة المستخدمين

```
GET /admin/user
```

- **المصادقة**: JWT + RBAC

**معاملات الاستعلام**:

| المعامل | النوع | مطلوب | القيمة الافتراضية | الوصف |
|------|------|------|------|------|
| page | int | لا | 1 | رقم الصفحة |
| limit | int | لا | 15 | عدد العناصر لكل صفحة |
| keyword | string | لا | | كلمة البحث، تطابق اسم المستخدم والاسم الحقيقي |
| status | int | لا | | تصفية الحالة، 0=معطَّل، 1=مفعَّل |

**مثال على الاستجابة**:
```json
{
  "code": 0,
  "message": "success",
  "data": {
    "list": [
      {
        "id": "a1b2c3d4",
        "username": "admin",
        "real_name": "管理员",
        "phone": "138****5678",
        "email": "a***@example.com",
        "status": 1,
        "last_login_at": "2026-05-21 10:30:00",
        "created_at": "2026-01-01 00:00:00"
      }
    ],
    "total": 100,
    "page": 1,
    "limit": 15
  }
}
```

| الحقل | النوع | الوصف |
|------|------|------|
| id | string | معرف المستخدم المشفر بـ hashid |
| username | string | اسم المستخدم |
| real_name | string | الاسم الحقيقي |
| phone | string | رقم هاتف مخفي (`138****5678`) |
| email | string | بريد إلكتروني مخفي (`a***@example.com`) |
| status | int | 1=مفعَّل، 0=معطَّل |
| last_login_at | string | آخر وقت تسجيل دخول (datetime) |
| created_at | string | وقت الإنشاء (datetime) |

### 5.2 إنشاء مستخدم

```
POST /admin/user
```

- **المصادقة**: JWT + RBAC

**جسم الطلب**:
```json
{
  "username": "newuser",
  "password": "123456",
  "real_name": "新用户",
  "phone": "13800138000",
  "email": "newuser@example.com",
  "status": 1
}
```

| الحقل | النوع | مطلوب | قاعدة التحقق | الوصف |
|------|------|------|---------|------|
| username | string | نعم | min:3, max:50 | اسم المستخدم (فريد) |
| password | string | نعم | min:6, max:32 | كلمة المرور (مخزنة بتشفير bcrypt) |
| real_name | string | نعم | max:50 | الاسم الحقيقي |
| phone | string | لا | | رقم الهاتف (مخزن مشفرًا بـ Encryptable) |
| email | string | لا | | البريد الإلكتروني (مخزن مشفرًا بـ Encryptable) |
| status | int | لا | in:0,1 | الحالة، الافتراضي 1 (مفعَّل) |

**مثال على الاستجابة**:
```json
{
  "code": 0,
  "message": "创建成功",
  "data": {
    "id": "e5f6g7h8",
    "username": "newuser",
    "real_name": "新用户",
    "phone": "13800138000",
    "email": "newuser@example.com",
    "status": 1,
    "created_at": "2026-05-21 12:00:00"
  }
}
```

**الأخطاء المحتملة**:
- 422: اسم المستخدم موجود بالفعل
- 422: فشل التحقق من المعاملات (حقل مطلوب مفقود)

### 5.3 تفاصيل المستخدم

```
GET /admin/user/{id}
```

- **المصادقة**: JWT + RBAC
- **معامل المسار**: `{id}` هو معرف المستخدم المشفر بـ hashid

**مثال على الاستجابة**:
```json
{
  "code": 0,
  "message": "success",
  "data": {
    "id": "a1b2c3d4",
    "username": "admin",
    "real_name": "管理员",
    "phone": "13800138000",
    "email": "admin@example.com",
    "status": 1,
    "last_login_at": "2026-05-21 10:30:00",
    "last_login_ip": "192.168.1.1",
    "created_at": "2026-01-01 00:00:00",
    "updated_at": "2026-05-20 08:00:00"
  }
}
```

في نقطة نهاية التفاصيل، يُرجع `phone` و`email` كنص عادي (مخزنان مشفرين في قاعدة البيانات، وcast من Encryptable يفك التشفير تلقائيًا)، دون إخفاء. `password` و`id_card` لا يظهران أبدًا في الاستجابة.

**الأخطاء المحتملة**:
- 404: المستخدم غير موجود

### 5.4 تحديث المستخدم

```
PUT /admin/user/{id}
```

- **المصادقة**: JWT + RBAC
- **معامل المسار**: `{id}` هو معرف المستخدم المشفر بـ hashid

**جسم الطلب**:
```json
{
  "real_name": "新姓名",
  "password": "",
  "phone": "13900139000",
  "email": "new@example.com",
  "status": 1
}
```

| الحقل | النوع | مطلوب | الوصف |
|------|------|------|------|
| real_name | string | لا | الاسم الحقيقي؛ عدم الإرسال يُبقي القيمة الأصلية |
| password | string | لا | كلمة مرور جديدة؛ سلسلة فارغة أو عدم الإرسال تعني عدم التعديل |
| phone | string | لا | رقم الهاتف |
| email | string | لا | البريد الإلكتروني |
| status | int | لا | 0=معطَّل، 1=مفعَّل |

**مثال على الاستجابة**:
```json
{
  "code": 0,
  "message": "更新成功",
  "data": {
    "id": "a1b2c3d4",
    "username": "admin",
    "real_name": "新姓名",
    "phone": "13900139000",
    "email": "new@example.com",
    "status": 1,
    "updated_at": "2026-05-21 12:30:00"
  }
}
```

**الأخطاء المحتملة**:
- 404: المستخدم غير موجود

### 5.5 حذف المستخدم

```
DELETE /admin/user/{id}
```

- **المصادقة**: JWT + RBAC
- **معامل المسار**: `{id}` هو معرف المستخدم المشفر بـ hashid
- **عملية حساسة**: تتطلب تأكيد كلمة المرور مرة ثانية

**جسم الطلب**:
```json
{
  "password": "admin_password"
}
```

| الحقل | النوع | مطلوب | الوصف |
|------|------|------|------|
| password | string | نعم | كلمة مرور المستخدم المسجل دخوله حاليًا (تأكيد ثانٍ) |

**مثال على الاستجابة**:
```json
{
  "code": 0,
  "message": "删除成功",
  "data": []
}
```

ينفذ الحذف الناعم (Eloquent SoftDeletes)، إذ تُعلَّم البيانات بـ deleted_at دون حذف فعلي.

**الأخطاء المحتملة**:
- 404: المستخدم غير موجود
- 422: تتطلب العملية الحساسة إدخال كلمة المرور للتأكيد (password فارغ)
- 422: فشل التحقق من كلمة المرور (كلمة المرور غير مطابقة)

### 5.6 حذف مستخدمين بالجملة

```
POST /admin/user/batch/destroy
```

- **المصادقة**: JWT + RBAC
- **عملية حساسة**: تتطلب تأكيد كلمة المرور مرة ثانية

**جسم الطلب**:
```json
{
  "ids": ["a1b2c3d4", "e5f6g7h8"],
  "password": "admin_password"
}
```

| الحقل | النوع | مطلوب | الوصف |
|------|------|------|------|
| ids | array{string} | نعم | مصفوفة معرفات المستخدمين المشفرة بـ hashid |
| password | string | نعم | كلمة مرور المستخدم المسجل دخوله حاليًا (تأكيد ثانٍ) |

**مثال على الاستجابة**:
```json
{
  "code": 0,
  "message": "删除成功",
  "data": {
    "count": 2
  }
}
```

ينفذ الحذف الناعم؛ `data.count` هو العدد الفعلي المحذوف.

**الأخطاء المحتملة**:
- 422: يرجى اختيار المستخدمين المراد حذفهم (ids فارغ)
- 422: معرف غير صالح (فشل فك تشفير hashid)
- 422: فشل التحقق من كلمة المرور

### 5.7 تفعيل/تعطيل مستخدمين بالجملة

```
POST /admin/user/batch/status
```

- **المصادقة**: JWT + RBAC

**جسم الطلب**:
```json
{
  "ids": ["a1b2c3d4", "e5f6g7h8"],
  "status": 1
}
```

| الحقل | النوع | مطلوب | الوصف |
|------|------|------|------|
| ids | array{string} | نعم | مصفوفة معرفات المستخدمين المشفرة بـ hashid |
| status | int | نعم | 0=معطَّل، 1=مفعَّل |

**مثال على الاستجابة**:
```json
{
  "code": 0,
  "message": "批量启用成功",
  "data": {
    "count": 2
  }
}
```

تتغير message ديناميكيًا حسب قيمة status إلى `"批量启用成功"` أو `"批量禁用成功"`.

**الأخطاء المحتملة**:
- 422: يرجى اختيار المستخدمين (ids فارغ)
- 422: قيمة الحالة غير صالحة (status ليس 0 أو 1)

## 6. إدارة الأدوار

### 6.1 قائمة الأدوار

```
GET /admin/role
```

- **المصادقة**: JWT + RBAC

**معاملات الاستعلام**:

| المعامل | النوع | مطلوب | القيمة الافتراضية | الوصف |
|------|------|------|------|------|
| page | int | لا | 1 | رقم الصفحة |
| limit | int | لا | 15 | عدد العناصر لكل صفحة |

**مثال على الاستجابة**:
```json
{
  "code": 0,
  "message": "success",
  "data": {
    "list": [
      {
        "id": "r1r2r3r4",
        "name": "超级管理员",
        "slug": "super_admin",
        "description": "拥有所有权限",
        "status": 1,
        "users_count": 3,
        "created_at": "2026-01-01 00:00:00"
      }
    ],
    "total": 5,
    "page": 1,
    "limit": 15
  }
}
```

| الحقل | النوع | الوصف |
|------|------|------|
| id | string | معرف الدور المشفر بـ hashid |
| name | string | اسم الدور |
| slug | string | معرّف الدور (فريد، يستخدم للحكم على الصلاحيات) |
| description | string | وصف الدور |
| status | int | 1=مفعَّل، 0=معطَّل |
| users_count | int | عدد المستخدمين الحاصلين على هذا الدور |

### 6.2 إنشاء دور

```
POST /admin/role
```

- **المصادقة**: JWT + RBAC

**جسم الطلب**:
```json
{
  "name": "编辑员",
  "slug": "editor",
  "description": "内容编辑角色",
  "status": 1,
  "permission_ids": [1, 5, 12, 15]
}
```

| الحقل | النوع | مطلوب | قاعدة التحقق | الوصف |
|------|------|------|---------|------|
| name | string | نعم | max:50 | اسم الدور |
| slug | string | نعم | max:50 | معرّف الدور |
| description | string | لا | | وصف الدور، الافتراضي سلسلة فارغة |
| status | int | لا | | الحالة، الافتراضي 1 |
| permission_ids | array{int} | لا | | مصفوفة معرفات الصلاحيات (معرفات INT أصلية، وليست hashid) |

**مثال على الاستجابة**:
```json
{
  "code": 0,
  "message": "创建成功",
  "data": {
    "id": "r5r6r7r8",
    "name": "编辑员",
    "slug": "editor",
    "description": "内容编辑角色",
    "status": 1
  }
}
```

### 6.3 تحديث الدور

```
PUT /admin/role/{id}
```

- **المصادقة**: JWT + RBAC

**جسم الطلب**:
```json
{
  "name": "内容编辑",
  "description": "更新后的描述",
  "status": 1,
  "permission_ids": [1, 5, 12, 20]
}
```

| الحقل | النوع | مطلوب | الوصف |
|------|------|------|------|
| name | string | لا | اسم الدور |
| description | string | لا | الوصف |
| status | int | لا | 0=معطَّل، 1=مفعَّل |
| permission_ids | array{int} | لا | مصفوفة معرفات الصلاحيات؛ إذا أُرسلت تُزامن (تستبدل) صلاحيات الدور |

**مثال على الاستجابة**:
```json
{
  "code": 0,
  "message": "更新成功",
  "data": {
    "id": "r5r6r7r8",
    "name": "内容编辑",
    "slug": "editor",
    "description": "更新后的描述",
    "status": 1
  }
}
```

### 6.4 حذف الدور

```
DELETE /admin/role/{id}
```

- **المصادقة**: JWT + RBAC
- **عملية حساسة**: تتطلب تأكيد كلمة المرور مرة ثانية

**جسم الطلب**:
```json
{
  "password": "admin_password"
}
```

**مثال على الاستجابة**:
```json
{
  "code": 0,
  "message": "删除成功",
  "data": []
}
```

عند الحذف يُفصل الدور تلقائيًا عن جميع الصلاحيات والمستخدمين، ثم يُحذف سجل الدور فعليًا.

## 7. إدارة الصلاحيات

تستخدم الصلاحيات بنية شجرية (parent_id ذاتي الارتباط) وتنقسم إلى ثلاثة أنواع. تعيد نقطة نهاية القائمة شجرة الصلاحيات الكاملة.

### 7.1 شجرة الصلاحيات

```
GET /admin/permission
```

- **المصادقة**: JWT + RBAC

**مثال على الاستجابة**:
```json
{
  "code": 0,
  "message": "success",
  "data": [
    {
      "id": "p1p2p3p4",
      "parent_id": "0",
      "name": "用户管理",
      "slug": "/admin/user",
      "type": 1,
      "icon": "people",
      "path": "/user",
      "sort": 1,
      "created_at": "2026-01-01 00:00:00",
      "children": [
        {
          "id": "p5p6p7p8",
          "parent_id": "p1p2p3p4",
          "name": "用户列表",
          "slug": "/admin/user/index",
          "type": 2,
          "icon": "",
          "path": "/user/index",
          "sort": 1
        }
      ]
    }
  ]
}
```

| الحقل | النوع | الوصف |
|------|------|------|
| id | string | مشفر بـ hashid |
| parent_id | string | hashid الصلاحية الأم، "0" تعني العقدة الجذرية |
| name | string | اسم الصلاحية |
| slug | string | معرّف الصلاحية (معرّف المسار/الزر) |
| type | int | 1=قائمة، 2=زر، 3=واجهة |
| icon | string | أيقونة القائمة (اسم أيقونة Material) |
| path | string | مسار التوجيه في الواجهة الأمامية |
| sort | int | قيمة الترتيب (تصاعدي) |
| children | array? | قائمة الصلاحيات الفرعية (تكراري)؛ لا يتضمن هذا الحقل عند عدم وجود عقد فرعية |

### 7.2 إنشاء صلاحية

```
POST /admin/permission
```

- **المصادقة**: JWT + RBAC

**جسم الطلب**:
```json
{
  "parent_id": 0,
  "name": "系统设置",
  "slug": "/admin/config",
  "type": 1,
  "icon": "settings",
  "path": "/config",
  "sort": 99
}
```

| الحقل | النوع | مطلوب | قاعدة التحقق | الوصف |
|------|------|------|---------|------|
| parent_id | int | لا | | معرف الصلاحية الأم (نوع INT أصلي)، الافتراضي 0 |
| name | string | نعم | max:50 | اسم الصلاحية |
| slug | string | نعم | max:100 | معرّف الصلاحية |
| type | int | نعم | in:1,2,3 | 1=قائمة، 2=زر، 3=واجهة |
| icon | string | لا | | أيقونة القائمة، الافتراضي فارغ |
| path | string | لا | | مسار التوجيه في الواجهة الأمامية، الافتراضي فارغ |
| sort | int | لا | | قيمة الترتيب، الافتراضي 0 |

**مثال على الاستجابة**:
```json
{
  "code": 0,
  "message": "创建成功",
  "data": {
    "id": "p9p0a1b2",
    "parent_id": "0",
    "name": "系统设置",
    "slug": "/admin/config",
    "type": 1,
    "icon": "settings",
    "path": "/config",
    "sort": 99
  }
}
```

### 7.3 تحديث صلاحية

```
PUT /admin/permission/{id}
```

- **المصادقة**: JWT + RBAC

**جسم الطلب**:
```json
{
  "name": "系统配置",
  "icon": "tune",
  "path": "/system/config",
  "sort": 50
}
```

| الحقل | النوع | مطلوب | الوصف |
|------|------|------|------|
| name | string | لا | اسم الصلاحية |
| icon | string | لا | الأيقونة |
| path | string | لا | مسار التوجيه |
| sort | int | لا | قيمة الترتيب |

### 7.4 حذف صلاحية

```
DELETE /admin/permission/{id}
```

- **المصادقة**: JWT + RBAC
- **عملية حساسة**: تتطلب تأكيد كلمة المرور مرة ثانية

**جسم الطلب**:
```json
{
  "password": "admin_password"
}
```

**مثال على الاستجابة**:
```json
{
  "code": 0,
  "message": "删除成功",
  "data": []
}
```

عند الحذف تُحذف جميع الصلاحيات الفرعية بشكل متسلسل (السجلات التي `parent_id` = معرف الصلاحية الحالية)، مع فصل الارتباط بجميع الأدوار.

## 8. إعدادات النظام

إعدادات النظام فريدة من نوعها عبر تركيبة `group` + `key`.

### 8.1 قائمة الإعدادات

```
GET /admin/config
```

- **المصادقة**: JWT + RBAC

**معاملات الاستعلام**:

| المعامل | النوع | مطلوب | القيمة الافتراضية | الوصف |
|------|------|------|------|------|
| page | int | لا | 1 | رقم الصفحة |
| limit | int | لا | 15 | عدد العناصر لكل صفحة |
| group | string | لا | | تصفية حسب مجموعة الإعدادات |

**مثال على الاستجابة**:
```json
{
  "code": 0,
  "message": "success",
  "data": {
    "list": [
      {
        "id": "c1c2c3c4",
        "group": "system",
        "key": "site_name",
        "value": "开放管理后台",
        "type": "string",
        "description": "站点名称",
        "created_at": "2026-01-01 00:00:00"
      }
    ],
    "total": 20,
    "page": 1,
    "limit": 15
  }
}
```

| الحقل | النوع | الوصف |
|------|------|------|
| id | string | hashid |
| group | string | مجموعة الإعدادات (مثل `system` و`email` و`storage`) |
| key | string | مفتاح الإعداد |
| value | string | قيمة الإعداد |
| type | string | تلميح نوع القيمة (`string` و`integer` و`boolean` و`json` وغيرها) |
| description | string | وصف الإعداد |

### 8.2 إنشاء إعداد

```
POST /admin/config
```

- **المصادقة**: JWT + RBAC

**جسم الطلب**:
```json
{
  "group": "email",
  "key": "smtp_host",
  "value": "smtp.example.com",
  "type": "string",
  "description": "SMTP 服务器地址"
}
```

| الحقل | النوع | مطلوب | قاعدة التحقق | الوصف |
|------|------|------|---------|------|
| group | string | نعم | max:100 | مجموعة الإعدادات |
| key | string | نعم | max:100 | مفتاح الإعداد (فريد داخل المجموعة نفسها) |
| value | string | نعم | | قيمة الإعداد |
| type | string | لا | | نوع القيمة، الافتراضي `string` |
| description | string | لا | | وصف الإعداد، الافتراضي فارغ |

**مثال على الاستجابة**:
```json
{
  "code": 0,
  "message": "创建成功",
  "data": {
    "id": "c5c6c7c8",
    "group": "email",
    "key": "smtp_host",
    "value": "smtp.example.com",
    "type": "string",
    "description": "SMTP 服务器地址"
  }
}
```

**الأخطاء المحتملة**:
- 422: عنصر الإعداد موجود بالفعل (نفس group + key)

### 8.3 تحديث إعداد

```
PUT /admin/config/{id}
```

- **المصادقة**: JWT + RBAC

**جسم الطلب**:
```json
{
  "value": "smtp.newhost.com",
  "type": "string",
  "description": "更新后的 SMTP 地址"
}
```

| الحقل | النوع | مطلوب | الوصف |
|------|------|------|------|
| value | string | لا | تحديث قيمة الإعداد |
| type | string | لا | تحديث نوع القيمة |
| description | string | لا | تحديث نص الوصف |

### 8.4 حذف إعداد

```
DELETE /admin/config/{id}
```

- **المصادقة**: JWT + RBAC
- **عملية حساسة**: تتطلب تأكيد كلمة المرور مرة ثانية

**جسم الطلب**:
```json
{
  "password": "admin_password"
}
```

يحذف سجل الإعداد فعليًا.

## 9. سجلات العمليات

سجلات العمليات واجهة للقراءة فقط؛ يكتب وسيط `OperationLog` تلقائيًا في كل طلب POST/PUT/DELETE، وتشمل حقول التخزين `user_id` و`action` و`method` و`path` و`ip` و`source` و`input`.

### 9.1 قائمة سجلات العمليات

```
GET /admin/log
```

- **المصادقة**: JWT + RBAC

**معاملات الاستعلام**:

| المعامل | النوع | مطلوب | القيمة الافتراضية | الوصف |
|------|------|------|------|------|
| page | int | لا | 1 | رقم الصفحة |
| limit | int | لا | 15 | عدد العناصر لكل صفحة |
| user_id | int | لا | | تصفية دقيقة حسب معرف المستخدم (نوع INT أصلي) |
| action | string | لا | | تصفية دقيقة حسب إجراء العملية |
| path | string | لا | | تصفية تقريبية حسب مسار الطلب |
| start_date | string | لا | | تاريخ البدء (تنسيق Y-m-d) |
| end_date | string | لا | | تاريخ الانتهاء (تنسيق Y-m-d) |

**مثال على الاستجابة**:
```json
{
  "code": 0,
  "message": "success",
  "data": {
    "list": [
      {
        "id": "l1l2l3l4",
        "user_name": "admin",
        "action": "用户登录",
        "method": "POST",
        "path": "/api/auth/login",
        "ip": "192.168.1.1",
        "source": "web",
        "input": "{\"username\":\"admin\"}",
        "created_at": "2026-05-21 10:30:00"
      }
    ],
    "total": 500,
    "page": 1,
    "limit": 15
  }
}
```

| الحقل | النوع | الوصف |
|------|------|------|
| id | string | hashid |
| user_name | string | اسم مستخدم العملية (يُحصل عليه عبر علاقة user؛ العمليات دون تسجيل دخول تعرض "系统") |
| action | string | وصف إجراء العملية |
| method | string | طريقة HTTP (POST/PUT/DELETE) |
| path | string | مسار الطلب |
| ip | string | عنوان IP للعميل |
| source | string | مصدر الطلب |
| input | string | سلسلة JSON لمعاملات الطلب (لا تشمل الملفات) |
| created_at | string | وقت العملية (datetime) |

## 10. المركز الشخصي

تتطلب نقاط نهاية المركز الشخصي مصادقة JWT فقط (لا تتطلب التحقق من صلاحيات RBAC — يجب أن يضيفها وسيط `AdminPermission` إلى القائمة البيضاء).

### 10.1 تحديث المعلومات الشخصية

```
PUT /admin/profile
```

- **المصادقة**: JWT

**جسم الطلب**:
```json
{
  "real_name": "新姓名",
  "phone": "13800138000",
  "email": "me@example.com"
}
```

| الحقل | النوع | مطلوب | الوصف |
|------|------|------|------|
| real_name | string | لا | الاسم الحقيقي |
| phone | string | لا | رقم الهاتف (مخزن مشفرًا بـ Encryptable) |
| email | string | لا | البريد الإلكتروني (مخزن مشفرًا بـ Encryptable) |

**مثال على الاستجابة**:
```json
{
  "code": 0,
  "message": "更新成功",
  "data": {
    "id": "a1b2c3d4",
    "username": "admin",
    "real_name": "新姓名",
    "phone": "13800138000",
    "email": "me@example.com",
    "status": 1,
    "last_login_at": "2026-05-21 10:30:00"
  }
}
```

في الاستجابة يُرجع `phone` و`email` كنص عادي، بينما يُستبعد `password` و`id_card`.

### 10.2 تغيير كلمة المرور

```
PUT /admin/profile/password
```

- **المصادقة**: JWT

**جسم الطلب**:
```json
{
  "old_password": "current_pass",
  "new_password": "new_pass_123"
}
```

| الحقل | النوع | مطلوب | قاعدة التحقق | الوصف |
|------|------|------|---------|------|
| old_password | string | نعم | | كلمة المرور الحالية |
| new_password | string | نعم | min:6, max:32 | كلمة المرور الجديدة |

**مثال على الاستجابة**:
```json
{
  "code": 0,
  "message": "密码修改成功",
  "data": []
}
```

**الأخطاء المحتملة**:
- 422: يرجى إدخال كلمة المرور القديمة والجديدة
- 422: كلمة المرور القديمة خاطئة
- 422: يجب أن يتراوح طول كلمة المرور الجديدة بين 6-32 حرفًا

### 10.3 تسجيل الخروج

```
POST /admin/profile/logout
```

- **المصادقة**: JWT

**جسم الطلب**: لا يوجد (لا requestBody، يُقرأ الرمز من رأس Authorization)

**مثال على الاستجابة**:
```json
{
  "code": 0,
  "message": "已登出",
  "data": []
}
```

منطق تسجيل الخروج: فك تشفير JWT للحصول على مدة الصلاحية المتبقية (exp - now)، وكتابة تجزئة md5 لذلك الرمز في القائمة السوداء `jwt_blacklist:{md5}` في Redis، TTL = مدة الصلاحية المتبقية. الرموز الموجودة في القائمة السوداء تُعترض في وسيط `AdminAuth` وتُرجع 401.

عند غياب الرمز تُرجع 401. الرمز منتهي الصلاحية/غير الصالح (يثير فك التشفير استثناءً) يُعتبر مع ذلك تسجيل خروج ناجحًا.

## 11. الاستيراد والتصدير

### 11.1 تصدير Excel

```
POST /admin/export/excel
```

- **المصادقة**: JWT + RBAC
- **نوع الاستجابة**: تنزيل ملف (`application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`)

**جسم الطلب**:
```json
{
  "table": "admin_user",
  "columns": ["username", "real_name", "phone", "status", "created_at"],
  "conditions": {
    "status": 1
  },
  "title": "用户列表导出"
}
```

| الحقل | النوع | مطلوب | القيمة الافتراضية | الوصف |
|------|------|------|------|------|
| table | string | لا | `admin_user` | اسم جدول التصدير. المدعوم: `admin_user` و`operation_log` و`admin_role` و`system_config` |
| columns | array{string} | لا | | مصفوفة أسماء حقول أعمدة التصدير؛ فارغة تعني تصدير جميع أعمدة الجدول |
| conditions | object | لا | `{}` | شروط التصفية، أزواج key-value، تُستخدم في WHERE عندما لا تكون القيمة فارغة |
| title | string | لا | `数据导出` | عنوان Excel (يُعرض كاسم الورقة Sheet) |

**الجداول والأعمدة المدعومة**:

| table | الأعمدة المتاحة |
|-------|-------|
| `admin_user` | id, username, real_name, phone, email, status, last_login_at, last_login_ip, created_at |
| `operation_log` | id, user_id, action, method, path, ip, created_at |
| `admin_role` | id, name, slug, description, status, created_at |
| `system_config` | id, group, key, value, type, description, created_at |

الحقول الحساسة `phone` و`email` و`id_card` تُخفى تلقائيًا عند التصدير. حد البيانات 10000 صف. يُثبَّت الصف الأول في Excel وتُفعَّل التصفية التلقائية.

### 11.2 تصدير PDF

```
POST /admin/export/pdf
```

- **المصادقة**: JWT + RBAC
- **نوع الاستجابة**: تنزيل ملف (`application/pdf`، A4 عرضي)

**جسم الطلب**:
```json
{
  "type": "dashboard",
  "title": "管理仪表盘报告",
  "data": {
    "stats": [
      { "label": "用户总数", "value": "1280" }
    ]
  }
}
```

أو وضع الجدول:
```json
{
  "type": "table",
  "title": "用户列表",
  "data": {
    "columns": ["用户名", "真实姓名", "状态"],
    "rows": [
      ["admin", "管理员", "启用"],
      ["editor", "编辑员", "启用"]
    ]
  }
}
```

| الحقل | النوع | مطلوب | القيمة الافتراضية | الوصف |
|------|------|------|------|------|
| type | string | لا | `table` | نوع التصدير: `table` / `dashboard` |
| title | string | لا | `数据导出` | عنوان PDF |
| data | object | لا | `{}` | بيانات التصدير |

عند `type=dashboard` يجب أن يتضمن `data` مصفوفة `stats` (تُعرض كبطاقات)؛ وعند `type=table` يجب أن يتضمن `data` مصفوفتي `columns` و`rows`.

يتضمن قالب PDF معلومات حقوق النشر وطابع زمني للتصدير.

### 11.3 استيراد المستخدمين (Excel)

```
POST /admin/import/users
```

- **المصادقة**: JWT + RBAC
- **نوع الطلب**: `multipart/form-data` (رفع ملف)

**حقول النموذج**:

| الحقل | النوع | مطلوب | الوصف |
|------|------|------|------|
| file | file | نعم | تنسيق `.xlsx` أو `.xls` |

**متطلبات أعمدة Excel**:

| اسم العمود | مطلوب | الوصف |
|------|------|------|
| username | نعم | اسم المستخدم (فريد) |
| password | نعم | كلمة المرور (مخزنة بتجزئة bcrypt) |
| real_name | نعم | الاسم الحقيقي |
| phone | لا | رقم الهاتف |
| email | لا | البريد الإلكتروني |
| status | لا | الحالة، الافتراضي 1 |

الصف الأول هو عنوان العمود (غير حساس لحالة الأحرف)، والبيانات تبدأ من الصف الثاني.

**مثال على الاستجابة**:
```json
{
  "code": 0,
  "message": "导入完成",
  "data": {
    "total": 10,
    "success": 8,
    "failed": 2,
    "errors": [
      { "row": 3, "reason": "用户名为空" },
      { "row": 7, "reason": "用户名 zhangsan 已存在" }
    ]
  }
}
```

| الحقل | النوع | الوصف |
|------|------|------|
| total | int | إجمالي الصفوف (بدون صف العنوان) |
| success | int | عدد الاستيراد الناجح |
| failed | int | عدد الفاشل |
| errors | array | تفاصيل الفشل، كل عنصر يتضمن row (رقم صف Excel) وreason (سبب الفشل) |

## 12. رفع الملفات

```
POST /admin/upload
```

- **المصادقة**: JWT + RBAC
- **نوع الطلب**: `multipart/form-data`

**حقول النموذج**:

| الحقل | النوع | مطلوب | الوصف |
|------|------|------|------|
| file | file | نعم | الملف المراد رفعه |

**أنواع الملفات المسموحة**: `jpg` و`jpeg` و`png` و`gif` و`pdf` و`xlsx` و`docx`
**الحد الأقصى لحجم الملف**: 10MB

**مثال على الاستجابة**:
```json
{
  "code": 0,
  "message": "上传成功",
  "data": {
    "url": "/upload/2026-05-21/abc123def456.png"
  }
}
```

تُخزَّن الملفات في دليل حسب التاريخ `public/upload/{Y-m-d}/`، واسم الملف هو `md5(uniqid) + الامتداد الأصلي`. `url` هو مسار نسبي بالنسبة لجذر الموقع.

**الأخطاء المحتملة**:
- 422: يرجى اختيار ملف (لم يتم الرفع)
- 422: نوع ملف غير مدعوم
- 422: لا يجوز أن يتجاوز حجم الملف 10MB
- 500: فشل رفع الملف (ملف غير صالح)

## 13. رؤوس الاستجابة

تتضمن جميع نقاط النهاية (المحقونة في طبقة الوسائط العامة) رؤوس الاستجابة التالية:

| الرأس | الوصف |
|----|------|
| `X-RateLimit-Limit` | الحد الأعلى لتحديد المعدل (عدد المرات) |
| `X-RateLimit-Remaining` | عدد الطلبات المتبقية |
| `X-RateLimit-Reset` | الطابع الزمني لإعادة تعيين نافذة تحديد المعدل |
| `Retry-After` | يُرجع فقط عند تفعيل تحديد المعدل، ثوانٍ الانتظار المقترحة |
| `X-Content-Type-Options` | `nosniff` (افتراضي webman، يمنع استكشاف MIME) |
| `X-Frame-Options` | `DENY` (يوفره وسيط CORS/الإعداد الأساسي في webman) |

تفاصيل تحديد المعدل:
- الحد العام الافتراضي: 60 مرة/الدقيقة / IP + المسار
- نقطة نهاية تسجيل الدخول `/api/auth/login`: 10 مرات/الدقيقة
- نقطة نهاية التسجيل `/api/auth/register`: 5 مرات/الدقيقة
- يستخدم خوارزمية النافذة المنزلقة الذرية في Redis (Lua ZSET)، لتجنب سباق TOCTOU
- عند تعذر Redis يُفتح الفشل (يُسمح بالمرور)، دون حجب الطلبات

## 14. عملية المصادقة

التسلسل الكامل للمصادقة:

```
1. يطلب العميل POST /api/captcha/generate
   (رأس الطلب: API-Version: v1)
    ↓
   يُرجع الخادم: key + type(click|slider|rotate) + صورة base64 + extra(بيانات مرتبطة بالنوع)
   
2. يُكمل المستخدم عملية كلمة التحقق (نقر/سحب/تدوير)، ويجمع العميل الإجابة
   
3. يطلب العميل POST /api/captcha/verify
   (رأس الطلب: API-Version: v1, Content-Type: application/json)
   جسم الطلب: { key, type, clicks }
   - type=click:  clicks = [{x, y}, ...]        // مصفوفة الإحداثيات
   - type=slider: clicks = 120                   // إزاحة X
   - type=rotate: clicks = 315                   // زاوية التدوير
    ↓
   الخادم:
   أ. يقرأ بيانات captcha:key من التخزين (TTL 300 ثانية)
   ب. يتحقق من الإجابة حسب type (click: المسافة الإقليدية ≤18px / slider: ±4px / rotate: ±5°)
   ج. نجاح التحقق → يكتب `captcha_verified:{key}` = 1 في Redis (TTL 300 ثانية)
   د. فشل التحقق → يُرجع 422، العد +1، بعد 3 مرات يُلغى المفتاح
    ↓
   يُرجع الخادم: { valid: true/false }

4. يطلب العميل POST /api/auth/login
   (رأس الطلب: API-Version: v1, Content-Type: application/json)
   جسم الطلب: { username, password(مشفرة), captcha_key }
    ↓
   الخادم:
   أ. التحقق من المعاملات → 422
   ب. فحص وجود captcha_verified:{key} → 422
   ج. حذف captcha_verified:{key} (استخدام لمرة واحدة)
   د. فك تشفير كلمة المرور: EncryptionService::decrypt(password) → نص عادي
   هـ. التحقق من بيانات اعتماد المستخدم (password_verify) → 401
   و. فحص حالة الحساب → 403/429
   ز. إصدار JWT (access + refresh) → 200
   ح. تحديث last_login_at / last_login_ip
    ↓
   يحفظ العميل: access_token وrefresh_token وexpires_in

5. تحمل الطلبات اللاحقة JWT
   رأس الطلب: Authorization: Bearer <access_token>
    ↓
وسيط AdminAuth:
   أ. استخراج رمز Bearer
   ب. فحص القائمة السوداء (Redis jwt_blacklist:{md5}) → 401
   ج. فك تشفير JWT والتحقق من انتهاء الصلاحية → 401
   د. تعيين $request->adminId = حقل sub
    ↓
وسيط AdminPermission:
   أ. تحليل معرّف الصلاحية لمسار المورد
   ب. الاستعلام عن أدوار المستخدم → صلاحيات الأدوار، ومطابقتها
   ج. لا صلاحية → 403
    ↓
يعالج Controller الطلب
    ↓
Response + رؤوس X-RateLimit-*

6. التحديث قبل انتهاء صلاحية رمز الوصول
   يطلب العميل POST /api/auth/refresh
   جسم الطلب: { refresh_token: "..." }
    ↓
   يفك الخادم تشفير refresh_token → يصدر access + refresh جديدين
    ↓
   يحدّث العميل الرموز المحلية

7. تسجيل الخروج
   يطلب العميل POST /admin/profile/logout
   رأس الطلب: Authorization: Bearer <access_token>
    ↓
   الخادم:
   أ. فك تشفير JWT للحصول على TTL المتبقي
   ب. الكتابة في القائمة السوداء في Redis: jwt_blacklist:{md5(token)} = 1, TTL = مدة الصلاحية المتبقية
   ج. إرجاع النجاح
```

### بنية JWT

- **access_token**: `{ sub: <user_id>, username: "<name>" }`، TTL الافتراضي 7200 ثانية (يتحكم به إعداد JWT `default_expire`)
- **refresh_token**: `{ sub: <user_id>, token_type: "refresh" }`، TTL الافتراضي 1209600 ثانية (يتحكم به إعداد JWT `refresh_expire`، أي 14 يومًا)

### إدارة الأمان

- تُخزَّن كلمات المرور كتجزئة `PASSWORD_BCRYPT`
- تستخدم طبقة نقل كلمات المرور تشفير AES-256-CBC-HMAC (العميل يشفر ← الخادم يفك)، مع توافق التراجع إلى النص العادي
- الحقول الحساسة (phone وemail وid_card) تُشفَّر وتُفك بشفافية في طبقة قاعدة البيانات عبر `erikwang2013/encryptable`
- تُشفر معرفات طبقة API عبر `erikwang2013/hashids` أثناء النقل، لتجنب كشف تسلسل معرفات snowflake الأصلية
- يفحص SecurityFilter عالميًا XSS وحقن SQL وتجاوز المسار وحقن الأوامر؛ نفس IP 5 مرات/60 ثانية يدخل القائمة السوداء المؤقتة 15 دقيقة
- تتطلب العمليات الحساسة (حذف المستخدمين والأدوار والصلاحيات والإعدادات) تأكيد كلمة مرور المستخدم المسجل دخوله حاليًا مرة ثانية
- حد الجلسات المتزامنة: 3 رموز صالحة كحد أقصى لكل مستخدم؛ عند تسجيل دخول الجهاز الرابع يُجبر الرمز الأقدم على دخول القائمة السوداء
- قفل الحساب: فشل تسجيل الدخول 5 مرات متتالية يُطلق قفل الحساب 15 دقيقة، ويُرجع 429 أثناء القفل

## 15. النشر والتشغيل

### Docker Compose

يوفر الدليل الجذري للمشروع `docker-compose.yml`، الذي ينظم 5 خدمات (Nginx وتطبيق webman وMySQL وRedis وElasticsearch). يُبنى PHP عبر `Dockerfile` (استنادًا إلى `php:8.3-cli` مع تفعيل OPcache).

```bash
cp .env.docker .env
docker-compose up -d
```

### CI/CD

يعرّف `.github/workflows/ci.yml` خط أنابيب التكامل المستمر في GitHub Actions:
- فحص الصياغة `php -l`
- اختبارات الوحدة PHPUnit
- التحليل الساكن `flutter analyze`

### النسخ الاحتياطي لقاعدة البيانات

يوفر دليل `database/backup/` سكربتات النسخ الاحتياطي والاستعادة:
- `backup.sh` — نسخ احتياطي بضغط mysqldump + gzip، ينظف تلقائيًا ملفات النسخ الاحتياطي الأقدم من 30 يومًا
- `restore.sh` — استعادة تفاعلية، تعرض النسخ الاحتياطية الموجودة لاختيار المستخدم

### إعداد أمان Nginx

في نشر بيئة الإنتاج، راجع `docs/nginx-security.conf` لتقوية إعدادات أمان الوكيل العكسي.
