# API রেফারেন্স ডকুমেন্টেশন
**语言 / Languages:** [中文](API.md) · [English](API.en.md) · [한국어](API.ko.md) · [Русский](API.ru.md) · [Deutsch](API.de.md) · [Français](API.fr.md) · [Español](API.es.md) · [Português](API.pt.md) · [हिन्दी](API.hi.md) · [العربية](API.ar.md) · [বাংলা](API.bn.md) · [Bahasa Indonesia](API.id.md) · [日本語](API.ja.md)

> Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz

## 1. ভূমিকা

open-admin webman v2-এর উপর ভিত্তি করে নির্মিত এবং RESTful JSON API প্রদান করে। সকল অ্যাডমিন এন্ডপয়েন্টে JWT প্রমাণীকরণ এবং RBAC অনুমতি যাচাই প্রয়োজন; পাবলিক এন্ডপয়েন্টগুলো API সংস্করণ হেডারের মাধ্যমে সংস্করণভুক্ত কন্ট্রোলারে রাউট হয়।

- **বেস URL**: `http://localhost:8787`
- **API সংস্করণ**: অনুরোধ হেডার `API-Version: v1` দ্বারা নিয়ন্ত্রিত (অনুপস্থিত থাকলে ডিফল্ট v1)
- **ভাষা**: `Accept-Language` হেডার বা `?lang=zh_CN|en` প্যারামিটার দিয়ে পরিবর্তন (ডিফল্ট zh_CN), Locale মিডলওয়্যার দ্বারা স্বয়ংক্রিয়ভাবে শনাক্ত

> **এন্ডপয়েন্ট সারাংশ**: প্রমাণীকরণ(5) | ড্যাশবোর্ড(1) | ব্যবহারকারী(7) | ভূমিকা(4) | অনুমতি(4) | কনফিগারেশন(4) | লগ(1) | প্রোফাইল সেন্টার(3) | আমদানি/রপ্তানি(3) | আপলোড(1) | অপারেশন(4: health/metrics/docs/security.txt) | মোট 37টি এন্ডপয়েন্ট
- **প্রমাণীকরণ**: `Authorization: Bearer <token>` (JWT)
- **প্রতিক্রিয়া ফরম্যাট**: `{ "code": 0, "message": "success", "data": {...} }`
- **ডকুমেন্টেশন এন্ডপয়েন্ট**: `GET /api/docs` OpenAPI 3.0 JSON স্পেসিফিকেশন ফেরত দেয়

### অনুরোধের প্রয়োজনীয়তা

- শুধুমাত্র `GET` / `POST` / `PUT` / `DELETE` / `OPTIONS` / `HEAD` পদ্ধতি অনুমোদিত; অন্য HTTP পদ্ধতি (যেমন TRACE, CONNECT, PATCH) ব্যবহার করলে 405 ফেরত আসে
- সকল `POST` / `PUT` অনুরোধে `Content-Type: application/json` সেট থাকতে হবে (ফাইল আপলোড ছাড়া), অন্যথায় 415 ফেরত আসে
- অনুরোধ বডির আকার 10MB-এর বেশি হতে পারবে না, অন্যথায় 413 ফেরত আসে
- SecurityFilter সকল অনুরোধ ইনপুট XSS, SQL ইনজেকশন, পাথ ট্রাভার্সাল, কমান্ড ইনজেকশনের জন্য স্ক্যান করে; পাওয়া গেলে 403 ফেরত দেয়
- টানা 5 বার লগইন ব্যর্থ হলে অ্যাকাউন্ট লক হয়ে যায় (15 মিনিট); লক অবস্থায় লগইন অনুরোধ 429 ফেরত দেয়
- একজন ব্যবহারকারী সর্বোচ্চ 3টি বৈধ টোকেন একসাথে রাখতে পারে; এর বেশি হলে সবচেয়ে পুরনো টোকেন স্বয়ংক্রিয়ভাবে ব্ল্যাকলিস্টে যায়

## 2. ত্রুটি কোড

| code | অর্থ | ট্রিগার পরিস্থিতি |
|------|------|---------|
| 0 | সফল | |
| 400 | অনুরোধ প্যারামিটার ত্রুটি | অনুরোধের ফরম্যাট সঠিক নয় |
| 401 | প্রমাণিত নয় | টোকেন অনুপস্থিত / মেয়াদোত্তীর্ণ / ব্ল্যাকলিস্টে |
| 403 | অনুমতি নেই / নিরাপত্তা বাধা | RBAC অনুমতি অপর্যাপ্ত / SecurityFilter ট্রিগার |
| 404 | রিসোর্স নেই | কোয়েরি/আপডেট/ডিলিটের লক্ষ্য বিদ্যমান নেই |
| 405 | অনুরোধ পদ্ধতি অনুমোদিত নয় | শুধুমাত্র GET/POST/PUT/DELETE/OPTIONS/HEAD অনুমোদিত; অ-মানক পদ্ধতি সরাসরি প্রত্যাখ্যাত |
| 413 | অনুরোধ বডি খুব বড় | Content-Length 10MB-এর বেশি |
| 415 | অসমর্থিত মিডিয়া টাইপ | POST/PUT অনুরোধের Content-Type JSON নয় এবং ফাইল আপলোড নয় |
| 422 | প্যারামিটার ভ্যালিডেশন ব্যর্থ | প্রয়োজনীয় ফিল্ড অনুপস্থিত, ফরম্যাট ভুল, ব্যবসায়িক ভ্যালিডেশন ব্যর্থ |
| 429 | অত্যধিক অনুরোধ | RateLimit ট্রিগার / অ্যাকাউন্ট লক (টানা ৫টি লগইন ব্যর্থতায় ১৫ মিনিট লক) |
| 500 | সার্ভার অভ্যন্তরীণ ত্রুটি | |

## 3. পাবলিক এন্ডপয়েন্ট

সকল পাবলিক এন্ডপয়েন্ট `/api` গ্রুপের অধীনে মাউন্ট হয় এবং `ApiVersion` মিডলওয়্যার `API-Version` হেডার অনুযায়ী সংশ্লিষ্ট সংস্করণভুক্ত কন্ট্রোলারে (যেমন `app\api\v1\controller\AuthController`) বিতরণ করে।

### 3.1 হেলথ চেক

```
GET /health
```

- **প্রমাণীকরণ**: প্রয়োজন নেই
- **রেট লিমিট**: নেই

**প্রতিক্রিয়া উদাহরণ**:
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

`database`, `redis`, `elasticsearch` মান: `"ok"` | `"unavailable"`। ES অনুপলব্ধ হলে `elasticsearch` `"unavailable"` ফেরত দেয়; ক্লাস্টার হেলথ স্ট্যাটাস green/yellow না হলে প্রকৃত status মান ফেরত দেয় (যেমন `"red"`)।

### 3.2 API ডকুমেন্টেশন

```
GET /api/docs
```

- **প্রমাণীকরণ**: প্রয়োজন নেই
- **রেট লিমিট**: গ্লোবাল ডিফল্ট (60 বার/মিনিট)
- **প্রতিক্রিয়া**: OpenAPI 3.0.3 JSON স্পেসিফিকেশন, সকল এন্ডপয়েন্ট সংজ্ঞা, প্যারামিটার এবং Schema সহ

### 3.3 ক্যাপচা জেনারেট করুন

```
POST /api/captcha/generate
```

- **প্রমাণীকরণ**: প্রয়োজন নেই
- **অনুরোধ হেডার**: `API-Version: v1` (আবশ্যক)
- **রেট লিমিট**: গ্লোবাল ডিফল্ট (60 বার/মিনিট)

**অনুরোধ বডি**:
```json
{
  "difficulty": "medium"
}
```

| ফিল্ড | টাইপ | আবশ্যক | বিবরণ |
|------|------|------|------|
| difficulty | string | না | `easy` / `medium` / `hard`, ডিফল্ট `medium` |

**প্রতিক্রিয়া উদাহরণ** — ক্লিক টাইপ (`type: "click"`):
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

**প্রতিক্রিয়া উদাহরণ** — স্লাইডার টাইপ (`type: "slider"`):
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

**প্রতিক্রিয়া উদাহরণ** — রোটেট টাইপ (`type: "rotate"`):
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

| ফিল্ড | টাইপ | বিবরণ |
|------|------|------|
| key | string | ক্যাপচা শনাক্তকারী, ভ্যালিডেশনের সময় ফেরত পাঠানো হয় |
| type | string | ক্যাপচা টাইপ: `click` / `slider` / `rotate` |
| image | string | base64 data URI ছবি |
| extra | object | টাইপ-সম্পর্কিত অতিরিক্ত ডেটা (নিচে দেখুন) |

**`extra` টাইপ অনুযায়ী বিবরণ**:

| type | extra ফিল্ড | টাইপ | বিবরণ |
|------|-----------|------|------|
| click | targets | array | ক্লিক লক্ষ্য, যাতে `order`(ক্রম) `text`(সংকেত পাঠ্য) `x` `y`(স্থানাঙ্ক) রয়েছে |
| slider | x, y | int | গ্যাপের উপরে-বাম কোণের স্থানাঙ্ক (300×200 ক্যানভাসের উপর ভিত্তি করে) |
| slider | puzzle_w, puzzle_h | int | পাজল ছবির প্রস্থ ও উচ্চতা |
| slider | puzzle | string | পাজল ছবি base64 data URI |
| rotate | angle | int | সঠিক ঘূর্ণন কোণ (0-359), ছবি সোজা করতে `360-angle` ঘুরাতে হবে |

### 3.4 ক্যাপচা ভ্যালিডেট করুন

```
POST /api/captcha/verify
```

- **প্রমাণীকরণ**: প্রয়োজন নেই
- **অনুরোধ হেডার**: `API-Version: v1` (আবশ্যক)
- **রেট লিমিট**: গ্লোবাল ডিফল্ট (60 বার/মিনিট)

**অনুরোধ বডি** — ক্লিক টাইপ (`type: "click"`):
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

**অনুরোধ বডি** — স্লাইডার টাইপ (`type: "slider"`):
```json
{
  "key": "def456abc789",
  "type": "slider",
  "clicks": 120
}
```

**অনুরোধ বডি** — রোটেট টাইপ (`type: "rotate"`):
```json
{
  "key": "ghi789abc012",
  "type": "rotate",
  "clicks": 315
}
```

| ফিল্ড | টাইপ | আবশ্যক | বিবরণ |
|------|------|------|------|
| key | string | হ্যাঁ | ক্যাপচা key, generate দ্বারা ফেরত দেওয়া |
| type | string | হ্যাঁ | ক্যাপচা টাইপ, generate-এর ফেরত দেওয়া `type`-এর সাথে মিলতে হবে |
| clicks | ভিন্নতা | হ্যাঁ | উত্তর ডেটা, ফরম্যাট type অনুযায়ী পরিবর্তিত হয় (নিচে দেখুন) |

**`clicks` টাইপ অনুযায়ী বিবরণ**:

| type | clicks টাইপ | বিবরণ | ত্রুটি সহনশীলতা |
|------|------------|------|---------|
| click | `[{x:int, y:int}]` | ক্লিক স্থানাঙ্কের অ্যারে, order ক্রমে | 18px ব্যাসার্ধ |
| slider | `int` | স্লাইডার X-অক্ষ অফসেট | ±4px |
| rotate | `int` | ঘূর্ণন কোণ (0-359) | ±5° |

**প্রতিক্রিয়া উদাহরণ**:
```json
{
  "code": 0,
  "message": "验证通过",
  "data": { "valid": true }
}
```

ভ্যালিডেশন পাস হলে, ব্যাকএন্ড `captcha_verified:{key}` Redis-এ লেখে (TTL 300 সেকেন্ড), এবং লগইন এন্ডপয়েন্ট এর ভিত্তিতে অনুমতি দেয়।
ভ্যালিডেশন ব্যর্থ হলে `code` 422, `message` `"验证失败，请重试"` এবং `data.valid` `false` হয়।

### 3.5 লগইন

```
POST /api/auth/login
```

- **প্রমাণীকরণ**: প্রয়োজন নেই
- **অনুরোধ হেডার**: `API-Version: v1` (আবশ্যক)
- **রেট লিমিট**: 10 বার/মিনিট (IP + পাথ অনুযায়ী)

**অনুরোধ বডি**:
```json
{
  "username": "admin",
  "password": "djGYscnyS5V6mW6KyDFjB8vGwjBBnB3Odpyxu8LY...",
  "captcha_key": "abc123def456"
}
```

| ফিল্ড | টাইপ | আবশ্যক | ভ্যালিডেশন নিয়ম | বিবরণ |
|------|------|------|---------|------|
| username | string | হ্যাঁ | min:3, max:50 | ব্যবহারকারীর নাম |
| password | string | হ্যাঁ | min:6, max:32 (প্লেইনটেক্সট) | AES-256-CBC-HMAC এনক্রিপশনের পর Base64 এনকোডিং (প্লেইনটেক্সট সামঞ্জস্যপূর্ণ) |
| captcha_key | string | হ্যাঁ | | ক্যাপচা key (আগে `/api/captcha/verify` দিয়ে ভ্যালিডেট করা প্রয়োজন) |

### পাসওয়ার্ড এনক্রিপশন প্রোটোকল

**RSA-2048 অ্যাসিমেট্রিক এনক্রিপশন** ব্যবহার করে; পাবলিক কী ফ্রন্টএন্ড কোডে সংরক্ষিত থাকে (নিরাপদে প্রকাশ করা যায়), প্রাইভেট কী শুধুমাত্র সার্ভারের কাছে থাকে।

```
এনক্রিপশন প্রক্রিয়া (ক্লায়েন্ট):
  RSA পাবলিক কী (PEM) → PKCS1v1.5 এনক্রিপশন → Base64 এনকোডিং → প্রেরণ

ডিক্রিপশন প্রক্রিয়া (সার্ভার, ধাপে ধাপে ফলব্যাক):
  1. RSA প্রাইভেট কী ডিক্রিপশন → সফল এবং বৈধ UTF-8 → ডিক্রিপ্টেড ফলাফল ব্যবহার করুন
  2. AES-256-CBC-HMAC ডিক্রিপশন → সফল → ডিক্রিপ্টেড ফলাফল ব্যবহার করুন (পুরনো ক্লায়েন্ট সামঞ্জস্য)
  3. প্লেইনটেক্সট ফলব্যাক → মূল ইনপুট সরাসরি ব্যবহার করুন
```

পাবলিক কী ফ্রন্টএন্ড অ্যাপ্লিকেশনে এমবেড করা থাকে, নেটওয়ার্কে প্রেরণের প্রয়োজন নেই। প্রাইভেট কী শুধুমাত্র `.env`-এর `RSA_PRIVATE_KEY`-এ সংরক্ষিত, ফাঁস করা যাবে না।

> AES সিমেট্রিক এনক্রিপশন পুরনো সংস্করণের সামঞ্জস্য সমাধান; সকল ক্লায়েন্ট RSA-তে স্থানান্তরিত হলে সরিয়ে ফেলা হবে।

**প্রতিক্রিয়া উদাহরণ**:
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

| ফিল্ড | টাইপ | বিবরণ |
|------|------|------|
| access_token | string | JWT অ্যাক্সেস টোকেন |
| refresh_token | string | JWT রিফ্রেশ টোকেন |
| expires_in | int | অ্যাক্সেস টোকেনের বৈধতা সময় (সেকেন্ড), ডিফল্ট 7200 |
| user.id | string | hashid এনক্রিপ্টেড ব্যবহারকারী ID |
| user.username | string | ব্যবহারকারীর নাম |
| user.real_name | string | প্রকৃত নাম |

**সম্ভাব্য ত্রুটি**:
- 422: প্যারামিটার ভ্যালিডেশন ব্যর্থ (প্রয়োজনীয় ফিল্ড অনুপস্থিত, ফরম্যাট ভুল)
- 422: আগে ক্যাপচা ভ্যালিডেশন সম্পন্ন করুন (captcha_key `/api/captcha/verify` পাস করেনি)
- 401: ব্যবহারকারীর নাম বা পাসওয়ার্ড ভুল
- 403: অ্যাকাউন্ট নিষ্ক্রিয় করা হয়েছে
- 429: অ্যাকাউন্ট লক হয়েছে, 15 মিনিট পর আবার চেষ্টা করুন (টানা ৫টি লগইন ব্যর্থতায় ট্রিগার)

### 3.6 নিবন্ধন

```
POST /api/auth/register
```

- **প্রমাণীকরণ**: প্রয়োজন নেই
- **অনুরোধ হেডার**: `API-Version: v1` (আবশ্যক)
- **রেট লিমিট**: 5 বার/মিনিট (IP + পাথ অনুযায়ী)

**অনুরোধ বডি**:
```json
{
  "username": "newuser",
  "password": "djGYscnyS5V6mW6KyDFjB8vGwjBBnB3Odpyxu8LY...",
  "real_name": "新用户",
  "captcha_key": "abc123def456"
}
```

| ফিল্ড | টাইপ | আবশ্যক | ভ্যালিডেশন নিয়ম | বিবরণ |
|------|------|------|---------|------|
| username | string | হ্যাঁ | min:3, max:50 | ব্যবহারকারীর নাম (অদ্বিতীয়) |
| password | string | হ্যাঁ | min:6, max:32 (প্লেইনটেক্সট) | AES-256-CBC-HMAC এনক্রিপশনের পর Base64 এনকোডিং |
| real_name | string | হ্যাঁ | max:50 | প্রকৃত নাম |
| captcha_key | string | হ্যাঁ | | ক্যাপচা key (আগে `/api/captcha/verify` দিয়ে ভ্যালিডেট করা প্রয়োজন) |

**প্রতিক্রিয়া উদাহরণ**:
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

নিবন্ধন সফল হলে সরাসরি JWT টোকেন ফেরত দেওয়া হয়; ব্যবহারকারীর অবস্থা ডিফল্টভাবে সক্রিয় (status=1)।

### 3.7 টোকেন রিফ্রেশ করুন

```
POST /api/auth/refresh
```

- **প্রমাণীকরণ**: প্রয়োজন নেই
- **অনুরোধ হেডার**: `API-Version: v1` (আবশ্যক)
- **রেট লিমিট**: গ্লোবাল ডিফল্ট (60 বার/মিনিট)

**অনুরোধ বডি**:
```json
{
  "refresh_token": "eyJhbGciOiJIUzI1NiIs..."
}
```

| ফিল্ড | টাইপ | আবশ্যক | বিবরণ |
|------|------|------|------|
| refresh_token | string | হ্যাঁ | লগইন/নিবন্ধনের সময় প্রাপ্ত refresh_token |

**প্রতিক্রিয়া উদাহরণ**:
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

সফল রিফ্রেশে নতুন access_token এবং refresh_token দুটোই ফেরত আসে; পুরনো টোকেন স্বয়ংক্রিয়ভাবে অবৈধ হয়। রিফ্রেশ ব্যবহারকারীর শেষ লগইন সময় এবং IP-ও আপডেট করে।

**সম্ভাব্য ত্রুটি**:
- 422: রিফ্রেশ টোকেন অনুপস্থিত
- 401: রিফ্রেশ টোকেন অবৈধ বা মেয়াদোত্তীর্ণ

### 3.8 Prometheus মনিটরিং মেট্রিক্স

```
GET /metrics
```

- **প্রমাণীকরণ**: প্রয়োজন নেই
- **রেট লিমিট**: নেই
- **প্রতিক্রিয়া ফরম্যাট**: Prometheus টেক্সট ফরম্যাট (`text/plain; version=0.0.4`)

পাবলিক Prometheus মনিটরিং মেট্রিক্স এন্ডপয়েন্ট, Grafana/Prometheus দ্বারা স্ক্র্যাপিংয়ের জন্য।

**প্রতিক্রিয়া উদাহরণ**:
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

| মেট্রিক নাম | টাইপ | বিবরণ |
|------|------|------|
| `openadmin_http_requests_total` | gauge | ক্রমবর্ধমান মোট HTTP অনুরোধ সংখ্যা |
| `openadmin_active_users` | gauge | বর্তমান সক্রিয় ব্যবহারকারীর সংখ্যা (২৪ ঘণ্টার মধ্যে লগইন) |
| `openadmin_db_connection_status` | gauge | ডেটাবেস সংযোগ অবস্থা, 1=সাধারণ, 0=অস্বাভাবিক |
| `openadmin_redis_connection_status` | gauge | Redis সংযোগ অবস্থা, 1=সাধারণ, 0=অস্বাভাবিক |
| `openadmin_memory_usage_bytes` | gauge | PHP প্রক্রিয়ার বর্তমান মেমোরি ব্যবহার (bytes) |

## 4. ড্যাশবোর্ড

সকল অ্যাডমিন এন্ডপয়েন্ট `/admin` গ্রুপের অধীনে মাউন্ট হয় এবং তিনটি মিডলওয়্যারের মধ্য দিয়ে যায়: `AdminAuth` (JWT প্রমাণীকরণ), `AdminPermission` (RBAC অনুমতি যাচাই), `OperationLog` (অপারেশন রেকর্ডিং)।

### 4.1 ড্যাশবোর্ড ডেটা

```
GET /admin/dashboard
```

- **প্রমাণীকরণ**: JWT + RBAC
- **ক্যাশ**: Redis 5 মিনিট

**প্রতিক্রিয়া উদাহরণ**:
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

| stats ফিল্ড | টাইপ | বিবরণ |
|------|------|------|
| label | string | মেট্রিক নাম |
| value | string | মেট্রিক মান (স্ট্রিং টাইপ) |
| icon | string | Material আইকন নাম |
| color | string | কার্ড রঙের মান |
| trend | float? | দৈনিক বৃদ্ধির হার (শতাংশ); শুধুমাত্র "মোট ব্যবহারকারী"-র এই ফিল্ড আছে |

| trends ফিল্ড | টাইপ | বিবরণ |
|------|------|------|
| dates | array{string} | সাম্প্রতিক 30 দিনের তারিখ ক্রম |
| series | array{object} | ট্রেন্ড লাইন ডেটা, প্রতিটিতে name (নাম), data (মান অ্যারে), color (রঙ) |

## 5. ব্যবহারকারী ব্যবস্থাপনা

সকল ব্যবহারকারী ব্যবস্থাপনা এন্ডপয়েন্টের ফেরত দেওয়া `id` hashid এনক্রিপ্টেড স্ট্রিং। পাসওয়ার্ড ফিল্ড প্রতিক্রিয়া থেকে বাদ দেওয়া হয়েছে। ফোন নম্বর এবং ইমেইল তালিকা এন্ডপয়েন্টে মাস্ক করা হয়, এবং বিবরণ এন্ডপয়েন্টে প্লেইনটেক্সট ফেরত দেওয়া হয় (ডেটাবেস এনক্রিপ্টেড ফিল্ড Encryptable trait দ্বারা স্বয়ংক্রিয়ভাবে ডিক্রিপ্ট হয়)।

### 5.1 ব্যবহারকারী তালিকা

```
GET /admin/user
```

- **প্রমাণীকরণ**: JWT + RBAC

**কোয়েরি প্যারামিটার**:

| প্যারামিটার | টাইপ | আবশ্যক | ডিফল্ট মান | বিবরণ |
|------|------|------|------|------|
| page | int | না | 1 | পৃষ্ঠা নম্বর |
| limit | int | না | 15 | প্রতি পৃষ্ঠায় সংখ্যা |
| keyword | string | না | | অনুসন্ধান কীওয়ার্ড, ব্যবহারকারীর নাম ও প্রকৃত নামের সাথে মেলে |
| status | int | না | | অবস্থা ফিল্টার, 0=নিষ্ক্রিয়, 1=সক্রিয় |

**প্রতিক্রিয়া উদাহরণ**:
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

| ফিল্ড | টাইপ | বিবরণ |
|------|------|------|
| id | string | hashid এনক্রিপ্টেড ব্যবহারকারী ID |
| username | string | ব্যবহারকারীর নাম |
| real_name | string | প্রকৃত নাম |
| phone | string | মাস্ক করা ফোন নম্বর (`138****5678` ফরম্যাট) |
| email | string | মাস্ক করা ইমেইল (`a***@example.com` ফরম্যাট) |
| status | int | 1=সক্রিয়, 0=নিষ্ক্রিয় |
| last_login_at | string | শেষ লগইন সময় (datetime) |
| created_at | string | তৈরি হওয়ার সময় (datetime) |

### 5.2 ব্যবহারকারী তৈরি করুন

```
POST /admin/user
```

- **প্রমাণীকরণ**: JWT + RBAC

**অনুরোধ বডি**:
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

| ফিল্ড | টাইপ | আবশ্যক | ভ্যালিডেশন নিয়ম | বিবরণ |
|------|------|------|---------|------|
| username | string | হ্যাঁ | min:3, max:50 | ব্যবহারকারীর নাম (অদ্বিতীয়) |
| password | string | হ্যাঁ | min:6, max:32 | পাসওয়ার্ড (bcrypt-এ সংরক্ষিত) |
| real_name | string | হ্যাঁ | max:50 | প্রকৃত নাম |
| phone | string | না | | ফোন নম্বর (Encryptable এনক্রিপ্টেড সংরক্ষিত) |
| email | string | না | | ইমেইল (Encryptable এনক্রিপ্টেড সংরক্ষিত) |
| status | int | না | in:0,1 | অবস্থা, ডিফল্ট 1 (সক্রিয়) |

**প্রতিক্রিয়া উদাহরণ**:
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

**সম্ভাব্য ত্রুটি**:
- 422: ব্যবহারকারীর নাম ইতিমধ্যে আছে
- 422: প্যারামিটার ভ্যালিডেশন ব্যর্থ (প্রয়োজনীয় ফিল্ড অনুপস্থিত)

### 5.3 ব্যবহারকারীর বিবরণ

```
GET /admin/user/{id}
```

- **প্রমাণীকরণ**: JWT + RBAC
- **পাথ প্যারামিটার**: `{id}` hashid এনক্রিপ্টেড ব্যবহারকারী ID

**প্রতিক্রিয়া উদাহরণ**:
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

বিবরণ এন্ডপয়েন্টে `phone` এবং `email` প্লেইনটেক্সট ফেরত দেওয়া হয় (ডেটাবেসে এনক্রিপ্টেড সংরক্ষিত, Encryptable cast স্বয়ংক্রিয়ভাবে ডিক্রিপ্ট করে), মাস্ক করা হয় না। `password` এবং `id_card` কখনো প্রতিক্রিয়ায় থাকে না।

**সম্ভাব্য ত্রুটি**:
- 404: ব্যবহারকারী নেই

### 5.4 ব্যবহারকারী আপডেট করুন

```
PUT /admin/user/{id}
```

- **প্রমাণীকরণ**: JWT + RBAC
- **পাথ প্যারামিটার**: `{id}` hashid এনক্রিপ্টেড ব্যবহারকারী ID

**অনুরোধ বডি**:
```json
{
  "real_name": "新姓名",
  "password": "",
  "phone": "13900139000",
  "email": "new@example.com",
  "status": 1
}
```

| ফিল্ড | টাইপ | আবশ্যক | বিবরণ |
|------|------|------|------|
| real_name | string | না | প্রকৃত নাম; না পাঠালে মূল মান থাকে |
| password | string | না | নতুন পাসওয়ার্ড; খালি স্ট্রিং বা না পাঠালে পরিবর্তন হয় না |
| phone | string | না | ফোন নম্বর |
| email | string | না | ইমেইল |
| status | int | না | 0=নিষ্ক্রিয়, 1=সক্রিয় |

**প্রতিক্রিয়া উদাহরণ**:
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

**সম্ভাব্য ত্রুটি**:
- 404: ব্যবহারকারী নেই

### 5.5 ব্যবহারকারী মুছুন

```
DELETE /admin/user/{id}
```

- **প্রমাণীকরণ**: JWT + RBAC
- **পাথ প্যারামিটার**: `{id}` hashid এনক্রিপ্টেড ব্যবহারকারী ID
- **সংবেদনশীল অপারেশন**: পাসওয়ার্ড পুনর্নিশ্চিতকরণ প্রয়োজন

**অনুরোধ বডি**:
```json
{
  "password": "admin_password"
}
```

| ফিল্ড | টাইপ | আবশ্যক | বিবরণ |
|------|------|------|------|
| password | string | হ্যাঁ | বর্তমান লগইন করা ব্যবহারকারীর পাসওয়ার্ড (পুনর্নিশ্চিতকরণ) |

**প্রতিক্রিয়া উদাহরণ**:
```json
{
  "code": 0,
  "message": "删除成功",
  "data": []
}
```

সফট ডিলিট (Eloquent SoftDeletes) সম্পাদন করে; ডেটা deleted_at দ্বারা চিহ্নিত হয়, শারীরিকভাবে মুছে যায় না।

**সম্ভাব্য ত্রুটি**:
- 404: ব্যবহারকারী নেই
- 422: সংবেদনশীল অপারেশনে পাসওয়ার্ড নিশ্চিতকরণ প্রয়োজন (password খালি)
- 422: পাসওয়ার্ড ভ্যালিডেশন ব্যর্থ (পাসওয়ার্ড মেলে না)

### 5.6 একাধিক ব্যবহারকারী মুছুন

```
POST /admin/user/batch/destroy
```

- **প্রমাণীকরণ**: JWT + RBAC
- **সংবেদনশীল অপারেশন**: পাসওয়ার্ড পুনর্নিশ্চিতকরণ প্রয়োজন

**অনুরোধ বডি**:
```json
{
  "ids": ["a1b2c3d4", "e5f6g7h8"],
  "password": "admin_password"
}
```

| ফিল্ড | টাইপ | আবশ্যক | বিবরণ |
|------|------|------|------|
| ids | array{string} | হ্যাঁ | hashid এনক্রিপ্টেড ব্যবহারকারী ID-র অ্যারে |
| password | string | হ্যাঁ | বর্তমান লগইন করা ব্যবহারকারীর পাসওয়ার্ড (পুনর্নিশ্চিতকরণ) |

**প্রতিক্রিয়া উদাহরণ**:
```json
{
  "code": 0,
  "message": "删除成功",
  "data": {
    "count": 2
  }
}
```

সফট ডিলিট সম্পাদন করে; `data.count` প্রকৃত মুছে ফেলা সংখ্যা।

**সম্ভাব্য ত্রুটি**:
- 422: মুছতে ব্যবহারকারী নির্বাচন করুন (ids খালি)
- 422: অবৈধ ID (hashid ডিকোড ব্যর্থ)
- 422: পাসওয়ার্ড ভ্যালিডেশন ব্যর্থ

### 5.7 একাধিক ব্যবহারকারী সক্রিয়/নিষ্ক্রিয় করুন

```
POST /admin/user/batch/status
```

- **প্রমাণীকরণ**: JWT + RBAC

**অনুরোধ বডি**:
```json
{
  "ids": ["a1b2c3d4", "e5f6g7h8"],
  "status": 1
}
```

| ফিল্ড | টাইপ | আবশ্যক | বিবরণ |
|------|------|------|------|
| ids | array{string} | হ্যাঁ | hashid এনক্রিপ্টেড ব্যবহারকারী ID-র অ্যারে |
| status | int | হ্যাঁ | 0=নিষ্ক্রিয়, 1=সক্রিয় |

**প্রতিক্রিয়া উদাহরণ**:
```json
{
  "code": 0,
  "message": "批量启用成功",
  "data": {
    "count": 2
  }
}
```

status মান অনুযায়ী message গতিশীলভাবে `"批量启用成功"` বা `"批量禁用成功"` হয়।

**সম্ভাব্য ত্রুটি**:
- 422: ব্যবহারকারী নির্বাচন করুন (ids খালি)
- 422: অবস্থার মান অবৈধ (status 0 বা 1 নয়)

## 6. ভূমিকা ব্যবস্থাপনা

### 6.1 ভূমিকা তালিকা

```
GET /admin/role
```

- **প্রমাণীকরণ**: JWT + RBAC

**কোয়েরি প্যারামিটার**:

| প্যারামিটার | টাইপ | আবশ্যক | ডিফল্ট মান | বিবরণ |
|------|------|------|------|------|
| page | int | না | 1 | পৃষ্ঠা নম্বর |
| limit | int | না | 15 | প্রতি পৃষ্ঠায় সংখ্যা |

**প্রতিক্রিয়া উদাহরণ**:
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

| ফিল্ড | টাইপ | বিবরণ |
|------|------|------|
| id | string | hashid এনক্রিপ্টেড ভূমিকা ID |
| name | string | ভূমিকার নাম |
| slug | string | ভূমিকা শনাক্তকারী (অদ্বিতীয়, অনুমতি যাচাইয়ে ব্যবহৃত) |
| description | string | ভূমিকার বিবরণ |
| status | int | 1=সক্রিয়, 0=নিষ্ক্রিয় |
| users_count | int | এই ভূমিকা আছে এমন ব্যবহারকারীর সংখ্যা |

### 6.2 ভূমিকা তৈরি করুন

```
POST /admin/role
```

- **প্রমাণীকরণ**: JWT + RBAC

**অনুরোধ বডি**:
```json
{
  "name": "编辑员",
  "slug": "editor",
  "description": "内容编辑角色",
  "status": 1,
  "permission_ids": [1, 5, 12, 15]
}
```

| ফিল্ড | টাইপ | আবশ্যক | ভ্যালিডেশন নিয়ম | বিবরণ |
|------|------|------|---------|------|
| name | string | হ্যাঁ | max:50 | ভূমিকার নাম |
| slug | string | হ্যাঁ | max:50 | ভূমিকা শনাক্তকারী |
| description | string | না | | ভূমিকার বিবরণ, ডিফল্ট খালি স্ট্রিং |
| status | int | না | | অবস্থা, ডিফল্ট 1 |
| permission_ids | array{int} | না | | অনুমতি ID-র অ্যারে (মূল INT ID, hashid নয়) |

**প্রতিক্রিয়া উদাহরণ**:
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

### 6.3 ভূমিকা আপডেট করুন

```
PUT /admin/role/{id}
```

- **প্রমাণীকরণ**: JWT + RBAC

**অনুরোধ বডি**:
```json
{
  "name": "内容编辑",
  "description": "更新后的描述",
  "status": 1,
  "permission_ids": [1, 5, 12, 20]
}
```

| ফিল্ড | টাইপ | আবশ্যক | বিবরণ |
|------|------|------|------|
| name | string | না | ভূমিকার নাম |
| description | string | না | বিবরণ |
| status | int | না | 0=নিষ্ক্রিয়, 1=সক্রিয় |
| permission_ids | array{int} | না | অনুমতি ID-র অ্যারে; পাঠালে ভূমিকার অনুমতি সিঙ্ক (ওভাররাইট) হয় |

**প্রতিক্রিয়া উদাহরণ**:
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

### 6.4 ভূমিকা মুছুন

```
DELETE /admin/role/{id}
```

- **প্রমাণীকরণ**: JWT + RBAC
- **সংবেদনশীল অপারেশন**: পাসওয়ার্ড পুনর্নিশ্চিতকরণ প্রয়োজন

**অনুরোধ বডি**:
```json
{
  "password": "admin_password"
}
```

**প্রতিক্রিয়া উদাহরণ**:
```json
{
  "code": 0,
  "message": "删除成功",
  "data": []
}
```

মুছার সময় ভূমিকার সকল অনুমতি ও ব্যবহারকারীর সাথে সম্পর্ক স্বয়ংক্রিয়ভাবে বিচ্ছিন্ন হয়, তারপর ভূমিকা রেকর্ড শারীরিকভাবে মুছে যায়।

## 7. অনুমতি ব্যবস্থাপনা

অনুমতি ট্রি কাঠামো (parent_id স্ব-রেফারেন্স) ব্যবহার করে এবং তিন প্রকারের হয়। তালিকা এন্ডপয়েন্ট সম্পূর্ণ অনুমতি ট্রি ফেরত দেয়।

### 7.1 অনুমতি ট্রি

```
GET /admin/permission
```

- **প্রমাণীকরণ**: JWT + RBAC

**প্রতিক্রিয়া উদাহরণ**:
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

| ফিল্ড | টাইপ | বিবরণ |
|------|------|------|
| id | string | hashid এনক্রিপ্টেড |
| parent_id | string | মূল অনুমতি hashid, "0" মানে রুট নোড |
| name | string | অনুমতির নাম |
| slug | string | অনুমতি শনাক্তকারী (রুট/বাটন শনাক্তকারী) |
| type | int | 1=মেনু, 2=বাটন, 3=API |
| icon | string | মেনু আইকন (Material আইকন নাম) |
| path | string | ফ্রন্টএন্ড রুট পাথ |
| sort | int | ক্রম মান (অ্যাসেন্ডিং) |
| children | array? | চাইল্ড অনুমতির তালিকা (পুনরাবৃত্ত); চাইল্ড নোড না থাকলে এই ফিল্ড থাকে না |

### 7.2 অনুমতি তৈরি করুন

```
POST /admin/permission
```

- **প্রমাণীকরণ**: JWT + RBAC

**অনুরোধ বডি**:
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

| ফিল্ড | টাইপ | আবশ্যক | ভ্যালিডেশন নিয়ম | বিবরণ |
|------|------|------|---------|------|
| parent_id | int | না | | মূল অনুমতি ID (মূল INT টাইপ), ডিফল্ট 0 |
| name | string | হ্যাঁ | max:50 | অনুমতির নাম |
| slug | string | হ্যাঁ | max:100 | অনুমতি শনাক্তকারী |
| type | int | হ্যাঁ | in:1,2,3 | 1=মেনু, 2=বাটন, 3=API |
| icon | string | না | | মেনু আইকন, ডিফল্ট খালি |
| path | string | না | | ফ্রন্টএন্ড রুট পাথ, ডিফল্ট খালি |
| sort | int | না | | ক্রম মান, ডিফল্ট 0 |

**প্রতিক্রিয়া উদাহরণ**:
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

### 7.3 অনুমতি আপডেট করুন

```
PUT /admin/permission/{id}
```

- **প্রমাণীকরণ**: JWT + RBAC

**অনুরোধ বডি**:
```json
{
  "name": "系统配置",
  "icon": "tune",
  "path": "/system/config",
  "sort": 50
}
```

| ফিল্ড | টাইপ | আবশ্যক | বিবরণ |
|------|------|------|------|
| name | string | না | অনুমতির নাম |
| icon | string | না | আইকন |
| path | string | না | রুট পাথ |
| sort | int | না | ক্রম মান |

### 7.4 অনুমতি মুছুন

```
DELETE /admin/permission/{id}
```

- **প্রমাণীকরণ**: JWT + RBAC
- **সংবেদনশীল অপারেশন**: পাসওয়ার্ড পুনর্নিশ্চিতকরণ প্রয়োজন

**অনুরোধ বডি**:
```json
{
  "password": "admin_password"
}
```

**প্রতিক্রিয়া উদাহরণ**:
```json
{
  "code": 0,
  "message": "删除成功",
  "data": []
}
```

মুছার সময় সকল চাইল্ড অনুমতি ক্যাসকেডে মুছে যায় (`parent_id` = বর্তমান অনুমতি ID-র রেকর্ড), সাথে সকল ভূমিকার সাথে সম্পর্ক বিচ্ছিন্ন হয়।

## 8. সিস্টেম কনফিগারেশন

সিস্টেম কনফিগারেশন `group` + `key` সংমিশ্রণে অদ্বিতীয়।

### 8.1 কনফিগারেশন তালিকা

```
GET /admin/config
```

- **প্রমাণীকরণ**: JWT + RBAC

**কোয়েরি প্যারামিটার**:

| প্যারামিটার | টাইপ | আবশ্যক | ডিফল্ট মান | বিবরণ |
|------|------|------|------|------|
| page | int | না | 1 | পৃষ্ঠা নম্বর |
| limit | int | না | 15 | প্রতি পৃষ্ঠায় সংখ্যা |
| group | string | না | | কনফিগারেশন গ্রুপ অনুযায়ী ফিল্টার |

**প্রতিক্রিয়া উদাহরণ**:
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

| ফিল্ড | টাইপ | বিবরণ |
|------|------|------|
| id | string | hashid |
| group | string | কনফিগারেশন গ্রুপ (যেমন `system`, `email`, `storage`) |
| key | string | কনফিগারেশন কী |
| value | string | কনফিগারেশন মান |
| type | string | মানের টাইপ নির্দেশ (`string`, `integer`, `boolean`, `json` ইত্যাদি) |
| description | string | কনফিগারেশন বিবরণ |

### 8.2 কনফিগারেশন তৈরি করুন

```
POST /admin/config
```

- **প্রমাণীকরণ**: JWT + RBAC

**অনুরোধ বডি**:
```json
{
  "group": "email",
  "key": "smtp_host",
  "value": "smtp.example.com",
  "type": "string",
  "description": "SMTP 服务器地址"
}
```

| ফিল্ড | টাইপ | আবশ্যক | ভ্যালিডেশন নিয়ম | বিবরণ |
|------|------|------|---------|------|
| group | string | হ্যাঁ | max:100 | কনফিগারেশন গ্রুপ |
| key | string | হ্যাঁ | max:100 | কনফিগারেশন কী (একই গ্রুপে অদ্বিতীয়) |
| value | string | হ্যাঁ | | কনফিগারেশন মান |
| type | string | না | | মানের টাইপ, ডিফল্ট `string` |
| description | string | না | | কনফিগারেশন বিবরণ, ডিফল্ট খালি |

**প্রতিক্রিয়া উদাহরণ**:
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

**সম্ভাব্য ত্রুটি**:
- 422: কনফিগারেশন আইটেম ইতিমধ্যে আছে (একই group + key)

### 8.3 কনফিগারেশন আপডেট করুন

```
PUT /admin/config/{id}
```

- **প্রমাণীকরণ**: JWT + RBAC

**অনুরোধ বডি**:
```json
{
  "value": "smtp.newhost.com",
  "type": "string",
  "description": "更新后的 SMTP 地址"
}
```

| ফিল্ড | টাইপ | আবশ্যক | বিবরণ |
|------|------|------|------|
| value | string | না | কনফিগারেশন মান আপডেট করুন |
| type | string | না | মানের টাইপ আপডেট করুন |
| description | string | না | বিবরণ টেক্সট আপডেট করুন |

### 8.4 কনফিগারেশন মুছুন

```
DELETE /admin/config/{id}
```

- **প্রমাণীকরণ**: JWT + RBAC
- **সংবেদনশীল অপারেশন**: পাসওয়ার্ড পুনর্নিশ্চিতকরণ প্রয়োজন

**অনুরোধ বডি**:
```json
{
  "password": "admin_password"
}
```

কনফিগারেশন রেকর্ড শারীরিকভাবে মুছে ফেলে।

## 9. অপারেশন লগ

অপারেশন লগ শুধুমাত্র-পঠনযোগ্য ইন্টারফেস; `OperationLog` মিডলওয়্যার প্রতিটি POST/PUT/DELETE অনুরোধে স্বয়ংক্রিয়ভাবে লেখে, সংরক্ষিত ফিল্ডে `user_id`, `action`, `method`, `path`, `ip`, `source`, `input` অন্তর্ভুক্ত।

### 9.1 অপারেশন লগ তালিকা

```
GET /admin/log
```

- **প্রমাণীকরণ**: JWT + RBAC

**কোয়েরি প্যারামিটার**:

| প্যারামিটার | টাইপ | আবশ্যক | ডিফল্ট মান | বিবরণ |
|------|------|------|------|------|
| page | int | না | 1 | পৃষ্ঠা নম্বর |
| limit | int | না | 15 | প্রতি পৃষ্ঠায় সংখ্যা |
| user_id | int | না | | ব্যবহারকারী ID দিয়ে সঠিক ফিল্টার (মূল INT টাইপ) |
| action | string | না | | অপারেশন অ্যাকশন দিয়ে সঠিক ফিল্টার |
| path | string | না | | অনুরোধ পাথ দিয়ে ফাজি ফিল্টার |
| start_date | string | না | | শুরু তারিখ (Y-m-d ফরম্যাট) |
| end_date | string | না | | শেষ তারিখ (Y-m-d ফরম্যাট) |

**প্রতিক্রিয়া উদাহরণ**:
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

| ফিল্ড | টাইপ | বিবরণ |
|------|------|------|
| id | string | hashid |
| user_name | string | অপারেটিং ব্যবহারকারীর নাম (user সম্পর্ক থেকে প্রাপ্ত; লগইন ছাড়া অপারেশন "系统" দেখায়) |
| action | string | অপারেশন অ্যাকশনের বিবরণ |
| method | string | HTTP পদ্ধতি (POST/PUT/DELETE) |
| path | string | অনুরোধ পাথ |
| ip | string | ক্লায়েন্ট IP |
| source | string | অনুরোধের উৎস |
| input | string | অনুরোধ প্যারামিটারের JSON স্ট্রিং (ফাইল অন্তর্ভুক্ত নয়) |
| created_at | string | অপারেশন সময় (datetime) |

## 10. প্রোফাইল সেন্টার

প্রোফাইল সেন্টার এন্ডপয়েন্টে শুধুমাত্র JWT প্রমাণীকরণ প্রয়োজন (RBAC অনুমতি যাচাইয়ের প্রয়োজন নেই — `AdminPermission` মিডলওয়্যারের হোয়াইটলিস্টে এগুলো যোগ করা উচিত)।

### 10.1 ব্যক্তিগত তথ্য আপডেট করুন

```
PUT /admin/profile
```

- **প্রমাণীকরণ**: JWT

**অনুরোধ বডি**:
```json
{
  "real_name": "新姓名",
  "phone": "13800138000",
  "email": "me@example.com"
}
```

| ফিল্ড | টাইপ | আবশ্যক | বিবরণ |
|------|------|------|------|
| real_name | string | না | প্রকৃত নাম |
| phone | string | না | ফোন নম্বর (Encryptable এনক্রিপ্টেড সংরক্ষিত) |
| email | string | না | ইমেইল (Encryptable এনক্রিপ্টেড সংরক্ষিত) |

**প্রতিক্রিয়া উদাহরণ**:
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

প্রতিক্রিয়ায় `phone` এবং `email` প্লেইনটেক্সট ফেরত দেওয়া হয়; `password` এবং `id_card` বাদ দেওয়া হয়েছে।

### 10.2 পাসওয়ার্ড পরিবর্তন করুন

```
PUT /admin/profile/password
```

- **প্রমাণীকরণ**: JWT

**অনুরোধ বডি**:
```json
{
  "old_password": "current_pass",
  "new_password": "new_pass_123"
}
```

| ফিল্ড | টাইপ | আবশ্যক | ভ্যালিডেশন নিয়ম | বিবরণ |
|------|------|------|---------|------|
| old_password | string | হ্যাঁ | | বর্তমান পাসওয়ার্ড |
| new_password | string | হ্যাঁ | min:6, max:32 | নতুন পাসওয়ার্ড |

**প্রতিক্রিয়া উদাহরণ**:
```json
{
  "code": 0,
  "message": "密码修改成功",
  "data": []
}
```

**সম্ভাব্য ত্রুটি**:
- 422: পুরনো ও নতুন পাসওয়ার্ড লিখুন
- 422: পুরনো পাসওয়ার্ড ভুল
- 422: নতুন পাসওয়ার্ডের দৈর্ঘ্য 6-32 অক্ষর

### 10.3 লগআউট

```
POST /admin/profile/logout
```

- **প্রমাণীকরণ**: JWT

**অনুরোধ বডি**: নেই (কোনো requestBody নেই, টোকেন Authorization হেডার থেকে পড়া হয়)

**প্রতিক্রিয়া উদাহরণ**:
```json
{
  "code": 0,
  "message": "已登出",
  "data": []
}
```

লগআউট লজিক: JWT ডিকোড করে অবশিষ্ট বৈধতা (exp - now) পান, সেই টোকেনের md5 হ্যাশ Redis ব্ল্যাকলিস্ট `jwt_blacklist:{md5}`-এ লিখুন, TTL = অবশিষ্ট বৈধতা। ব্ল্যাকলিস্টের টোকেন `AdminAuth` মিডলওয়্যারে বাধা পায় এবং 401 ফেরত দেয়।

টোকেন না থাকলে 401 ফেরত আসে। মেয়াদোত্তীর্ণ/অবৈধ টোকেন (ডিকোডে এক্সেপশন) তবুও সফল লগআউট হিসেবে গণ্য হয়।

## 11. আমদানি/রপ্তানি

### 11.1 Excel রপ্তানি

```
POST /admin/export/excel
```

- **প্রমাণীকরণ**: JWT + RBAC
- **প্রতিক্রিয়া টাইপ**: ফাইল ডাউনলোড (`application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`)

**অনুরোধ বডি**:
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

| ফিল্ড | টাইপ | আবশ্যক | ডিফল্ট মান | বিবরণ |
|------|------|------|------|------|
| table | string | না | `admin_user` | রপ্তানি টেবিলের নাম। সমর্থিত: `admin_user`, `operation_log`, `admin_role`, `system_config` |
| columns | array{string} | না | | রপ্তানি কলাম ফিল্ড নামের অ্যারে; খালি হলে টেবিলের সব কলাম রপ্তানি হয় |
| conditions | object | না | `{}` | ফিল্টার শর্ত, key-value জোড়া; মান খালি না হলে WHERE-এর জন্য ব্যবহৃত |
| title | string | না | `数据导出` | Excel শিরোনাম (Sheet নাম হিসেবে দেখানো হয়) |

**সমর্থিত টেবিল ও কলাম**:

| table | উপলব্ধ কলাম |
|-------|-------|
| `admin_user` | id, username, real_name, phone, email, status, last_login_at, last_login_ip, created_at |
| `operation_log` | id, user_id, action, method, path, ip, created_at |
| `admin_role` | id, name, slug, description, status, created_at |
| `system_config` | id, group, key, value, type, description, created_at |

সংবেদনশীল ফিল্ড `phone`, `email`, `id_card` রপ্তানির সময় স্বয়ংক্রিয়ভাবে মাস্ক হয়। ডেটা সীমা 10000 সারি। Excel-এর প্রথম সারি ফ্রিজ হয় এবং স্বয়ংক্রিয় ফিল্টার সক্রিয়।

### 11.2 PDF রপ্তানি

```
POST /admin/export/pdf
```

- **প্রমাণীকরণ**: JWT + RBAC
- **প্রতিক্রিয়া টাইপ**: ফাইল ডাউনলোড (`application/pdf`, A4 ল্যান্ডস্কেপ)

**অনুরোধ বডি**:
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

অথবা টেবিল মোড:
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

| ফিল্ড | টাইপ | আবশ্যক | ডিফল্ট মান | বিবরণ |
|------|------|------|------|------|
| type | string | না | `table` | রপ্তানি টাইপ: `table` / `dashboard` |
| title | string | না | `数据导出` | PDF শিরোনাম |
| data | object | না | `{}` | রপ্তানি ডেটা |

`type=dashboard` হলে `data`-তে `stats` অ্যারে থাকতে হবে (কার্ড আকারে রেন্ডার); `type=table` হলে `data`-তে `columns` এবং `rows` অ্যারে থাকতে হবে।

PDF টেমপ্লেটে কপিরাইট তথ্য এবং রপ্তানি টাইমস্ট্যাম্প অন্তর্ভুক্ত।

### 11.3 ব্যবহারকারী আমদানি (Excel)

```
POST /admin/import/users
```

- **প্রমাণীকরণ**: JWT + RBAC
- **অনুরোধ টাইপ**: `multipart/form-data` (ফাইল আপলোড)

**ফর্ম ফিল্ড**:

| ফিল্ড | টাইপ | আবশ্যক | বিবরণ |
|------|------|------|------|
| file | file | হ্যাঁ | `.xlsx` বা `.xls` ফরম্যাট |

**Excel কলাম প্রয়োজনীয়তা**:

| কলামের নাম | আবশ্যক | বিবরণ |
|------|------|------|
| username | হ্যাঁ | ব্যবহারকারীর নাম (অদ্বিতীয়) |
| password | হ্যাঁ | পাসওয়ার্ড (bcrypt হ্যাশ সংরক্ষিত) |
| real_name | হ্যাঁ | প্রকৃত নাম |
| phone | না | ফোন নম্বর |
| email | না | ইমেইল |
| status | না | অবস্থা, ডিফল্ট 1 |

সারি 1 কলাম শিরোনাম (কেস-অসংবেদনশীল); ডেটা সারি 2 থেকে শুরু হয়।

**প্রতিক্রিয়া উদাহরণ**:
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

| ফিল্ড | টাইপ | বিবরণ |
|------|------|------|
| total | int | মোট সারি (শিরোনাম সারি বাদে) |
| success | int | সফলভাবে আমদানি হওয়া সংখ্যা |
| failed | int | ব্যর্থ সংখ্যা |
| errors | array | ব্যর্থতার বিবরণ, প্রতিটিতে row (Excel সারি নম্বর) এবং reason (ব্যর্থতার কারণ) |

## 12. ফাইল আপলোড

```
POST /admin/upload
```

- **প্রমাণীকরণ**: JWT + RBAC
- **অনুরোধ টাইপ**: `multipart/form-data`

**ফর্ম ফিল্ড**:

| ফিল্ড | টাইপ | আবশ্যক | বিবরণ |
|------|------|------|------|
| file | file | হ্যাঁ | আপলোড করা ফাইল |

**অনুমোদিত ফাইল টাইপ**: `jpg`, `jpeg`, `png`, `gif`, `pdf`, `xlsx`, `docx`
**সর্বোচ্চ ফাইল আকার**: 10MB

**প্রতিক্রিয়া উদাহরণ**:
```json
{
  "code": 0,
  "message": "上传成功",
  "data": {
    "url": "/upload/2026-05-21/abc123def456.png"
  }
}
```

ফাইল তারিখ অনুযায়ী `public/upload/{Y-m-d}/`-এ সংরক্ষিত হয়, ফাইলের নাম `md5(uniqid) + মূল এক্সটেনশন`। `url` সাইট রুটের সাপেক্ষে পাথ।

**সম্ভাব্য ত্রুটি**:
- 422: ফাইল নির্বাচন করুন (আপলোড হয়নি)
- 422: অসমর্থিত ফাইল টাইপ
- 422: ফাইলের আকার 10MB-এর বেশি হতে পারবে না
- 500: ফাইল আপলোড ব্যর্থ (ফাইল অবৈধ)

## 13. প্রতিক্রিয়া হেডার

সকল এন্ডপয়েন্টে (গ্লোবাল মিডলওয়্যার স্তরে ইনজেক্ট) নিম্নলিখিত প্রতিক্রিয়া হেডার থাকে:

| হেডার | বিবরণ |
|----|------|
| `X-RateLimit-Limit` | রেট লিমিটের সীমা (সংখ্যা) |
| `X-RateLimit-Remaining` | অবশিষ্ট অনুরোধ সংখ্যা |
| `X-RateLimit-Reset` | রেট লিমিট উইন্ডো রিসেট টাইমস্ট্যাম্প |
| `Retry-After` | শুধুমাত্র রেট লিমিট ট্রিগার হলে ফেরত আসে, অপেক্ষার প্রস্তাবিত সেকেন্ড |
| `X-Content-Type-Options` | `nosniff` (webman ডিফল্ট, MIME স্নিফিং নিষিদ্ধ) |
| `X-Frame-Options` | `DENY` (webman-এর CORS মিডলওয়্যার/বেস কনফিগারেশন দ্বারা প্রদত্ত) |

রেট লিমিট বিবরণ:
- ডিফল্ট গ্লোবাল সীমা: 60 বার/মিনিট / IP+পাথ
- লগইন এন্ডপয়েন্ট `/api/auth/login`: 10 বার/মিনিট
- নিবন্ধন এন্ডপয়েন্ট `/api/auth/register`: 5 বার/মিনিট
- Redis পারমাণবিক স্লাইডিং উইন্ডো অ্যালগরিদম (Lua ZSET) ব্যবহার করে, TOCTOU রেস এড়ানো
- Redis অনুপলব্ধ হলে fail open (অনুমতি), অনুরোধ ব্লক করে না

## 14. প্রমাণীকরণ প্রক্রিয়া

সম্পূর্ণ প্রমাণীকরণ ক্রম:

```
1. ক্লায়েন্ট POST /api/captcha/generate অনুরোধ করে
   (অনুরোধ হেডার: API-Version: v1)
    ↓
   সার্ভার ফেরত দেয়: key + type(click|slider|rotate) + base64 ছবি + extra(টাইপ-সম্পর্কিত ডেটা)
   
2. ব্যবহারকারী ক্যাপচা অপারেশন সম্পন্ন করে (ক্লিক/ড্র্যাগ/রোটেট), ক্লায়েন্ট উত্তর সংগ্রহ করে
   
3. ক্লায়েন্ট POST /api/captcha/verify অনুরোধ করে
   (অনুরোধ হেডার: API-Version: v1, Content-Type: application/json)
   অনুরোধ বডি: { key, type, clicks }
   - type=click:  clicks = [{x, y}, ...]        // স্থানাঙ্ক অ্যারে
   - type=slider: clicks = 120                   // X অফসেট
   - type=rotate: clicks = 315                   // ঘূর্ণন কোণ
    ↓
   সার্ভার:
   a. স্টোরেজ থেকে captcha:key ডেটা পড়ুন (TTL 300 সেকেন্ড)
   b. type অনুযায়ী উত্তর ভ্যালিডেট করুন (click: ইউক্লিডীয় দূরত্ব ≤18px / slider: ±4px / rotate: ±5°)
   c. ভ্যালিডেশন পাস → Redis-এ `captcha_verified:{key}` = 1 লিখুন (TTL 300 সেকেন্ড)
   d. ভ্যালিডেশন ব্যর্থ → 422 ফেরত দিন, গণনা +1, 3 বারের বেশি হলে key বাতিল
    ↓
   সার্ভার ফেরত দেয়: { valid: true/false }

4. ক্লায়েন্ট POST /api/auth/login অনুরোধ করে
   (অনুরোধ হেডার: API-Version: v1, Content-Type: application/json)
   অনুরোধ বডি: { username, password(এনক্রিপ্টেড), captcha_key }
    ↓
   সার্ভার:
   a. প্যারামিটার ভ্যালিডেশন → 422
   b. captcha_verified:{key} আছে কিনা পরীক্ষা করুন → 422
   c. captcha_verified:{key} মুছুন (একবার ব্যবহারযোগ্য)
   d. পাসওয়ার্ড ডিক্রিপ্ট করুন: EncryptionService::decrypt(password) → প্লেইনটেক্সট
   e. ব্যবহারকারীর ক্রেডেনশিয়াল ভ্যালিডেট করুন (password_verify) → 401
   f. অ্যাকাউন্টের অবস্থা পরীক্ষা করুন → 403/429
   g. JWT ইস্যু করুন (access + refresh) → 200
   h. last_login_at / last_login_ip আপডেট করুন
    ↓
   ক্লায়েন্ট সংরক্ষণ করে: access_token, refresh_token, expires_in

5. পরবর্তী অনুরোধে JWT বহন করা হয়
   অনুরোধ হেডার: Authorization: Bearer <access_token>
    ↓
AdminAuth মিডলওয়্যার:
   a. Bearer টোকেন বের করুন
   b. ব্ল্যাকলিস্ট পরীক্ষা করুন (Redis jwt_blacklist:{md5}) → 401
   c. JWT ডিকোড করুন, মেয়াদোত্তীর্ণতা ভ্যালিডেট করুন → 401
   d. $request->adminId = sub ফিল্ড সেট করুন
    ↓
AdminPermission মিডলওয়্যার:
   a. রিসোর্স রুটের জন্য অনুমতি শনাক্তকারী পার্স করুন
   b. ব্যবহারকারীর ভূমিকা কোয়েরি করুন → ভূমিকা অনুমতি, মিলান
   c. অনুমতি নেই → 403
    ↓
Controller অনুরোধ প্রক্রিয়া করে
    ↓
Response + X-RateLimit-* হেডার

6. অ্যাক্সেস টোকেনের মেয়াদ শেষ হওয়ার আগে রিফ্রেশ করুন
   ক্লায়েন্ট POST /api/auth/refresh অনুরোধ করে
   অনুরোধ বডি: { refresh_token: "..." }
    ↓
   সার্ভার refresh_token ডিকোড করে → নতুন access + refresh ইস্যু করে
    ↓
   ক্লায়েন্ট লোকাল টোকেন আপডেট করে

7. লগআউট
   ক্লায়েন্ট POST /admin/profile/logout অনুরোধ করে
   অনুরোধ হেডার: Authorization: Bearer <access_token>
    ↓
   সার্ভার:
   a. JWT ডিকোড করে অবশিষ্ট TTL পান
   b. Redis ব্ল্যাকলিস্টে লিখুন: jwt_blacklist:{md5(token)} = 1, TTL = অবশিষ্ট বৈধতা
   c. সফলতা ফেরত দিন
```

### JWT কাঠামো

- **access_token**: `{ sub: <user_id>, username: "<name>" }`, ডিফল্ট TTL 7200 সেকেন্ড (JWT কনফিগারেশন `default_expire` দ্বারা নিয়ন্ত্রিত)
- **refresh_token**: `{ sub: <user_id>, token_type: "refresh" }`, ডিফল্ট TTL 1209600 সেকেন্ড (JWT কনফিগারেশন `refresh_expire` দ্বারা নিয়ন্ত্রিত, অর্থাৎ 14 দিন)

### নিরাপত্তা ব্যবস্থাপনা

- পাসওয়ার্ড `PASSWORD_BCRYPT` হ্যাশে সংরক্ষিত হয়
- পাসওয়ার্ড পরিবহন স্তরে AES-256-CBC-HMAC এনক্রিপশন ব্যবহার হয় (ক্লায়েন্ট এনক্রিপ্ট → সার্ভার ডিক্রিপ্ট), প্লেইনটেক্সট ফলব্যাক সামঞ্জস্যপূর্ণ
- সংবেদনশীল ফিল্ড (phone, email, id_card) ডেটাবেস স্তরে `erikwang2013/encryptable` দ্বারা স্বচ্ছভাবে এনক্রিপ্ট/ডিক্রিপ্ট হয়
- API স্তরের ID `erikwang2013/hashids` দ্বারা এনক্রিপ্টেড প্রেরণ হয়, মূল snowflake ID ক্রম প্রকাশ এড়ানো
- SecurityFilter XSS, SQL ইনজেকশন, পাথ ট্রাভার্সাল, কমান্ড ইনজেকশন বৈশ্বিকভাবে স্ক্যান করে; একই IP 5 বার/60 সেকেন্ডে 15 মিনিট অস্থায়ী ব্ল্যাকলিস্ট
- সংবেদনশীল অপারেশন (ব্যবহারকারী, ভূমিকা, অনুমতি, কনফিগারেশন মুছা) বর্তমান লগইন ব্যবহারকারীর পাসওয়ার্ড পুনর্নিশ্চিতকরণ প্রয়োজন
- সমান্তরাল সেশন সীমা: একজন ব্যবহারকারী সর্বোচ্চ 3টি বৈধ টোকেন; চতুর্থ ডিভাইস লগইন করলে সবচেয়ে পুরনো টোকেন ব্ল্যাকলিস্টে বাধ্য হয়
- অ্যাকাউন্ট লক: টানা ৫টি লগইন ব্যর্থতায় ১৫ মিনিট অ্যাকাউন্ট লক; লক অবস্থায় 429 ফেরত দেয়

## 15. ডিপ্লয়মেন্ট ও অপারেশন

### Docker Compose

প্রজেক্ট রুট ডিরেক্টরি `docker-compose.yml` প্রদান করে, যা ৫টি সার্ভিস (Nginx, webman অ্যাপ, MySQL, Redis, Elasticsearch) অর্কেস্ট্রেট করে। PHP `Dockerfile` দিয়ে নির্মিত হয় (ভিত্তি `php:8.3-cli`, OPcache সক্রিয়)।

```bash
cp .env.docker .env
docker-compose up -d
```

### CI/CD

`.github/workflows/ci.yml` GitHub Actions ক্রমাগত ইন্টিগ্রেশন পাইপলাইন সংজ্ঞায়িত করে:
- `php -l` সিনট্যাক্স পরীক্ষা
- PHPUnit ইউনিট টেস্ট
- `flutter analyze` স্ট্যাটিক বিশ্লেষণ

### ডেটাবেস ব্যাকআপ

`database/backup/` ডিরেক্টরি ব্যাকআপ ও পুনরুদ্ধার স্ক্রিপ্ট প্রদান করে:
- `backup.sh` — mysqldump + gzip কম্প্রেশন ব্যাকআপ, 30 দিনের পুরনো ব্যাকআপ ফাইল স্বয়ংক্রিয়ভাবে পরিষ্কার
- `restore.sh` — ইন্টারঅ্যাক্টিভ পুনরুদ্ধার, ব্যবহারকারীর নির্বাচনের জন্য বিদ্যমান ব্যাকআপ তালিকাভুক্ত করে

### Nginx নিরাপত্তা কনফিগারেশন

প্রোডাকশন ডিপ্লয়মেন্টের জন্য `docs/nginx-security.conf` দেখুন, reverse proxy নিরাপত্তা শক্তিশালীকরণ কনফিগারেশনের জন্য।
