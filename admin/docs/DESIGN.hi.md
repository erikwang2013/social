# ओपन एडमिन कंसोल — डिज़ाइन दस्तावेज़

**语言 / Languages:** [中文](DESIGN.md) · [English](DESIGN.en.md) · [한국어](DESIGN.ko.md) · [Русский](DESIGN.ru.md) · [Deutsch](DESIGN.de.md) · [Français](DESIGN.fr.md) · [Español](DESIGN.es.md) · [Português](DESIGN.pt.md) · [हिन्दी](DESIGN.hi.md) · [العربية](DESIGN.ar.md) · [বাংলা](DESIGN.bn.md) · [Bahasa Indonesia](DESIGN.id.md) · [日本語](DESIGN.ja.md)

> Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz

> विस्तृत Mermaid आर्किटेक्चर आरेख के लिए [ARCHITECTURE.md](ARCHITECTURE.md) देखें (GitHub/GitLab/VS Code में स्वचालित रेंडरिंग)।

## 1. सिस्टम आर्किटेक्चर

> **सुविधा सूची**: प्रमाणीकरण (login/register/refresh/logout + खाता लॉक + सत्र सीमा) | डैशबोर्ड (Redis कैश) | उपयोगकर्ता CRUD+बल्क+इंपोर्ट | रोल अनुमतियाँ (RBAC) | सिस्टम कॉन्फ़िगरेशन | संचालन ऑडिट (8 प्लेटफ़ॉर्म स्रोत) | फ़ाइलें (अपलोड+निर्यात+मास्किंग) | सुरक्षा (18-परत रक्षा) | संचालन (health/metrics/docs/Docker/CI)

```
┌──────────────────────────────────────────────────────────────┐
│                        客户端层                               │
│  ┌──────────────────────┐  ┌──────────────────────────────┐  │
│  │  Flutter Web (PC)    │  │  HarmonyOS ArkTS (Mobile)    │  │
│  │  管理后台 (桌面风格)   │  │  客户端 (手机/平板/2in1)      │  │
│  └──────────┬───────────┘  └──────────────┬───────────────┘  │
└─────────────┼──────────────────────────────┼─────────────────┘
              │        HTTPS / JSON          │
              │   Authorization: Bearer JWT  │
┌─────────────┼──────────────────────────────┼─────────────────┐
│             ▼                              ▼                  │
│  ┌──────────────────────────────────────────────────────┐    │
│  │                   API 网关层                          │    │
│  │  AdminAuth(认证) → AdminPermission(授权) → Controller │    │
│  └──────────────────────────┬───────────────────────────┘    │
│                             │                                  │
│  ┌──────────────────────────┼───────────────────────────┐    │
│  │              业务逻辑层 (Controller/Service)           │    │
│  │  ┌──────────┐ ┌──────────┐ ┌──────────┐ ┌─────────┐ │    │
│  │  │Dashboard │ │  User    │ │  Role    │ │ Export  │ │    │
│  │  │Controller│ │Controller│ │Controller│ │Controller│ │    │
│  │  └────┬─────┘ └────┬─────┘ └────┬─────┘ └────┬────┘ │    │
│  └───────┼────────────┼─────────────┼────────────┼──────┘    │
│          │            │             │            │            │
│  ┌───────┼────────────┼─────────────┼────────────┼──────┐    │
│  │       ▼            ▼             ▼            ▼       │    │
│  │                   Model 层                            │    │
│  │  ┌──────────────────────────────────────────────┐    │    │
│  │  │  Snowflake ID ← encryptable → Encryption     │    │    │
│  │  │  (主键生成)     (DB字段加密)   (API传输加密)    │    │    │
│  │  └──────────────────────────────────────────────┘    │    │
│  └──────────────────────────┬───────────────────────────┘    │
│                             │                                  │
│  ┌──────────────────────────┼───────────────────────────┐    │
│  │              数据存储层                                │    │
│  │  ┌──────────┐  ┌──────────────┐  ┌──────────┐        │    │
│  │  │  MySQL   │  │ Elasticsearch│  │  Redis   │        │    │
│  │  │ (主存储)  │  │ (全文检索)    │  │ (缓存)   │        │    │
│  │  └──────────┘  └──────────────┘  └──────────┘        │    │
│  └──────────────────────────────────────────────────────┘    │
│                       webman v2                               │
└──────────────────────────────────────────────────────────────┘
```

## 2. बैकएंड आर्किटेक्चर

### 2.1 परत-आधारित डिज़ाइन

| परत | निर्देशिका | ज़िम्मेदारी |
|------|------|------|
| रूट | `config/route.php` | URL से कंट्रोलर मैपिंग, मिडलवेयर बाइंडिंग, संस्करणित रूट |
| मिडलवेयर | `app/middleware/` | आक्रमण अवरोधन (SecurityFilter), रेट लिमिट (RateLimit), प्रमाणीकरण (JWT), प्राधिकरण (RBAC), API संस्करण (ApiVersion) |
| कंट्रोलर | 14: Dashboard/User/Role/Permission/Config/Log/Profile/Export/Import/Upload/Health/Docs (एडमिन एंड) + Captcha/Auth (API v1) | अनुरोध पैरामीटर सत्यापन, व्यावसायिक तर्क कॉल, रिस्पॉन्स फ़ॉर्मेटिंग |
| व्यावसायिक सेवाएँ | `app/service/` | पुन: प्रयोज्य व्यावसायिक तर्क (आरक्षित) |
| डेटा मॉडल | `app/model/` | ORM मैपिंग, संबंध, फ़ील्ड एन्क्रिप्ट/डिक्रिप्ट |
| सामान्य उपकरण | `app/common/` | Hashids, Snowflake, Encryption सेवाएँ |

### 2.2 अनुरोध जीवनचक्र

```
客户端请求
  │
  ▼
webman HTTP Server (workerman)
  │
  ▼
Route 匹配
  │
  ▼
中间件链:
  Locale ──────────────► Accept-Language / ?lang= 语言检测
  │
  ▼
SecurityFilter ──────► HTTP方法检查 → 405 (仅允许 GET/POST/PUT/DELETE/OPTIONS/HEAD)
  │                     XSS/SQL注入/路径遍历/命令注入/CSRF 攻击拦截 (403)
  ▼
RateLimit ───────────► Redis 滑动窗口限流
  │ (失败返回 429 + Retry-After 头)
  ▼
ApiVersion ─────────► API-Version 头校验，注入 $request->apiVersion
  │ (失败返回 400)
  ▼
AdminAuth ──────────► JWT 验证，注入 $request->adminId
  │ (失败返回 401)
  ▼
AdminPermission ────► RBAC 权限校验（Redis 60s 缓存）
  │ (失败返回 403)
  ▼
OperationLog ───────► 操作日志记录 (POST/PUT/DELETE)，自动检测来源端
  │
  ▼
Controller::method()
  │
  ├─► 参数验证 (validator)
  ├─► 敏感操作确认 (confirmPassword)
  ├─► decodeId() — hashid → BIGINT
  ├─► Model 操作 (自动 encryptable 加解密)
  ├─► encodeId() — BIGINT → hashid
  └─► Response JSON
```

### 2.3 ID जीवनचक्र

```
生成 (Snowflake) → 存储 (MySQL BIGINT) → 传输 (Hashids 编码) → 外部 (hash 字符串)
                                                                    │
                            HashidsService::decode() ←──────────────┘
```

### 2.4 डेटा एन्क्रिप्शन प्रणाली

```
传输层 (encryption)     — AES-256-CBC，独立密钥
存储层 (encryptable)    — AES-128-ECB，独立密钥，Model $casts 自动处理
展示层 (mask)           — 手机号: 138****1234，邮箱: a***@example.com
```

## 3. डेटाबेस डिज़ाइन

### 3.1 ER संबंध

```
erik_admin_user ──┬── erik_admin_user_role ──┬── erik_admin_role
  (用户)           │    (用户-角色关联)         │     (角色)
                  │                          │
                  │                    erik_admin_role_permission
                  │                     (角色-权限关联)
                  │                          │
                  │                          ▼
                  │                    erik_admin_permission
                  │                      (权限/菜单)
                  │
                  ▼
           erik_operation_log
             (操作日志)

erik_system_config (系统配置) — 独立表
```

### 3.2 मुख्य तालिका संरचना

| तालिका नाम | फ़ील्ड संख्या | विवरण |
|------|-------|------|
| `erik_admin_user` | 14 | एडमिन उपयोगकर्ता, phone/email/id_card एन्क्रिप्टेड संग्रहीत, सॉफ्ट डिलीट समर्थित |
| `erik_admin_role` | 7 | रोल, slug अद्वितीय |
| `erik_admin_permission` | 10 | अनुमति ट्री (parent_id स्व-संदर्भित), type: 1=मेनू 2=बटन 3=API |
| `erik_admin_user_role` | 2 | उपयोगकर्ता-रोल मैनी-टू-मैनी मध्यवर्ती तालिका |
| `erik_admin_role_permission` | 2 | रोल-अनुमति मैनी-टू-मैनी मध्यवर्ती तालिका |
| `erik_system_config` | 8 | कुंजी-मान कॉन्फ़िगरेशन, group+key संयुक्त अद्वितीय |
| `erik_operation_log` | 9 | संचालन ऑडिट लॉग (source स्रोत फ़ील्ड सहित) |

### 3.3 प्राथमिक कुंजी मानक

- प्रकार: `BIGINT UNSIGNED NOT NULL`
- विशेषता: **गैर-ऑटोइंक्रीमेंट**, एप्लिकेशन परत में Snowflake एल्गोरिदम द्वारा उत्पन्न
- लाभ: वैश्विक रूप से अद्वितीय, वितरित-अनुकूल, ट्रेंड-इंक्रीमेंटल इंडेक्स के लिए अनुकूल, व्यावसायिक मात्रा उजागर नहीं करता
- कॉन्फ़िगरेशन: datacenter_id(0-31) + worker_id(0-31), 1024 नोड्स समवर्ती समर्थित

## 4. API डिज़ाइन

### 4.1 URL मानक

```
公开接口:  /api/captcha/{generate|verify}
           /api/auth/{login|register|refresh}

管理端:   /admin/{resource}[/{hashid}]
          /admin/export/{excel|pdf}

资源路由:
  GET    /admin/user          → 列表
  POST   /admin/user          → 创建
  GET    /admin/user/{hashid} → 详情
  PUT    /admin/user/{hashid} → 更新
  DELETE /admin/user/{hashid} → 删除（需密码确认）

系统配置:  /admin/config[/{hashid}]
操作日志:  /admin/log
个人中心:  /admin/profile[/password|/logout]
导入:     /admin/import/users
上传:     /admin/upload
批量:     /admin/user/batch/{destroy|status}
文档:     /api/docs     (OpenAPI 3.0)
健康:     /health
```

### 4.2 API संस्करण नीति

API संस्करण अनुरोध हेडर से नियंत्रित होता है, **URL पथ में प्रदर्शित नहीं होता**:

```http
API-Version: v1
```

| तंत्र | विवरण |
|------|------|
| डिफ़ॉल्ट संस्करण | `API-Version` हेडर न होने पर डिफ़ॉल्ट `v1` |
| सत्यापन | `ApiVersion` मिडलवेयर सत्यापन, असमर्थित संस्करण 400 लौटाता है |
| रूट | `v()` सहायक फ़ंक्शन संस्करण के अनुसार कंट्रोलर क्लास गतिशील रूप से हल करता है |
| निर्देशिका | कंट्रोलर संस्करण के अनुसार व्यवस्थित: `app/api/{version}/controller/` |

विस्तार उदाहरण — नई v2 API जोड़ना:
1. `app/api/v2/controller/AuthController.php` बनाएँ
2. `ApiVersion` मिडलवेयर के `SUPPORTED` स्थिरांक में `'v2'` जोड़ें
3. रूट परिभाषाएँ संशोधित करने की आवश्यकता नहीं

```bash
# 使用 v1
curl -H "API-Version: v1" /api/auth/login

# 使用 v2
curl -H "API-Version: v2" /api/auth/login

# 不传，默认 v1
curl /api/auth/login
```

### 4.3 रेट लिमिटिंग नीति

Redis Sorted Set स्लाइडिंग विंडो एल्गोरिदम पर आधारित, एटॉमिक Lua स्क्रिप्ट निष्पादन:

| इंटरफ़ेस | सीमा |
|------|------|
| डिफ़ॉल्ट | 60 बार/मिनट/IP/रूट |
| POST /api/auth/login | 10 बार/मिनट |
| POST /api/auth/register | 5 बार/मिनट |

सीमा पार करने पर 429 लौटता है, रिस्पॉन्स हेडर में X-RateLimit-Limit / Remaining / Reset / Retry-After शामिल।

### 4.4 एकीकृत रिस्पॉन्स

```json
{
  "code": 0,
  "message": "success",
  "data": { ... }
}
```

| code | अर्थ | ट्रिगर परिदृश्य |
|------|------|---------|
| 0 | सफल | सामान्य रिस्पॉन्स |
| 400 | पैरामीटर त्रुटि | अनुरोध प्रारूप गलत |
| 401 | प्रमाणीकृत नहीं | Token अनुपस्थित/समाप्त/अमान्य |
| 403 | अनुमति नहीं | उपयोगकर्ता के रोल में आवश्यक अनुमति शामिल नहीं |
| 404 | मौजूद नहीं | संसाधन नहीं मिला |
| 422 | सत्यापन विफल | फ़ॉर्म पैरामीटर नियमों के अनुरूप नहीं / पासवर्ड पुष्टि विफल |
| 500 | सर्वर त्रुटि | अप्रत्याशित अपवाद |

### 4.5 प्रमाणीकरण प्रक्रिया (क्लिक कैप्चा सहित)

```
客户端                               服务端
  │                                    │
  │  ① POST /api/captcha/generate     │ captcha_create('click')
  │◄── {key, image(base64), targets}  │
  │                                    │
  │  ② 用户点击图中文字位置              │
  │                                    │
  │  ③ POST /api/auth/login           │
  │     {username, password,          │
  │      captcha_key, clicks}         │
  │────────────────────────────────►  │
  │                                    │ ① captcha_verify()
  │                                    │ ② password_verify()
  │                                    │ ③ jwt()->create()
  │◄── {access_token, refresh_token}  │
  │                                    │
  │  ④ GET /admin/dashboard           │
  │     Authorization: Bearer xxx     │
  │────────────────────────────────►  │ AdminAuth → AdminPermission
  │◄── 200 {dashboard data}           │
```

### 4.6 अनुमति मॉडल (RBAC)

```
  用户 ──┬── 角色 ──┬── 权限
  User     Role      Permission
                 │
                 ├── type=1: 菜单 (控制侧边栏可见)
                 ├── type=2: 按钮 (控制页面内操作)
                 └── type=3: API  (控制接口访问)

  权限标识格式: {method}.{path}
  例: get.admin/user  post.admin/user  delete.admin/user
  超级管理员标识: * (跳过所有权限检查)
```

### 4.7 संवेदनशील संचालन द्वितीयक पुष्टि

उपयोगकर्ता, रोल, अनुमति हटाने जैसे संवेदनशील संचालनों में, अनुरोध बॉडी में वर्तमान उपयोगकर्ता का पासवर्ड भेजकर पहचान पुनः सत्यापित करनी होती है:

```
客户端                           服务端
  │                                │
  │  DELETE /admin/user/{hashid}  │
  │  { password: "******" }       │
  │────────────────────────────►  │
  │                                │ confirmPassword(adminId, password)
  │                                │ → 密码错误返回 422
  │                                │ → 密码正确继续执行
  │◄── 200 { code: 0 }           │
```

फ्रंटएंड डिलीट संचालन ट्रिगर करने से पहले पुष्टिकरण डायलॉग दिखाता है, उपयोगकर्ता का पासवर्ड एकत्र कर अनुरोध भेजता है।

## 5. फ्रंटएंड डिज़ाइन

### 5.1 Flutter Web एडमिन पैनल

```
┌────────────────────────────────────────────────┐
│  Header (56px)                                 │
│  ☰ 菜单按钮           🔔 消息  👤 管理员  ▼    │
├──────────┬─────────────────────────────────────┤
│ Sidebar  │  Content Area                       │
│ (64/240) │                                     │
│          │  ┌──────────────┐ ┌──────────┐     │
│ 📊 仪表盘│  │ 统计卡片×4    │ │ 趋势图   │     │
│ 👥 用户  │  └──────────────┘ └──────────┘     │
│ 🔒 角色  │  ┌──────┐ ┌────────────────┐       │
│ ⚙ 配置  │  │饼图  │ │ 最近操作日志    │       │
│ 📋 日志  │  └──────┘ └────────────────┘       │
└──────────┴─────────────────────────────────────┘
```

विशेषताएँ: साइडबार फोल्ड करने योग्य, Material 3 दोहरा थीम, उच्च-घनत्व डेटा टेबल, डायलॉग, माउस होवर इंटरैक्शन

### 5.2 HarmonyOS मोबाइल एंड

पेज रूट:

| पेज | रूट | विवरण |
|------|------|------|
| LoginPage | `pages/LoginPage` | उपयोगकर्ता नाम + पासवर्ड + क्लिक कैप्चा लॉगिन |
| DashboardPage | `pages/DashboardPage` | सांख्यिकी कार्ड + हाल के संचालन |
| UserListPage | `pages/UserListPage` | उपयोगकर्ता सूची, खोज + नीचे स्वाइप रिफ्रेश + ऊपर स्वाइप लोड |
| UserDetailPage | `pages/UserDetailPage` | नया/संपादित/देखें/हटाएँ (AlertDialog पुष्टि) |
| ProfilePage | `pages/ProfilePage` | व्यक्तिगत केंद्र, लॉगआउट (AlertDialog पुष्टि) |

डेटा प्रवाह: Page ← DataService ← ApiService (JWT Bearer) ← HTTP ← webman

## 6. सुरक्षा डिज़ाइन

### 6.1 गहराई में रक्षा

| परत | उपाय |
|------|------|
| विधि सीमा | SecurityFilter HTTP विधि व्हाइटलिस्ट, केवल GET/POST/PUT/DELETE/OPTIONS/HEAD, गैर-मानक विधियाँ 405 लौटाती हैं |
| आक्रमण अवरोधन | SecurityFilter मिडलवेयर, XSS/SQL इंजेक्शन/पाथ ट्रैवर्सल/कमांड इंजेक्शन/CSRF जाँच अवरोधन |
| मानव-मशीन सत्यापन | क्लिक कैप्चा (Click Captcha), लॉगिन/रजिस्ट्रेशन पर अनिवार्य सत्यापन |
| खाता लॉक | लगातार 5 बार लॉगिन विफलता पर खाता 15 मिनट लॉक, लॉक अवधि में 429 लौटता है |
| सत्र सीमा | एक ही उपयोगकर्ता अधिकतम 3 समवर्ती Token, अधिक होने पर सबसे पुराना Token स्वचालित ब्लैकलिस्ट |
| रेट लिमिट | RateLimit मिडलवेयर, Redis स्लाइडिंग विंडो, Lua एटॉमिक |
| CSP | Content-Security-Policy हेडर संसाधन स्रोत सीमित करता है, XSS और डेटा इंजेक्शन रोकता है |
| संचालन पुष्टि | डिलीट जैसे संवेदनशील संचालनों में वर्तमान उपयोगकर्ता पासवर्ड द्वितीयक पुष्टि आवश्यक |
| परिवहन | HTTPS + JWT Bearer Token |
| इंटरफ़ेस ID | Hashids एन्क्रिप्शन, बाहर से वास्तविक ID का अनुमान असंभव |
| अनुरोध बॉडी | AES-256-CBC संवेदनशील फ़ील्ड एन्क्रिप्शन |
| डेटाबेस | BIGINT प्राथमिक कुंजी (ऑटोइंक्रीमेंट मात्रा उजागर नहीं करती) |
| डेटाबेस | AES-128-ECB संवेदनशील फ़ील्ड एन्क्रिप्टेड संग्रहण |
| प्रमाणीकरण | JWT HS256, 2h समाप्ति + refresh token |
| प्राधिकरण | RBAC, method.path ग्रैन्युलैरिटी अनुमति नियंत्रण |
| ऑडिट | OperationLog सभी संचालन रिकॉर्ड करता है (source स्रोत स्वचालित जाँच सहित) |

### 6.2 कुंजी प्रबंधन

```
JWT_SECRET          → 环境变量注入，64位随机字符串
HASHIDS_SALT        → 唯一盐值，泄漏后需全局更换
ENCRYPTION_KEY      → API 传输加密密钥，32字节
ENCRYPTABLE_KEY     → DB 存储加密密钥，与传输密钥独立
SCOUT_HOSTS         → ES 地址，内网部署
```

### 6.3 संवेदनशील डेटा सुरक्षा

| परिदृश्य | फ़ील्ड | उपाय |
|------|------|------|
| सूची प्रदर्शन | phone | मास्किंग: 138****1234 |
| सूची प्रदर्शन | email | मास्किंग: a***@example.com |
| विवरण देखना | phone/email | डिक्रिप्ट इंटरफ़ेस आवश्यक |
| Excel निर्यात | phone/email | मास्किंग के बाद निर्यात |
| PDF निर्यात | सभी फ़ील्ड | मास्किंग + हटाने योग्य नहीं कॉपीराइट वॉटरमार्क |
| संग्रहण | phone/email/id_card | encryptable एन्क्रिप्शन से साइफरटेक्स्ट |

## 7. निर्यात डिज़ाइन

### 7.1 Excel निर्यात

```
请求: POST /admin/export/excel { table, columns, conditions, title }
  → fetchExportData() 查询数据 (limit 10000)
  → 脱敏敏感字段
  → PhpSpreadsheet 构建（蓝底白字表头 + 冻结首行 + 自动筛选）
  → 写入 runtime/tmp/ → download 响应
```

### 7.2 PDF निर्यात

```
请求: POST /admin/export/pdf { type: table|dashboard, title, data }
  → buildPdfHtml() HTML + 内联CSS + 页头版权 + 页脚不可移除版权
  → Dompdf 渲染 A4 横向
  → 写入 runtime/tmp/ → download 响应
```

## 8. डिप्लॉयमेंट आर्किटेक्चर

### 8.1 अनुशंसित टोपोलॉजी

```
Nginx (:443 HTTPS) → webman worker × N (:8787) → MySQL + ES + Redis
                    静态文件: Flutter Web build/
```

### 8.2 Docker Compose (अनुशंसित उत्पादन परिवेश)

प्रोजेक्ट रूट निर्देशिका का `docker-compose.yml` उपरोक्त टोपोलॉजी की सभी सेवाओं की व्यवस्था करता है:

| सेवा | इमेज/बिल्ड | पोर्ट | विवरण |
|------|----------|------|------|
| `nginx` | nginx:alpine | 80, 443 | रिवर्स प्रॉक्सी + स्थिर फ़ाइलें + Gzip |
| `app` | स्थानीय `Dockerfile` बिल्ड | 8787 | PHP 8.3 + OPcache + webman |
| `mysql` | mysql:8.0 | 3306 | मुख्य डेटाबेस, डेटा वॉल्यूम पर्सिस्टेंस |
| `redis` | redis:7-alpine | 6379 | कैश / रेट लिमिट / कैप्चा |
| `elasticsearch` | elasticsearch:8.x | 9200 | फ़ुल-टेक्स्ट खोज |

शुरू करने से पहले `docker-compose.yml` में `JWT_SECRET`, `HASHIDS_SALT`, `ENCRYPTION_KEY` आदि कुंजियों को यादृच्छिक स्ट्रिंग से बदलें।

```bash
cp .env.docker .env
docker-compose up -d
```

### 8.3 CI/CD

GitHub Actions निरंतर एकीकरण `.github/workflows/ci.yml` में परिभाषित:
- PHP सिंटैक्स जाँच (`php -l`)
- PHPUnit यूनिट परीक्षण
- Flutter स्थिर विश्लेषण (`flutter analyze`)

### 8.4 डेटाबेस बैकअप

`database/backup/backup.sh` — mysqldump + gzip बैकअप, 30 दिन पुराने बैकअप स्वचालित सफाई।
`database/backup/restore.sh` — इंटरैक्टिव चयन और बैकअप पुनर्स्थापना।

### 8.5 मॉनिटरिंग

`GET /metrics` एंडपॉइंट (`MetricsController`) Prometheus text फ़ॉर्मेट में 5 gauge मेट्रिक्स प्रदर्शित करता है: HTTP अनुरोध कुल संख्या, सक्रिय उपयोगकर्ता संख्या, डेटाबेस/Redis कनेक्शन स्थिति, मेमोरी उपयोग।

### 8.6 पर्यावरण आवश्यकताएँ

| घटक | न्यूनतम संस्करण | अनुशंसित कॉन्फ़िगरेशन |
|------|---------|---------|
| PHP | 8.3+ | 8.3+ OPcache सक्षम |
| MySQL | 8.0+ | 8.0+ मास्टर-स्लेव रेप्लिकेशन |
| Elasticsearch | 7.x | 8.x 3-नोड क्लस्टर |
| Redis | 6.x | 7.x सेंटिनल मोड |
| Nginx | 1.20+ | रिवर्स प्रॉक्सी + gzip + SSL |
| Flutter SDK | 3.41+ | नवीनतम स्थिर संस्करण |
| HarmonyOS | API 12 | DevEco Studio 5.x |
