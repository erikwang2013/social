# API संदर्भ दस्तावेज़
**语言 / Languages:** [中文](API.md) · [English](API.en.md) · [한국어](API.ko.md) · [Русский](API.ru.md) · [Deutsch](API.de.md) · [Français](API.fr.md) · [Español](API.es.md) · [Português](API.pt.md) · [हिन्दी](API.hi.md) · [العربية](API.ar.md) · [বাংলা](API.bn.md) · [Bahasa Indonesia](API.id.md) · [日本語](API.ja.md)

> Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz

## 1. परिचय

open-admin webman v2 पर आधारित है और RESTful JSON API प्रदान करता है। सभी एडमिन एंडपॉइंट्स के लिए JWT प्रमाणीकरण और RBAC अनुमति जाँच आवश्यक है; सार्वजनिक एंडपॉइंट्स API संस्करण हेडर के माध्यम से संस्करणित कंट्रोलरों में रूट होते हैं।

- **आधार URL**: `http://localhost:8787`
- **API संस्करण**: अनुरोध हेडर `API-Version: v1` द्वारा नियंत्रित (अनुपस्थित होने पर डिफ़ॉल्ट v1)
- **भाषा**: `Accept-Language` हेडर या `?lang=zh_CN|en` पैरामीटर से बदलें (डिफ़ॉल्ट zh_CN), Locale मिडलवेयर द्वारा स्वतः पता लगाया जाता है

> **एंडपॉइंट सारांश**: प्रमाणीकरण(5) | डैशबोर्ड(1) | उपयोगकर्ता(7) | भूमिका(4) | अनुमति(4) | कॉन्फ़िगरेशन(4) | लॉग(1) | प्रोफ़ाइल केंद्र(3) | आयात/निर्यात(3) | अपलोड(1) | ऑपरेशन(4: health/metrics/docs/security.txt) | कुल 37 एंडपॉइंट
- **प्रमाणीकरण**: `Authorization: Bearer <token>` (JWT)
- **प्रतिक्रिया प्रारूप**: `{ "code": 0, "message": "success", "data": {...} }`
- **दस्तावेज़ एंडपॉइंट**: `GET /api/docs` OpenAPI 3.0 JSON स्पेकिफिकेशन लौटाता है

### अनुरोध आवश्यकताएँ

- केवल `GET` / `POST` / `PUT` / `DELETE` / `OPTIONS` / `HEAD` विधियाँ अनुमत हैं; अन्य HTTP विधियों (जैसे TRACE, CONNECT, PATCH) का उपयोग करने पर 405 लौटेगा
- सभी `POST` / `PUT` अनुरोधों में `Content-Type: application/json` सेट होना चाहिए (फ़ाइल अपलोड को छोड़कर), अन्यथा 415 लौटेगा
- अनुरोध बॉडी का आकार 10MB से अधिक नहीं होना चाहिए, अन्यथा 413 लौटेगा
- SecurityFilter सभी अनुरोध इनपुट को XSS, SQL इंजेक्शन, पाथ ट्रैवर्सल, कमांड इंजेक्शन के लिए स्कैन करता है; पाए जाने पर 403 लौटाता है
- लगातार 5 बार लॉगिन विफल होने पर खाता लॉक हो जाता है (15 मिनट); लॉक अवधि के दौरान लॉगिन अनुरोध 429 लौटाता है
- एक उपयोगकर्ता अधिकतम 3 वैध टोकन एक साथ रख सकता है; इससे अधिक होने पर सबसे पुराना टोकन स्वतः ब्लैकलिस्ट में जाता है

## 2. त्रुटि कोड

| code | अर्थ | ट्रिगर परिदृश्य |
|------|------|---------|
| 0 | सफल | |
| 400 | अनुरोध पैरामीटर त्रुटि | अनुरोध प्रारूप सही नहीं है |
| 401 | प्रमाणित नहीं | टोकन गायब / समाप्त / ब्लैकलिस्ट में |
| 403 | कोई अनुमति नहीं / सुरक्षा अवरोध | RBAC अनुमति अपर्याप्त / SecurityFilter ट्रिगर |
| 404 | संसाधन मौजूद नहीं | क्वेरी/अपडेट/डिलीट का लक्ष्य मौजूद नहीं |
| 405 | अनुरोध विधि अनुमत नहीं | केवल GET/POST/PUT/DELETE/OPTIONS/HEAD अनुमत; गैर-मानक विधियाँ सीधे अस्वीकृत |
| 413 | अनुरोध बॉडी बहुत बड़ी | Content-Length 10MB से अधिक |
| 415 | असमर्थित मीडिया प्रकार | POST/PUT अनुरोध का Content-Type JSON नहीं और फ़ाइल अपलोड नहीं |
| 422 | पैरामीटर सत्यापन विफल | आवश्यक फ़ील्ड गायब, प्रारूप गलत, व्यावसायिक सत्यापन विफल |
| 429 | बहुत अधिक अनुरोध | RateLimit ट्रिगर / खाता लॉक (लगातार 5 लॉगिन विफलताओं पर 15 मिनट लॉक) |
| 500 | सर्वर आंतरिक त्रुटि | |

## 3. सार्वजनिक एंडपॉइंट

सभी सार्वजनिक एंडपॉइंट `/api` समूह के अंतर्गत माउंट होते हैं और `ApiVersion` मिडलवेयर द्वारा `API-Version` हेडर के अनुसार संबंधित संस्करणित कंट्रोलर (जैसे `app\api\v1\controller\AuthController`) में वितरित होते हैं।

### 3.1 स्वास्थ्य जाँच

```
GET /health
```

- **प्रमाणीकरण**: आवश्यक नहीं
- **रेट लिमिट**: नहीं

**प्रतिक्रिया उदाहरण**:
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

`database`, `redis`, `elasticsearch` मान: `"ok"` | `"unavailable"`। ES अनुपलब्ध होने पर `elasticsearch` `"unavailable"` लौटाता है; क्लस्टर स्वास्थ्य स्थिति green/yellow न होने पर वास्तविक status मान लौटाता है (जैसे `"red"`)।

### 3.2 API दस्तावेज़

```
GET /api/docs
```

- **प्रमाणीकरण**: आवश्यक नहीं
- **रेट लिमिट**: वैश्विक डिफ़ॉल्ट (60 बार/मिनट)
- **प्रतिक्रिया**: OpenAPI 3.0.3 JSON स्पेकिफिकेशन, सभी एंडपॉइंट परिभाषाएँ, पैरामीटर और Schema सहित

### 3.3 कैप्चा जनरेट करें

```
POST /api/captcha/generate
```

- **प्रमाणीकरण**: आवश्यक नहीं
- **अनुरोध हेडर**: `API-Version: v1` (आवश्यक)
- **रेट लिमिट**: वैश्विक डिफ़ॉल्ट (60 बार/मिनट)

**अनुरोध बॉडी**:
```json
{
  "difficulty": "medium"
}
```

| फ़ील्ड | प्रकार | आवश्यक | विवरण |
|------|------|------|------|
| difficulty | string | नहीं | `easy` / `medium` / `hard`, डिफ़ॉल्ट `medium` |

**प्रतिक्रिया उदाहरण** — क्लिक प्रकार (`type: "click"`):
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

**प्रतिक्रिया उदाहरण** — स्लाइडर प्रकार (`type: "slider"`):
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

**प्रतिक्रिया उदाहरण** — रोटेट प्रकार (`type: "rotate"`):
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

| फ़ील्ड | प्रकार | विवरण |
|------|------|------|
| key | string | कैप्चा पहचानकर्ता, सत्यापन के समय वापस भेजा जाता है |
| type | string | कैप्चा प्रकार: `click` / `slider` / `rotate` |
| image | string | base64 data URI छवि |
| extra | object | प्रकार से संबंधित अतिरिक्त डेटा (नीचे देखें) |

**`extra` प्रकार के अनुसार विवरण**:

| type | extra फ़ील्ड | प्रकार | विवरण |
|------|-----------|------|------|
| click | targets | array | क्लिक लक्ष्य, जिसमें `order`(क्रम) `text`(संकेत पाठ) `x` `y`(निर्देशांक) शामिल हैं |
| slider | x, y | int | गैप के ऊपरी-बाएँ कोने के निर्देशांक (300×200 कैनवास पर आधारित) |
| slider | puzzle_w, puzzle_h | int | पज़ल छवि की चौड़ाई और ऊँचाई |
| slider | puzzle | string | पज़ल छवि base64 data URI |
| rotate | angle | int | सही रोटेशन कोण (0-359), छवि को सीधा करने के लिए `360-angle` घुमाना होगा |

### 3.4 कैप्चा सत्यापित करें

```
POST /api/captcha/verify
```

- **प्रमाणीकरण**: आवश्यक नहीं
- **अनुरोध हेडर**: `API-Version: v1` (आवश्यक)
- **रेट लिमिट**: वैश्विक डिफ़ॉल्ट (60 बार/मिनट)

**अनुरोध बॉडी** — क्लिक प्रकार (`type: "click"`):
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

**अनुरोध बॉडी** — स्लाइडर प्रकार (`type: "slider"`):
```json
{
  "key": "def456abc789",
  "type": "slider",
  "clicks": 120
}
```

**अनुरोध बॉडी** — रोटेट प्रकार (`type: "rotate"`):
```json
{
  "key": "ghi789abc012",
  "type": "rotate",
  "clicks": 315
}
```

| फ़ील्ड | प्रकार | आवश्यक | विवरण |
|------|------|------|------|
| key | string | हाँ | कैप्चा key, generate द्वारा लौटाया गया |
| type | string | हाँ | कैप्चा प्रकार, generate द्वारा लौटाए गए `type` से मेल खाना चाहिए |
| clicks | भिन्न | हाँ | उत्तर डेटा, प्रारूप type के अनुसार बदलता है (नीचे देखें) |

**`clicks` प्रकार के अनुसार विवरण**:

| type | clicks प्रकार | विवरण | त्रुटि सहनशीलता |
|------|------------|------|---------|
| click | `[{x:int, y:int}]` | क्लिक निर्देशांकों की सरणी, order क्रम में | 18px त्रिज्या |
| slider | `int` | स्लाइडर X-अक्ष ऑफ़सेट | ±4px |
| rotate | `int` | रोटेशन कोण (0-359) | ±5° |

**प्रतिक्रिया उदाहरण**:
```json
{
  "code": 0,
  "message": "验证通过",
  "data": { "valid": true }
}
```

सत्यापन पास होने के बाद, बैकएंड `captcha_verified:{key}` को Redis में लिखता है (TTL 300 सेकंड), और लॉगिन एंडपॉइंट इसी के आधार पर अनुमति देता है।
सत्यापन विफल होने पर `code` 422 है, `message` `"验证失败，请重试"` है, और `data.valid` `false` है।

### 3.5 लॉगिन

```
POST /api/auth/login
```

- **प्रमाणीकरण**: आवश्यक नहीं
- **अनुरोध हेडर**: `API-Version: v1` (आवश्यक)
- **रेट लिमिट**: 10 बार/मिनट (IP + पाथ के अनुसार)

**अनुरोध बॉडी**:
```json
{
  "username": "admin",
  "password": "djGYscnyS5V6mW6KyDFjB8vGwjBBnB3Odpyxu8LY...",
  "captcha_key": "abc123def456"
}
```

| फ़ील्ड | प्रकार | आवश्यक | सत्यापन नियम | विवरण |
|------|------|------|---------|------|
| username | string | हाँ | min:3, max:50 | उपयोगकर्ता नाम |
| password | string | हाँ | min:6, max:32 (प्लेनटेक्स्ट) | AES-256-CBC-HMAC एन्क्रिप्शन के बाद Base64 एन्कोडिंग (प्लेनटेक्स्ट संगत) |
| captcha_key | string | हाँ | | कैप्चा key (पहले `/api/captcha/verify` से सत्यापित होना आवश्यक) |

### पासवर्ड एन्क्रिप्शन प्रोटोकॉल

**RSA-2048 असममित एन्क्रिप्शन** का उपयोग करता है; सार्वजनिक कुंजी फ्रंटएंड कोड में संग्रहीत होती है (सुरक्षित रूप से उजागर की जा सकती है), निजी कुंजी केवल सर्वर के पास होती है।

```
एन्क्रिप्शन प्रक्रिया (क्लाइंट):
  RSA सार्वजनिक कुंजी (PEM) → PKCS1v1.5 एन्क्रिप्शन → Base64 एन्कोडिंग → ट्रांसमिशन

डिक्रिप्शन प्रक्रिया (सर्वर, चरणबद्ध फ़ॉलबैक):
  1. RSA निजी कुंजी डिक्रिप्शन → सफल और वैध UTF-8 → डिक्रिप्टेड परिणाम का उपयोग करें
  2. AES-256-CBC-HMAC डिक्रिप्शन → सफल → डिक्रिप्टेड परिणाम का उपयोग करें (पुराने क्लाइंट संगतता)
  3. प्लेनटेक्स्ट फ़ॉलबैक → मूल इनपुट का सीधे उपयोग करें
```

सार्वजनिक कुंजी फ्रंटएंड एप्लिकेशन में अंतर्निहित होती है, नेटवर्क पर प्रसारित करने की आवश्यकता नहीं होती। निजी कुंजी केवल `.env` के `RSA_PRIVATE_KEY` में संग्रहीत होती है और लीक नहीं होनी चाहिए।

> AES सममित एन्क्रिप्शन पुराने संस्करणों के लिए संगतता समाधान है; सभी क्लाइंट RSA में स्थानांतरित होने के बाद हटा दिया जाएगा।

**प्रतिक्रिया उदाहरण**:
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

| फ़ील्ड | प्रकार | विवरण |
|------|------|------|
| access_token | string | JWT एक्सेस टोकन |
| refresh_token | string | JWT रिफ्रेश टोकन |
| expires_in | int | एक्सेस टोकन की वैधता अवधि (सेकंड), डिफ़ॉल्ट 7200 |
| user.id | string | hashid एन्क्रिप्टेड उपयोगकर्ता ID |
| user.username | string | उपयोगकर्ता नाम |
| user.real_name | string | वास्तविक नाम |

**संभावित त्रुटियाँ**:
- 422: पैरामीटर सत्यापन विफल (आवश्यक फ़ील्ड गायब, प्रारूप गलत)
- 422: कृपया पहले कैप्चा सत्यापन पूरा करें (captcha_key `/api/captcha/verify` से पास नहीं हुआ)
- 401: उपयोगकर्ता नाम या पासवर्ड गलत
- 403: खाता अक्षम कर दिया गया है
- 429: खाता लॉक कर दिया गया है, कृपया 15 मिनट बाद पुनः प्रयास करें (लगातार 5 लॉगिन विफलताओं से ट्रिगर)

### 3.6 पंजीकरण

```
POST /api/auth/register
```

- **प्रमाणीकरण**: आवश्यक नहीं
- **अनुरोध हेडर**: `API-Version: v1` (आवश्यक)
- **रेट लिमिट**: 5 बार/मिनट (IP + पाथ के अनुसार)

**अनुरोध बॉडी**:
```json
{
  "username": "newuser",
  "password": "djGYscnyS5V6mW6KyDFjB8vGwjBBnB3Odpyxu8LY...",
  "real_name": "新用户",
  "captcha_key": "abc123def456"
}
```

| फ़ील्ड | प्रकार | आवश्यक | सत्यापन नियम | विवरण |
|------|------|------|---------|------|
| username | string | हाँ | min:3, max:50 | उपयोगकर्ता नाम (अद्वितीय) |
| password | string | हाँ | min:6, max:32 (प्लेनटेक्स्ट) | AES-256-CBC-HMAC एन्क्रिप्शन के बाद Base64 एन्कोडिंग |
| real_name | string | हाँ | max:50 | वास्तविक नाम |
| captcha_key | string | हाँ | | कैप्चा key (पहले `/api/captcha/verify` से सत्यापित होना आवश्यक) |

**प्रतिक्रिया उदाहरण**:
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

पंजीकरण सफल होने पर सीधे JWT टोकन लौटाया जाता है; उपयोगकर्ता स्थिति डिफ़ॉल्ट रूप से सक्रिय (status=1) होती है।

### 3.7 टोकन रिफ्रेश करें

```
POST /api/auth/refresh
```

- **प्रमाणीकरण**: आवश्यक नहीं
- **अनुरोध हेडर**: `API-Version: v1` (आवश्यक)
- **रेट लिमिट**: वैश्विक डिफ़ॉल्ट (60 बार/मिनट)

**अनुरोध बॉडी**:
```json
{
  "refresh_token": "eyJhbGciOiJIUzI1NiIs..."
}
```

| फ़ील्ड | प्रकार | आवश्यक | विवरण |
|------|------|------|------|
| refresh_token | string | हाँ | लॉगिन/पंजीकरण पर प्राप्त refresh_token |

**प्रतिक्रिया उदाहरण**:
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

सफल रिफ्रेश पर नया access_token और refresh_token दोनों लौटाए जाते हैं; पुराने टोकन स्वतः अमान्य हो जाते हैं। रिफ्रेश उपयोगकर्ता के अंतिम लॉगिन समय और IP को भी अपडेट करता है।

**संभावित त्रुटियाँ**:
- 422: रिफ्रेश टोकन गायब
- 401: रिफ्रेश टोकन अमान्य या समाप्त

### 3.8 Prometheus मॉनिटरिंग मेट्रिक्स

```
GET /metrics
```

- **प्रमाणीकरण**: आवश्यक नहीं
- **रेट लिमिट**: नहीं
- **प्रतिक्रिया प्रारूप**: Prometheus टेक्स्ट प्रारूप (`text/plain; version=0.0.4`)

सार्वजनिक Prometheus मॉनिटरिंग मेट्रिक्स एंडपॉइंट, Grafana/Prometheus द्वारा स्क्रेपिंग के लिए।

**प्रतिक्रिया उदाहरण**:
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

| मेट्रिक नाम | प्रकार | विवरण |
|------|------|------|
| `openadmin_http_requests_total` | gauge | संचयी HTTP अनुरोधों की कुल संख्या |
| `openadmin_active_users` | gauge | वर्तमान सक्रिय उपयोगकर्ताओं की संख्या (24 घंटों के भीतर लॉगिन) |
| `openadmin_db_connection_status` | gauge | डेटाबेस कनेक्शन स्थिति, 1=सामान्य, 0=असामान्य |
| `openadmin_redis_connection_status` | gauge | Redis कनेक्शन स्थिति, 1=सामान्य, 0=असामान्य |
| `openadmin_memory_usage_bytes` | gauge | PHP प्रक्रिया की वर्तमान मेमोरी उपयोग (bytes) |

## 4. डैशबोर्ड

सभी एडमिन एंडपॉइंट `/admin` समूह के अंतर्गत माउंट होते हैं और तीन मिडलवेयर से गुजरते हैं: `AdminAuth` (JWT प्रमाणीकरण), `AdminPermission` (RBAC अनुमति जाँच), `OperationLog` (ऑपरेशन रिकॉर्डिंग)।

### 4.1 डैशबोर्ड डेटा

```
GET /admin/dashboard
```

- **प्रमाणीकरण**: JWT + RBAC
- **कैश**: Redis 5 मिनट

**प्रतिक्रिया उदाहरण**:
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

| stats फ़ील्ड | प्रकार | विवरण |
|------|------|------|
| label | string | मेट्रिक नाम |
| value | string | मेट्रिक मान (स्ट्रिंग प्रकार) |
| icon | string | Material आइकन नाम |
| color | string | कार्ड रंग मान |
| trend | float? | दैनिक वृद्धि दर (प्रतिशत); केवल "कुल उपयोगकर्ता" में यह फ़ील्ड होता है |

| trends फ़ील्ड | प्रकार | विवरण |
|------|------|------|
| dates | array{string} | पिछले 30 दिनों की दिनांक श्रृंखला |
| series | array{object} | ट्रेंड लाइन डेटा, प्रत्येक में name (नाम), data (मान सरणी), color (रंग) |

## 5. उपयोगकर्ता प्रबंधन

सभी उपयोगकर्ता प्रबंधन एंडपॉइंट द्वारा लौटाया गया `id` hashid एन्क्रिप्टेड स्ट्रिंग है। पासवर्ड फ़ील्ड प्रतिक्रिया से बाहर रखा गया है। फ़ोन नंबर और ईमेल सूची एंडपॉइंट्स में मास्क किए जाते हैं, और विवरण एंडपॉइंट्स में प्लेनटेक्स्ट लौटाए जाते हैं (डेटाबेस एन्क्रिप्टेड फ़ील्ड Encryptable trait द्वारा स्वतः डिक्रिप्ट होते हैं)।

### 5.1 उपयोगकर्ता सूची

```
GET /admin/user
```

- **प्रमाणीकरण**: JWT + RBAC

**क्वेरी पैरामीटर**:

| पैरामीटर | प्रकार | आवश्यक | डिफ़ॉल्ट मान | विवरण |
|------|------|------|------|------|
| page | int | नहीं | 1 | पृष्ठ संख्या |
| limit | int | नहीं | 15 | प्रति पृष्ठ संख्या |
| keyword | string | नहीं | | खोज कीवर्ड, उपयोगकर्ता नाम और वास्तविक नाम से मिलान |
| status | int | नहीं | | स्थिति फ़िल्टर, 0=अक्षम, 1=सक्रिय |

**प्रतिक्रिया उदाहरण**:
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

| फ़ील्ड | प्रकार | विवरण |
|------|------|------|
| id | string | hashid एन्क्रिप्टेड उपयोगकर्ता ID |
| username | string | उपयोगकर्ता नाम |
| real_name | string | वास्तविक नाम |
| phone | string | मास्क किया गया फ़ोन नंबर (`138****5678` प्रारूप) |
| email | string | मास्क किया गया ईमेल (`a***@example.com` प्रारूप) |
| status | int | 1=सक्रिय, 0=अक्षम |
| last_login_at | string | अंतिम लॉगिन समय (datetime) |
| created_at | string | निर्माण समय (datetime) |

### 5.2 उपयोगकर्ता बनाएं

```
POST /admin/user
```

- **प्रमाणीकरण**: JWT + RBAC

**अनुरोध बॉडी**:
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

| फ़ील्ड | प्रकार | आवश्यक | सत्यापन नियम | विवरण |
|------|------|------|---------|------|
| username | string | हाँ | min:3, max:50 | उपयोगकर्ता नाम (अद्वितीय) |
| password | string | हाँ | min:6, max:32 | पासवर्ड (bcrypt में संग्रहीत) |
| real_name | string | हाँ | max:50 | वास्तविक नाम |
| phone | string | नहीं | | फ़ोन नंबर (Encryptable एन्क्रिप्टेड संग्रहीत) |
| email | string | नहीं | | ईमेल (Encryptable एन्क्रिप्टेड संग्रहीत) |
| status | int | नहीं | in:0,1 | स्थिति, डिफ़ॉल्ट 1 (सक्रिय) |

**प्रतिक्रिया उदाहरण**:
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

**संभावित त्रुटियाँ**:
- 422: उपयोगकर्ता नाम पहले से मौजूद है
- 422: पैरामीटर सत्यापन विफल (आवश्यक फ़ील्ड गायब)

### 5.3 उपयोगकर्ता विवरण

```
GET /admin/user/{id}
```

- **प्रमाणीकरण**: JWT + RBAC
- **पाथ पैरामीटर**: `{id}` hashid एन्क्रिप्टेड उपयोगकर्ता ID है

**प्रतिक्रिया उदाहरण**:
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

विवरण एंडपॉइंट में `phone` और `email` प्लेनटेक्स्ट लौटाए जाते हैं (डेटाबेस में एन्क्रिप्टेड संग्रहीत, Encryptable cast स्वतः डिक्रिप्ट करता है), मास्क नहीं किया जाता। `password` और `id_card` हमेशा प्रतिक्रिया में नहीं होते।

**संभावित त्रुटियाँ**:
- 404: उपयोगकर्ता मौजूद नहीं

### 5.4 उपयोगकर्ता अपडेट करें

```
PUT /admin/user/{id}
```

- **प्रमाणीकरण**: JWT + RBAC
- **पाथ पैरामीटर**: `{id}` hashid एन्क्रिप्टेड उपयोगकर्ता ID है

**अनुरोध बॉडी**:
```json
{
  "real_name": "新姓名",
  "password": "",
  "phone": "13900139000",
  "email": "new@example.com",
  "status": 1
}
```

| फ़ील्ड | प्रकार | आवश्यक | विवरण |
|------|------|------|------|
| real_name | string | नहीं | वास्तविक नाम; न भेजने पर मूल मान रहता है |
| password | string | नहीं | नया पासवर्ड; खाली स्ट्रिंग या न भेजने पर संशोधित नहीं |
| phone | string | नहीं | फ़ोन नंबर |
| email | string | नहीं | ईमेल |
| status | int | नहीं | 0=अक्षम, 1=सक्रिय |

**प्रतिक्रिया उदाहरण**:
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

**संभावित त्रुटियाँ**:
- 404: उपयोगकर्ता मौजूद नहीं

### 5.5 उपयोगकर्ता हटाएं

```
DELETE /admin/user/{id}
```

- **प्रमाणीकरण**: JWT + RBAC
- **पाथ पैरामीटर**: `{id}` hashid एन्क्रिप्टेड उपयोगकर्ता ID है
- **संवेदनशील ऑपरेशन**: पासवर्ड पुनः पुष्टि आवश्यक

**अनुरोध बॉडी**:
```json
{
  "password": "admin_password"
}
```

| फ़ील्ड | प्रकार | आवश्यक | विवरण |
|------|------|------|------|
| password | string | हाँ | वर्तमान लॉगिन उपयोगकर्ता का पासवर्ड (पुनः पुष्टि) |

**प्रतिक्रिया उदाहरण**:
```json
{
  "code": 0,
  "message": "删除成功",
  "data": []
}
```

सॉफ्ट डिलीट (Eloquent SoftDeletes) निष्पादित करता है; डेटा deleted_at से चिह्नित होता है, भौतिक रूप से नहीं हटता।

**संभावित त्रुटियाँ**:
- 404: उपयोगकर्ता मौजूद नहीं
- 422: संवेदनशील ऑपरेशन के लिए पासवर्ड पुष्टि आवश्यक (password खाली)
- 422: पासवर्ड सत्यापन विफल (पासवर्ड मेल नहीं खाता)

### 5.6 बल्क उपयोगकर्ता हटाएं

```
POST /admin/user/batch/destroy
```

- **प्रमाणीकरण**: JWT + RBAC
- **संवेदनशील ऑपरेशन**: पासवर्ड पुनः पुष्टि आवश्यक

**अनुरोध बॉडी**:
```json
{
  "ids": ["a1b2c3d4", "e5f6g7h8"],
  "password": "admin_password"
}
```

| फ़ील्ड | प्रकार | आवश्यक | विवरण |
|------|------|------|------|
| ids | array{string} | हाँ | hashid एन्क्रिप्टेड उपयोगकर्ता ID की सरणी |
| password | string | हाँ | वर्तमान लॉगिन उपयोगकर्ता का पासवर्ड (पुनः पुष्टि) |

**प्रतिक्रिया उदाहरण**:
```json
{
  "code": 0,
  "message": "删除成功",
  "data": {
    "count": 2
  }
}
```

सॉफ्ट डिलीट निष्पादित करता है; `data.count` वास्तविक हटाई गई संख्या है।

**संभावित त्रुटियाँ**:
- 422: कृपया हटाने के लिए उपयोगकर्ता चुनें (ids खाली)
- 422: अमान्य ID (hashid डिकोड विफल)
- 422: पासवर्ड सत्यापन विफल

### 5.7 बल्क सक्रिय/अक्षम करें

```
POST /admin/user/batch/status
```

- **प्रमाणीकरण**: JWT + RBAC

**अनुरोध बॉडी**:
```json
{
  "ids": ["a1b2c3d4", "e5f6g7h8"],
  "status": 1
}
```

| फ़ील्ड | प्रकार | आवश्यक | विवरण |
|------|------|------|------|
| ids | array{string} | हाँ | hashid एन्क्रिप्टेड उपयोगकर्ता ID की सरणी |
| status | int | हाँ | 0=अक्षम, 1=सक्रिय |

**प्रतिक्रिया उदाहरण**:
```json
{
  "code": 0,
  "message": "批量启用成功",
  "data": {
    "count": 2
  }
}
```

status मान के अनुसार message गतिशील रूप से `"批量启用成功"` या `"批量禁用成功"` होता है।

**संभावित त्रुटियाँ**:
- 422: कृपया उपयोगकर्ता चुनें (ids खाली)
- 422: स्थिति मान अमान्य (status 0 या 1 नहीं है)

## 6. भूमिका प्रबंधन

### 6.1 भूमिका सूची

```
GET /admin/role
```

- **प्रमाणीकरण**: JWT + RBAC

**क्वेरी पैरामीटर**:

| पैरामीटर | प्रकार | आवश्यक | डिफ़ॉल्ट मान | विवरण |
|------|------|------|------|------|
| page | int | नहीं | 1 | पृष्ठ संख्या |
| limit | int | नहीं | 15 | प्रति पृष्ठ संख्या |

**प्रतिक्रिया उदाहरण**:
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

| फ़ील्ड | प्रकार | विवरण |
|------|------|------|
| id | string | hashid एन्क्रिप्टेड भूमिका ID |
| name | string | भूमिका नाम |
| slug | string | भूमिका पहचानकर्ता (अद्वितीय, अनुमति जाँच के लिए उपयोग) |
| description | string | भूमिका विवरण |
| status | int | 1=सक्रिय, 0=अक्षम |
| users_count | int | इस भूमिका वाले उपयोगकर्ताओं की संख्या |

### 6.2 भूमिका बनाएं

```
POST /admin/role
```

- **प्रमाणीकरण**: JWT + RBAC

**अनुरोध बॉडी**:
```json
{
  "name": "编辑员",
  "slug": "editor",
  "description": "内容编辑角色",
  "status": 1,
  "permission_ids": [1, 5, 12, 15]
}
```

| फ़ील्ड | प्रकार | आवश्यक | सत्यापन नियम | विवरण |
|------|------|------|---------|------|
| name | string | हाँ | max:50 | भूमिका नाम |
| slug | string | हाँ | max:50 | भूमिका पहचानकर्ता |
| description | string | नहीं | | भूमिका विवरण, डिफ़ॉल्ट खाली स्ट्रिंग |
| status | int | नहीं | | स्थिति, डिफ़ॉल्ट 1 |
| permission_ids | array{int} | नहीं | | अनुमति ID की सरणी (मूल INT ID, hashid नहीं) |

**प्रतिक्रिया उदाहरण**:
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

### 6.3 भूमिका अपडेट करें

```
PUT /admin/role/{id}
```

- **प्रमाणीकरण**: JWT + RBAC

**अनुरोध बॉडी**:
```json
{
  "name": "内容编辑",
  "description": "更新后的描述",
  "status": 1,
  "permission_ids": [1, 5, 12, 20]
}
```

| फ़ील्ड | प्रकार | आवश्यक | विवरण |
|------|------|------|------|
| name | string | नहीं | भूमिका नाम |
| description | string | नहीं | विवरण |
| status | int | नहीं | 0=अक्षम, 1=सक्रिय |
| permission_ids | array{int} | नहीं | अनुमति ID की सरणी; भेजने पर भूमिका अनुमतियाँ सिंक (ओवरराइट) होती हैं |

**प्रतिक्रिया उदाहरण**:
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

### 6.4 भूमिका हटाएं

```
DELETE /admin/role/{id}
```

- **प्रमाणीकरण**: JWT + RBAC
- **संवेदनशील ऑपरेशन**: पासवर्ड पुनः पुष्टि आवश्यक

**अनुरोध बॉडी**:
```json
{
  "password": "admin_password"
}
```

**प्रतिक्रिया उदाहरण**:
```json
{
  "code": 0,
  "message": "删除成功",
  "data": []
}
```

हटाते समय भूमिका का सभी अनुमतियों और उपयोगकर्ताओं से संबंध स्वतः हट जाता है, फिर भूमिका रिकॉर्ड भौतिक रूप से हटा दिया जाता है।

## 7. अनुमति प्रबंधन

अनुमतियाँ ट्री संरचना (parent_id स्व-संदर्भ) का उपयोग करती हैं और तीन प्रकार की होती हैं। सूची एंडपॉइंट पूर्ण अनुमति ट्री लौटाता है।

### 7.1 अनुमति ट्री

```
GET /admin/permission
```

- **प्रमाणीकरण**: JWT + RBAC

**प्रतिक्रिया उदाहरण**:
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

| फ़ील्ड | प्रकार | विवरण |
|------|------|------|
| id | string | hashid एन्क्रिप्टेड |
| parent_id | string | मूल अनुमति hashid, "0" का अर्थ रूट नोड |
| name | string | अनुमति नाम |
| slug | string | अनुमति पहचानकर्ता (रूट/बटन पहचानकर्ता) |
| type | int | 1=मेनू, 2=बटन, 3=API |
| icon | string | मेनू आइकन (Material आइकन नाम) |
| path | string | फ्रंटएंड रूट पाथ |
| sort | int | क्रम मान (आरोही) |
| children | array? | चाइल्ड अनुमति सूची (पुनरावर्ती); कोई चाइल्ड नोड न होने पर यह फ़ील्ड शामिल नहीं होती |

### 7.2 अनुमति बनाएं

```
POST /admin/permission
```

- **प्रमाणीकरण**: JWT + RBAC

**अनुरोध बॉडी**:
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

| फ़ील्ड | प्रकार | आवश्यक | सत्यापन नियम | विवरण |
|------|------|------|---------|------|
| parent_id | int | नहीं | | मूल अनुमति ID (मूल INT प्रकार), डिफ़ॉल्ट 0 |
| name | string | हाँ | max:50 | अनुमति नाम |
| slug | string | हाँ | max:100 | अनुमति पहचानकर्ता |
| type | int | हाँ | in:1,2,3 | 1=मेनू, 2=बटन, 3=API |
| icon | string | नहीं | | मेनू आइकन, डिफ़ॉल्ट खाली |
| path | string | नहीं | | फ्रंटएंड रूट पाथ, डिफ़ॉल्ट खाली |
| sort | int | नहीं | | क्रम मान, डिफ़ॉल्ट 0 |

**प्रतिक्रिया उदाहरण**:
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

### 7.3 अनुमति अपडेट करें

```
PUT /admin/permission/{id}
```

- **प्रमाणीकरण**: JWT + RBAC

**अनुरोध बॉडी**:
```json
{
  "name": "系统配置",
  "icon": "tune",
  "path": "/system/config",
  "sort": 50
}
```

| फ़ील्ड | प्रकार | आवश्यक | विवरण |
|------|------|------|------|
| name | string | नहीं | अनुमति नाम |
| icon | string | नहीं | आइकन |
| path | string | नहीं | रूट पाथ |
| sort | int | नहीं | क्रम मान |

### 7.4 अनुमति हटाएं

```
DELETE /admin/permission/{id}
```

- **प्रमाणीकरण**: JWT + RBAC
- **संवेदनशील ऑपरेशन**: पासवर्ड पुनः पुष्टि आवश्यक

**अनुरोध बॉडी**:
```json
{
  "password": "admin_password"
}
```

**प्रतिक्रिया उदाहरण**:
```json
{
  "code": 0,
  "message": "删除成功",
  "data": []
}
```

हटाते समय सभी चाइल्ड अनुमतियाँ कैस्केड में हटती हैं (`parent_id` = वर्तमान अनुमति ID वाले रिकॉर्ड), साथ ही सभी भूमिकाओं से संबंध हट जाता है।

## 8. सिस्टम कॉन्फ़िगरेशन

सिस्टम कॉन्फ़िगरेशन `group` + `key` संयोजन से अद्वितीय होता है।

### 8.1 कॉन्फ़िगरेशन सूची

```
GET /admin/config
```

- **प्रमाणीकरण**: JWT + RBAC

**क्वेरी पैरामीटर**:

| पैरामीटर | प्रकार | आवश्यक | डिफ़ॉल्ट मान | विवरण |
|------|------|------|------|------|
| page | int | नहीं | 1 | पृष्ठ संख्या |
| limit | int | नहीं | 15 | प्रति पृष्ठ संख्या |
| group | string | नहीं | | कॉन्फ़िगरेशन समूह के अनुसार फ़िल्टर |

**प्रतिक्रिया उदाहरण**:
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

| फ़ील्ड | प्रकार | विवरण |
|------|------|------|
| id | string | hashid |
| group | string | कॉन्फ़िगरेशन समूह (जैसे `system`, `email`, `storage`) |
| key | string | कॉन्फ़िगरेशन कुंजी |
| value | string | कॉन्फ़िगरेशन मान |
| type | string | मान प्रकार संकेत (`string`, `integer`, `boolean`, `json` आदि) |
| description | string | कॉन्फ़िगरेशन विवरण |

### 8.2 कॉन्फ़िगरेशन बनाएं

```
POST /admin/config
```

- **प्रमाणीकरण**: JWT + RBAC

**अनुरोध बॉडी**:
```json
{
  "group": "email",
  "key": "smtp_host",
  "value": "smtp.example.com",
  "type": "string",
  "description": "SMTP 服务器地址"
}
```

| फ़ील्ड | प्रकार | आवश्यक | सत्यापन नियम | विवरण |
|------|------|------|---------|------|
| group | string | हाँ | max:100 | कॉन्फ़िगरेशन समूह |
| key | string | हाँ | max:100 | कॉन्फ़िगरेशन कुंजी (समान समूह में अद्वितीय) |
| value | string | हाँ | | कॉन्फ़िगरेशन मान |
| type | string | नहीं | | मान प्रकार, डिफ़ॉल्ट `string` |
| description | string | नहीं | | कॉन्फ़िगरेशन विवरण, डिफ़ॉल्ट खाली |

**प्रतिक्रिया उदाहरण**:
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

**संभावित त्रुटियाँ**:
- 422: कॉन्फ़िगरेशन आइटम पहले से मौजूद (समान group + key)

### 8.3 कॉन्फ़िगरेशन अपडेट करें

```
PUT /admin/config/{id}
```

- **प्रमाणीकरण**: JWT + RBAC

**अनुरोध बॉडी**:
```json
{
  "value": "smtp.newhost.com",
  "type": "string",
  "description": "更新后的 SMTP 地址"
}
```

| फ़ील्ड | प्रकार | आवश्यक | विवरण |
|------|------|------|------|
| value | string | नहीं | कॉन्फ़िगरेशन मान अपडेट करें |
| type | string | नहीं | मान प्रकार अपडेट करें |
| description | string | नहीं | विवरण पाठ अपडेट करें |

### 8.4 कॉन्फ़िगरेशन हटाएं

```
DELETE /admin/config/{id}
```

- **प्रमाणीकरण**: JWT + RBAC
- **संवेदनशील ऑपरेशन**: पासवर्ड पुनः पुष्टि आवश्यक

**अनुरोध बॉडी**:
```json
{
  "password": "admin_password"
}
```

कॉन्फ़िगरेशन रिकॉर्ड को भौतिक रूप से हटाता है।

## 9. ऑपरेशन लॉग

ऑपरेशन लॉग केवल-पठनीय इंटरफ़ेस है; `OperationLog` मिडलवेयर प्रत्येक POST/PUT/DELETE अनुरोध पर स्वतः लिखता है, संग्रहीत फ़ील्ड में `user_id`, `action`, `method`, `path`, `ip`, `source`, `input` शामिल हैं।

### 9.1 ऑपरेशन लॉग सूची

```
GET /admin/log
```

- **प्रमाणीकरण**: JWT + RBAC

**क्वेरी पैरामीटर**:

| पैरामीटर | प्रकार | आवश्यक | डिफ़ॉल्ट मान | विवरण |
|------|------|------|------|------|
| page | int | नहीं | 1 | पृष्ठ संख्या |
| limit | int | नहीं | 15 | प्रति पृष्ठ संख्या |
| user_id | int | नहीं | | उपयोगकर्ता ID द्वारा सटीक फ़िल्टर (मूल INT प्रकार) |
| action | string | नहीं | | ऑपरेशन एक्शन द्वारा सटीक फ़िल्टर |
| path | string | नहीं | | अनुरोध पाथ द्वारा फ़ज़ी फ़िल्टर |
| start_date | string | नहीं | | आरंभ तिथि (Y-m-d प्रारूप) |
| end_date | string | नहीं | | समाप्ति तिथि (Y-m-d प्रारूप) |

**प्रतिक्रिया उदाहरण**:
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

| फ़ील्ड | प्रकार | विवरण |
|------|------|------|
| id | string | hashid |
| user_name | string | ऑपरेटिंग उपयोगकर्ता नाम (user संबंध से प्राप्त; बिना लॉगिन ऑपरेशन "系统" दिखाता है) |
| action | string | ऑपरेशन एक्शन विवरण |
| method | string | HTTP विधि (POST/PUT/DELETE) |
| path | string | अनुरोध पाथ |
| ip | string | क्लाइंट IP |
| source | string | अनुरोध स्रोत |
| input | string | अनुरोध पैरामीटर JSON स्ट्रिंग (फ़ाइलें शामिल नहीं) |
| created_at | string | ऑपरेशन समय (datetime) |

## 10. प्रोफ़ाइल केंद्र

प्रोफ़ाइल केंद्र एंडपॉइंट्स को केवल JWT प्रमाणीकरण चाहिए (RBAC अनुमति जाँच की आवश्यकता नहीं — `AdminPermission` मिडलवेयर को इन्हें व्हाइटलिस्ट में जोड़ना चाहिए)।

### 10.1 व्यक्तिगत जानकारी अपडेट करें

```
PUT /admin/profile
```

- **प्रमाणीकरण**: JWT

**अनुरोध बॉडी**:
```json
{
  "real_name": "新姓名",
  "phone": "13800138000",
  "email": "me@example.com"
}
```

| फ़ील्ड | प्रकार | आवश्यक | विवरण |
|------|------|------|------|
| real_name | string | नहीं | वास्तविक नाम |
| phone | string | नहीं | फ़ोन नंबर (Encryptable एन्क्रिप्टेड संग्रहीत) |
| email | string | नहीं | ईमेल (Encryptable एन्क्रिप्टेड संग्रहीत) |

**प्रतिक्रिया उदाहरण**:
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

प्रतिक्रिया में `phone` और `email` प्लेनटेक्स्ट लौटाए जाते हैं; `password` और `id_card` हटा दिए गए हैं।

### 10.2 पासवर्ड बदलें

```
PUT /admin/profile/password
```

- **प्रमाणीकरण**: JWT

**अनुरोध बॉडी**:
```json
{
  "old_password": "current_pass",
  "new_password": "new_pass_123"
}
```

| फ़ील्ड | प्रकार | आवश्यक | सत्यापन नियम | विवरण |
|------|------|------|---------|------|
| old_password | string | हाँ | | वर्तमान पासवर्ड |
| new_password | string | हाँ | min:6, max:32 | नया पासवर्ड |

**प्रतिक्रिया उदाहरण**:
```json
{
  "code": 0,
  "message": "密码修改成功",
  "data": []
}
```

**संभावित त्रुटियाँ**:
- 422: कृपया पुराना और नया पासवर्ड भरें
- 422: पुराना पासवर्ड गलत
- 422: नए पासवर्ड की लंबाई 6-32 अक्षर होनी चाहिए

### 10.3 लॉगआउट

```
POST /admin/profile/logout
```

- **प्रमाणीकरण**: JWT

**अनुरोध बॉडी**: नहीं (कोई requestBody नहीं, टोकन Authorization हेडर से पढ़ा जाता है)

**प्रतिक्रिया उदाहरण**:
```json
{
  "code": 0,
  "message": "已登出",
  "data": []
}
```

लॉगआउट तर्क: JWT डिकोड करके शेष वैधता (exp - now) प्राप्त करें, उस टोकन के md5 हैश को Redis ब्लैकलिस्ट `jwt_blacklist:{md5}` में लिखें, TTL = शेष वैधता। ब्लैकलिस्ट में टोकन `AdminAuth` मिडलवेयर में रोके जाते हैं और 401 लौटाते हैं।

टोकन न होने पर 401 लौटता है। समाप्त/अमान्य टोकन (डिकोडिंग में अपवाद) को फिर भी सफल लॉगआउट माना जाता है।

## 11. आयात/निर्यात

### 11.1 Excel निर्यात

```
POST /admin/export/excel
```

- **प्रमाणीकरण**: JWT + RBAC
- **प्रतिक्रिया प्रकार**: फ़ाइल डाउनलोड (`application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`)

**अनुरोध बॉडी**:
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

| फ़ील्ड | प्रकार | आवश्यक | डिफ़ॉल्ट मान | विवरण |
|------|------|------|------|------|
| table | string | नहीं | `admin_user` | निर्यात तालिका नाम। समर्थित: `admin_user`, `operation_log`, `admin_role`, `system_config` |
| columns | array{string} | नहीं | | निर्यात कॉलम फ़ील्ड नामों की सरणी; खाली होने पर तालिका के सभी कॉलम निर्यात होते हैं |
| conditions | object | नहीं | `{}` | फ़िल्टर शर्तें, key-value जोड़े; मान खाली न होने पर WHERE के लिए उपयोग |
| title | string | नहीं | `数据导出` | Excel शीर्षक (Sheet नाम के रूप में दिखाया जाता है) |

**समर्थित तालिकाएँ और कॉलम**:

| table | उपलब्ध कॉलम |
|-------|-------|
| `admin_user` | id, username, real_name, phone, email, status, last_login_at, last_login_ip, created_at |
| `operation_log` | id, user_id, action, method, path, ip, created_at |
| `admin_role` | id, name, slug, description, status, created_at |
| `system_config` | id, group, key, value, type, description, created_at |

संवेदनशील फ़ील्ड `phone`, `email`, `id_card` निर्यात के समय स्वतः मास्क होते हैं। डेटा सीमा 10000 पंक्तियाँ। Excel की पहली पंक्ति फ़्रीज़ होती है और स्वतः फ़िल्टर सक्रिय होता है।

### 11.2 PDF निर्यात

```
POST /admin/export/pdf
```

- **प्रमाणीकरण**: JWT + RBAC
- **प्रतिक्रिया प्रकार**: फ़ाइल डाउनलोड (`application/pdf`, A4 लैंडस्केप)

**अनुरोध बॉडी**:
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

या टेबल मोड:
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

| फ़ील्ड | प्रकार | आवश्यक | डिफ़ॉल्ट मान | विवरण |
|------|------|------|------|------|
| type | string | नहीं | `table` | निर्यात प्रकार: `table` / `dashboard` |
| title | string | नहीं | `数据导出` | PDF शीर्षक |
| data | object | नहीं | `{}` | निर्यात डेटा |

जब `type=dashboard`, `data` में `stats` सरणी होनी चाहिए (कार्ड रूप में रेंडर); जब `type=table`, `data` में `columns` और `rows` सरणियाँ होनी चाहिए।

PDF टेम्पलेट में कॉपीराइट जानकारी और निर्यात टाइमस्टैम्प शामिल है।

### 11.3 उपयोगकर्ता आयात करें (Excel)

```
POST /admin/import/users
```

- **प्रमाणीकरण**: JWT + RBAC
- **अनुरोध प्रकार**: `multipart/form-data` (फ़ाइल अपलोड)

**फ़ॉर्म फ़ील्ड**:

| फ़ील्ड | प्रकार | आवश्यक | विवरण |
|------|------|------|------|
| file | file | हाँ | `.xlsx` या `.xls` प्रारूप |

**Excel कॉलम आवश्यकताएँ**:

| कॉलम नाम | आवश्यक | विवरण |
|------|------|------|
| username | हाँ | उपयोगकर्ता नाम (अद्वितीय) |
| password | हाँ | पासवर्ड (bcrypt हैश संग्रहीत) |
| real_name | हाँ | वास्तविक नाम |
| phone | नहीं | फ़ोन नंबर |
| email | नहीं | ईमेल |
| status | नहीं | स्थिति, डिफ़ॉल्ट 1 |

पंक्ति 1 कॉलम शीर्षक है (केस-असंवेदनशील); डेटा पंक्ति 2 से शुरू होता है।

**प्रतिक्रिया उदाहरण**:
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

| फ़ील्ड | प्रकार | विवरण |
|------|------|------|
| total | int | कुल पंक्तियाँ (शीर्षक पंक्ति को छोड़कर) |
| success | int | सफलतापूर्वक आयात की गई संख्या |
| failed | int | विफल संख्या |
| errors | array | विफलता विवरण, प्रत्येक में row (Excel पंक्ति संख्या) और reason (विफलता कारण) |

## 12. फ़ाइल अपलोड

```
POST /admin/upload
```

- **प्रमाणीकरण**: JWT + RBAC
- **अनुरोध प्रकार**: `multipart/form-data`

**फ़ॉर्म फ़ील्ड**:

| फ़ील्ड | प्रकार | आवश्यक | विवरण |
|------|------|------|------|
| file | file | हाँ | अपलोड की जाने वाली फ़ाइल |

**अनुमत फ़ाइल प्रकार**: `jpg`, `jpeg`, `png`, `gif`, `pdf`, `xlsx`, `docx`
**अधिकतम फ़ाइल आकार**: 10MB

**प्रतिक्रिया उदाहरण**:
```json
{
  "code": 0,
  "message": "上传成功",
  "data": {
    "url": "/upload/2026-05-21/abc123def456.png"
  }
}
```

फ़ाइलें तिथि के अनुसार `public/upload/{Y-m-d}/` में संग्रहीत होती हैं, फ़ाइल नाम `md5(uniqid) + मूल एक्सटेंशन` है। `url` साइट रूट के सापेक्ष पाथ है।

**संभावित त्रुटियाँ**:
- 422: कृपया फ़ाइल चुनें (अपलोड नहीं हुई)
- 422: असमर्थित फ़ाइल प्रकार
- 422: फ़ाइल आकार 10MB से अधिक नहीं हो सकता
- 500: फ़ाइल अपलोड विफल (फ़ाइल अमान्य)

## 13. प्रतिक्रिया हेडर

सभी एंडपॉइंट्स (वैश्विक मिडलवेयर परत में इंजेक्ट) में निम्न प्रतिक्रिया हेडर शामिल होते हैं:

| हेडर | विवरण |
|----|------|
| `X-RateLimit-Limit` | रेट लिमिट सीमा (संख्या) |
| `X-RateLimit-Remaining` | शेष अनुरोध संख्या |
| `X-RateLimit-Reset` | रेट लिमिट विंडो रीसेट टाइमस्टैम्प |
| `Retry-After` | केवल रेट लिमिट ट्रिगर होने पर लौटता है, प्रतीक्षा के लिए सुझाए गए सेकंड |
| `X-Content-Type-Options` | `nosniff` (webman डिफ़ॉल्ट, MIME स्निफिंग प्रतिबंधित) |
| `X-Frame-Options` | `DENY` (webman के CORS मिडलवेयर/आधार कॉन्फ़िगरेशन द्वारा प्रदान) |

रेट लिमिट विवरण:
- डिफ़ॉल्ट वैश्विक सीमा: 60 बार/मिनट / IP+पाथ
- लॉगिन एंडपॉइंट `/api/auth/login`: 10 बार/मिनट
- पंजीकरण एंडपॉइंट `/api/auth/register`: 5 बार/मिनट
- Redis परमाणु स्लाइडिंग विंडो एल्गोरिदम (Lua ZSET) का उपयोग, TOCTOU रेस से बचाव
- Redis अनुपलब्ध होने पर fail open (अनुमति), अनुरोधों को अवरुद्ध नहीं करता

## 14. प्रमाणीकरण प्रक्रिया

पूर्ण प्रमाणीकरण अनुक्रम:

```
1. क्लाइंट POST /api/captcha/generate का अनुरोध करता है
   (अनुरोध हेडर: API-Version: v1)
    ↓
   सर्वर लौटाता है: key + type(click|slider|rotate) + base64 छवि + extra(प्रकार-संबंधित डेटा)
   
2. उपयोगकर्ता कैप्चा ऑपरेशन पूरा करता है (क्लिक/ड्रैग/रोटेट), क्लाइंट उत्तर एकत्र करता है
   
3. क्लाइंट POST /api/captcha/verify का अनुरोध करता है
   (अनुरोध हेडर: API-Version: v1, Content-Type: application/json)
   अनुरोध बॉडी: { key, type, clicks }
   - type=click:  clicks = [{x, y}, ...]        // निर्देशांक सरणी
   - type=slider: clicks = 120                   // X ऑफ़सेट
   - type=rotate: clicks = 315                   // रोटेशन कोण
    ↓
   सर्वर:
   a. स्टोरेज से captcha:key डेटा पढ़ें (TTL 300 सेकंड)
   b. type के अनुसार उत्तर सत्यापित करें (click: यूक्लिडियन दूरी ≤18px / slider: ±4px / rotate: ±5°)
   c. सत्यापन पास → Redis में `captcha_verified:{key}` = 1 लिखें (TTL 300 सेकंड)
   d. सत्यापन विफल → 422 लौटाएं, गिनती +1, 3 बार से अधिक पर key रद्द
    ↓
   सर्वर लौटाता है: { valid: true/false }

4. क्लाइंट POST /api/auth/login का अनुरोध करता है
   (अनुरोध हेडर: API-Version: v1, Content-Type: application/json)
   अनुरोध बॉडी: { username, password(एन्क्रिप्टेड), captcha_key }
    ↓
   सर्वर:
   a. पैरामीटर सत्यापन → 422
   b. captcha_verified:{key} मौजूद है या नहीं जाँचें → 422
   c. captcha_verified:{key} हटाएं (एक-बार उपयोग)
   d. पासवर्ड डिक्रिप्ट करें: EncryptionService::decrypt(password) → प्लेनटेक्स्ट
   e. उपयोगकर्ता क्रेडेंशियल सत्यापित करें (password_verify) → 401
   f. खाता स्थिति जाँचें → 403/429
   g. JWT जारी करें (access + refresh) → 200
   h. last_login_at / last_login_ip अपडेट करें
    ↓
   क्लाइंट सहेजता है: access_token, refresh_token, expires_in

5. बाद के अनुरोध JWT ले जाते हैं
   अनुरोध हेडर: Authorization: Bearer <access_token>
    ↓
AdminAuth मिडलवेयर:
   a. Bearer टोकन निकालें
   b. ब्लैकलिस्ट जाँचें (Redis jwt_blacklist:{md5}) → 401
   c. JWT डिकोड करें, समाप्ति सत्यापित करें → 401
   d. $request->adminId = sub फ़ील्ड सेट करें
    ↓
AdminPermission मिडलवेयर:
   a. संसाधन रूट के लिए अनुमति पहचानकर्ता पार्स करें
   b. उपयोगकर्ता भूमिकाएँ क्वेरी करें → भूमिका अनुमतियाँ, मिलान करें
   c. कोई अनुमति नहीं → 403
    ↓
Controller अनुरोध संसाधित करता है
    ↓
Response + X-RateLimit-* हेडर

6. एक्सेस टोकन समाप्त होने से पहले रिफ्रेश करें
   क्लाइंट POST /api/auth/refresh का अनुरोध करता है
   अनुरोध बॉडी: { refresh_token: "..." }
    ↓
   सर्वर refresh_token डिकोड करता है → नया access + refresh जारी करता है
    ↓
   क्लाइंट स्थानीय टोकन अपडेट करता है

7. लॉगआउट
   क्लाइंट POST /admin/profile/logout का अनुरोध करता है
   अनुरोध हेडर: Authorization: Bearer <access_token>
    ↓
   सर्वर:
   a. JWT डिकोड करके शेष TTL प्राप्त करें
   b. Redis ब्लैकलिस्ट में लिखें: jwt_blacklist:{md5(token)} = 1, TTL = शेष वैधता
   c. सफलता लौटाएं
```

### JWT संरचना

- **access_token**: `{ sub: <user_id>, username: "<name>" }`, डिफ़ॉल्ट TTL 7200 सेकंड (JWT कॉन्फ़िगरेशन `default_expire` द्वारा नियंत्रित)
- **refresh_token**: `{ sub: <user_id>, token_type: "refresh" }`, डिफ़ॉल्ट TTL 1209600 सेकंड (JWT कॉन्फ़िगरेशन `refresh_expire` द्वारा नियंत्रित, यानी 14 दिन)

### सुरक्षा प्रबंधन

- पासवर्ड `PASSWORD_BCRYPT` हैश में संग्रहीत होते हैं
- पासवर्ड ट्रांसपोर्ट परत AES-256-CBC-HMAC एन्क्रिप्शन का उपयोग करती है (क्लाइंट एन्क्रिप्ट → सर्वर डिक्रिप्ट), प्लेनटेक्स्ट फ़ॉलबैक संगत
- संवेदनशील फ़ील्ड (phone, email, id_card) डेटाबेस परत में `erikwang2013/encryptable` द्वारा पारदर्शी रूप से एन्क्रिप्ट/डिक्रिप्ट होते हैं
- API परत ID `erikwang2013/hashids` द्वारा एन्क्रिप्टेड ट्रांसमिशन होती हैं, मूल snowflake ID अनुक्रम उजागर होने से बचाव
- SecurityFilter XSS, SQL इंजेक्शन, पाथ ट्रैवर्सल, कमांड इंजेक्शन को वैश्विक रूप से स्कैन करता है; समान IP 5 बार/60 सेकंड पर 15 मिनट अस्थायी ब्लैकलिस्ट
- संवेदनशील ऑपरेशन (उपयोगकर्ता, भूमिका, अनुमति, कॉन्फ़िगरेशन हटाना) के लिए वर्तमान लॉगिन उपयोगकर्ता के पासवर्ड की पुनः पुष्टि आवश्यक
- समवर्ती सत्र सीमा: एक उपयोगकर्ता अधिकतम 3 वैध टोकन; चौथा डिवाइस लॉगिन करने पर सबसे पुराना टोकन ब्लैकलिस्ट में डाल दिया जाता है
- खाता लॉक: लगातार 5 लॉगिन विफलताओं पर 15 मिनट खाता लॉक; लॉक अवधि के दौरान 429 लौटाता है

## 15. डिप्लॉयमेंट और ऑपरेशन

### Docker Compose

प्रोजेक्ट रूट निर्देशिका `docker-compose.yml` प्रदान करती है, जो 5 सेवाओं (Nginx, webman ऐप, MySQL, Redis, Elasticsearch) को ऑर्केस्ट्रेट करती है। PHP `Dockerfile` से बनता है (आधार `php:8.3-cli`, OPcache सक्षम)।

```bash
cp .env.docker .env
docker-compose up -d
```

### CI/CD

`.github/workflows/ci.yml` GitHub Actions निरंतर एकीकरण पाइपलाइन परिभाषित करता है:
- `php -l` सिंटैक्स जाँच
- PHPUnit यूनिट टेस्ट
- `flutter analyze` स्थैतिक विश्लेषण

### डेटाबेस बैकअप

`database/backup/` निर्देशिका बैकअप और पुनर्स्थापना स्क्रिप्ट प्रदान करती है:
- `backup.sh` — mysqldump + gzip संपीड़न बैकअप, 30 दिन से पुरानी बैकअप फ़ाइलें स्वतः साफ़
- `restore.sh` — इंटरैक्टिव पुनर्स्थापना, उपयोगकर्ता चयन के लिए मौजूदा बैकअप सूचीबद्ध करता है

### Nginx सुरक्षा कॉन्फ़िगरेशन

उत्पादन परिनियोजन के लिए `docs/nginx-security.conf` देखें, reverse proxy सुरक्षा सुदृढ़ीकरण कॉन्फ़िगरेशन के लिए।
