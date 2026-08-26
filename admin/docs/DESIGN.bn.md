# ওপেন অ্যাডমিন কনসোল — ডিজাইন নথি

**语言 / Languages:** [中文](DESIGN.md) · [English](DESIGN.en.md) · [한국어](DESIGN.ko.md) · [Русский](DESIGN.ru.md) · [Deutsch](DESIGN.de.md) · [Français](DESIGN.fr.md) · [Español](DESIGN.es.md) · [Português](DESIGN.pt.md) · [हिन्दी](DESIGN.hi.md) · [العربية](DESIGN.ar.md) · [বাংলা](DESIGN.bn.md) · [Bahasa Indonesia](DESIGN.id.md) · [日本語](DESIGN.ja.md)

> Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz

> বিস্তারিত Mermaid আর্কিটেকচার ডায়াগ্রামের জন্য [ARCHITECTURE.md](ARCHITECTURE.md) দেখুন (GitHub/GitLab/VS Code-এ স্বয়ংক্রিয় রেন্ডারিং)।

## 1. সিস্টেম আর্কিটেকচার

> **ফিচার তালিকা**: প্রমাণীকরণ (login/register/refresh/logout + অ্যাকাউন্ট লক + সেশন সীমা) | ড্যাশবোর্ড (Redis ক্যাশ) | ব্যবহারকারী CRUD+বাল্ক+ইমপোর্ট | রোল অনুমতি (RBAC) | সিস্টেম কনফিগারেশন | অপারেশন অডিট (৮ প্ল্যাটফর্ম উৎস) | ফাইল (আপলোড+এক্সপোর্ট+মাস্কিং) | নিরাপত্তা (১৮-স্তর প্রতিরক্ষা) | অপারেশন (health/metrics/docs/Docker/CI)

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

## 2. ব্যাকএন্ড আর্কিটেকচার

### 2.1 স্তরভিত্তিক ডিজাইন

| স্তর | ডিরেক্টরি | দায়িত্ব |
|------|------|------|
| রুট | `config/route.php` | URL থেকে কন্ট্রোলার ম্যাপিং, মিডলওয়্যার বাইন্ডিং, সংস্করণযুক্ত রুট |
| মিডলওয়্যার | `app/middleware/` | আক্রমণ ব্লকিং (SecurityFilter), রেট লিমিট (RateLimit), প্রমাণীকরণ (JWT), অনুমোদন (RBAC), API সংস্করণ (ApiVersion) |
| কন্ট্রোলার | ১৪টি: Dashboard/User/Role/Permission/Config/Log/Profile/Export/Import/Upload/Health/Docs (অ্যাডমিন প্রান্ত) + Captcha/Auth (API v1) | অনুরোধ প্যারামিটার যাচাই, ব্যবসায়িক লজিক কল, রেসপন্স ফরম্যাটিং |
| ব্যবসায়িক পরিষেবা | `app/service/` | পুনঃব্যবহারযোগ্য ব্যবসায়িক লজিক (সংরক্ষিত) |
| ডেটা মডেল | `app/model/` | ORM ম্যাপিং, সম্পর্ক, ফিল্ড এনক্রিপ্ট/ডিক্রিপ্ট |
| সাধারণ টুল | `app/common/` | Hashids, Snowflake, Encryption পরিষেবা |

### 2.2 অনুরোধের জীবনচক্র

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

### 2.3 ID জীবনচক্র

```
生成 (Snowflake) → 存储 (MySQL BIGINT) → 传输 (Hashids 编码) → 外部 (hash 字符串)
                                                                    │
                            HashidsService::decode() ←──────────────┘
```

### 2.4 ডেটা এনক্রিপশন সিস্টেম

```
传输层 (encryption)     — AES-256-CBC，独立密钥
存储层 (encryptable)    — AES-128-ECB，独立密钥，Model $casts 自动处理
展示层 (mask)           — 手机号: 138****1234，邮箱: a***@example.com
```

## 3. ডেটাবেস ডিজাইন

### 3.1 ER সম্পর্ক

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

### 3.2 মূল টেবিলের গঠন

| টেবিলের নাম | ফিল্ড সংখ্যা | বিবরণ |
|------|-------|------|
| `erik_admin_user` | ১৪ | অ্যাডমিন ব্যবহারকারী, phone/email/id_card এনক্রিপ্টেড স্টোর, সফট ডিলিট সমর্থিত |
| `erik_admin_role` | ৭ | রোল, slug অনন্য |
| `erik_admin_permission` | ১০ | অনুমতি ট্রি (parent_id স্ব-রেফারেন্স), type: 1=মেনু 2=বাটন 3=API |
| `erik_admin_user_role` | ২ | ব্যবহারকারী-রোল ম্যানি-টু-ম্যানি মধ্যবর্তী টেবিল |
| `erik_admin_role_permission` | ২ | রোল-অনুমতি ম্যানি-টু-ম্যানি মধ্যবর্তী টেবিল |
| `erik_system_config` | ৮ | কী-ভ্যালু কনফিগারেশন, group+key সম্মিলিত অনন্য |
| `erik_operation_log` | ৯ | অপারেশন অডিট লগ (source উৎস ফিল্ড সহ) |

### 3.3 প্রাইমারি কী মান

- ধরন: `BIGINT UNSIGNED NOT NULL`
- বৈশিষ্ট্য: **অ-অটোইনক্রিমেন্ট**, অ্যাপ্লিকেশন স্তরে Snowflake অ্যালগরিদম দ্বারা উৎপন্ন
- সুবিধা: বিশ্বব্যাপী অনন্য, বিতরণ-বান্ধব, ট্রেন্ড-বর্ধনশীল ইনডেক্সের জন্য অনুকূল, ব্যবসার পরিমাণ প্রকাশ করে না
- কনফিগারেশন: datacenter_id(0-31) + worker_id(0-31), ১০২৪ নোড সমবর্তী সমর্থিত

## 4. API ডিজাইন

### 4.1 URL মান

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

### 4.2 API সংস্করণ নীতি

API সংস্করণ অনুরোধ হেডার দ্বারা নিয়ন্ত্রিত হয়, **URL পাথে প্রদর্শিত হয় না**:

```http
API-Version: v1
```

| প্রক্রিয়া | বিবরণ |
|------|------|
| ডিফল্ট সংস্করণ | `API-Version` হেডার না থাকলে ডিফল্ট `v1` |
| যাচাই | `ApiVersion` মিডলওয়্যার যাচাই করে, অসমর্থিত সংস্করণে ৪০০ ফেরত দেয় |
| রুট | `v()` সহায়ক ফাংশন সংস্করণ অনুযায়ী কন্ট্রোলার ক্লাস গতিশীলভাবে সমাধান করে |
| ডিরেক্টরি | কন্ট্রোলার সংস্করণ অনুযায়ী সংগঠিত: `app/api/{version}/controller/` |

সম্প্রসারণ উদাহরণ — নতুন v2 API যোগ করা:
১. `app/api/v2/controller/AuthController.php` তৈরি করুন
২. `ApiVersion` মিডলওয়্যারের `SUPPORTED` ধ্রুবকে `'v2'` যোগ করুন
৩. রুট সংজ্ঞা পরিবর্তনের প্রয়োজন নেই

```bash
# 使用 v1
curl -H "API-Version: v1" /api/auth/login

# 使用 v2
curl -H "API-Version: v2" /api/auth/login

# 不传，默认 v1
curl /api/auth/login
```

### 4.3 রেট লিমিটিং নীতি

Redis Sorted Set স্লাইডিং উইন্ডো অ্যালগরিদমের উপর ভিত্তি করে, অ্যাটমিক Lua স্ক্রিপ্ট এক্সিকিউশন:

| ইন্টারফেস | সীমা |
|------|------|
| ডিফল্ট | ৬০ বার/মিনিট/IP/রুট |
| POST /api/auth/login | ১০ বার/মিনিট |
| POST /api/auth/register | ৫ বার/মিনিট |

সীমা অতিক্রম করলে ৪২৯ ফেরত দেয়, রেসপন্স হেডারে X-RateLimit-Limit / Remaining / Reset / Retry-After অন্তর্ভুক্ত।

### 4.4 একীভূত রেসপন্স

```json
{
  "code": 0,
  "message": "success",
  "data": { ... }
}
```

| code | অর্থ | ট্রিগার পরিস্থিতি |
|------|------|---------|
| ০ | সফল | সাধারণ রেসপন্স |
| ৪০০ | প্যারামিটার ত্রুটি | অনুরোধের ফরম্যাট সঠিক নয় |
| ৪০১ | প্রমাণীকৃত নয় | টোকেন অনুপস্থিত/মেয়াদোত্তীর্ণ/অবৈধ |
| ৪০৩ | অনুমতি নেই | ব্যবহারকারীর রোলে প্রয়োজনীয় অনুমতি নেই |
| ৪০৪ | নেই | রিসোর্স খুঁজে পাওয়া যায়নি |
| ৪২২ | যাচাই ব্যর্থ | ফর্ম প্যারামিটার নিয়ম মেনে না / পাসওয়ার্ড নিশ্চিতকরণ ব্যর্থ |
| ৫০০ | সার্ভার ত্রুটি | অপ্রত্যাশিত ব্যতিক্রম |

### 4.5 প্রমাণীকরণ প্রক্রিয়া (ক্লিক ক্যাপচাসহ)

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

### 4.6 অনুমতি মডেল (RBAC)

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

### 4.7 সংবেদনশীল অপারেশনের দ্বিতীয় নিশ্চিতকরণ

ব্যবহারকারী, রোল, অনুমতি মুছে ফেলার মতো সংবেদনশীল অপারেশনে, অনুরোধ বডিতে বর্তমান ব্যবহারকারীর পাসওয়ার্ড পাঠিয়ে পরিচয় পুনরায় যাচাই করতে হয়:

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

ফ্রন্টএন্ড মুছে ফেলার অপারেশন শুরু করার আগে নিশ্চিতকরণ ডায়ালগ দেখায়, ব্যবহারকারীর পাসওয়ার্ড সংগ্রহ করে অনুরোধ পাঠায়।

## 5. ফ্রন্টএন্ড ডিজাইন

### 5.1 Flutter Web অ্যাডমিন প্যানেল

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

বৈশিষ্ট্য: সাইডবার ভাঁজযোগ্য, Material 3 দ্বৈত থিম, উচ্চ-ঘনত্ব ডেটা টেবিল, ডায়ালগ পপআপ, মাউস হোভার ইন্টারঅ্যাকশন

### 5.2 HarmonyOS মোবাইল প্রান্ত

পেজ রুট:

| পেজ | রুট | বিবরণ |
|------|------|------|
| LoginPage | `pages/LoginPage` | ইউজারনেম + পাসওয়ার্ড + ক্লিক ক্যাপচা লগইন |
| DashboardPage | `pages/DashboardPage` | পরিসংখ্যান কার্ড + সাম্প্রতিক অপারেশন |
| UserListPage | `pages/UserListPage` | ব্যবহারকারী তালিকা, খোঁজা + নিচে টেনে রিফ্রেশ + উপরে টেনে লোড |
| UserDetailPage | `pages/UserDetailPage` | নতুন/সম্পাদনা/দেখা/মুছুন (AlertDialog নিশ্চিতকরণ) |
| ProfilePage | `pages/ProfilePage` | ব্যক্তিগত কেন্দ্র, লগআউট (AlertDialog নিশ্চিতকরণ) |

ডেটা প্রবাহ: Page ← DataService ← ApiService (JWT Bearer) ← HTTP ← webman

## 6. নিরাপত্তা ডিজাইন

### 6.1 ডিফেন্স ইন ডেপথ

| স্তর | ব্যবস্থা |
|------|------|
| মেথড সীমা | SecurityFilter HTTP মেথড হোয়াইটলিস্ট, শুধু GET/POST/PUT/DELETE/OPTIONS/HEAD, অ-মানক মেথড ৪০৫ ফেরত দেয় |
| আক্রমণ ব্লকিং | SecurityFilter মিডলওয়্যার, XSS/SQL ইনজেকশন/পাথ ট্রাভার্সাল/কমান্ড ইনজেকশন/CSRF শনাক্তকরণ ও ব্লকিং |
| মানুষ-মেশিন যাচাই | ক্লিক ক্যাপচা (Click Captcha), লগইন/রেজিস্ট্রেশনে বাধ্যতামূলক যাচাই |
| অ্যাকাউন্ট লক | টানা ৫ বার লগইন ব্যর্থ হলে অ্যাকাউন্ট ১৫ মিনিট লক, লক সময়ে ৪২৯ ফেরত দেয় |
| সেশন সীমা | একই ব্যবহারকারী সর্বোচ্চ ৩টি সমবর্তী টোকেন, অতিক্রম করলে সবচেয়ে পুরনো টোকেন স্বয়ংক্রিয় ব্ল্যাকলিস্ট |
| রেট লিমিট | RateLimit মিডলওয়্যার, Redis স্লাইডিং উইন্ডো, Lua অ্যাটমিক |
| CSP | Content-Security-Policy হেডার রিসোর্স উৎস সীমিত করে, XSS ও ডেটা ইনজেকশন প্রতিরোধ করে |
| অপারেশন নিশ্চিতকরণ | মুছে ফেলার মতো সংবেদনশীল অপারেশনে বর্তমান ব্যবহারকারীর পাসওয়ার্ড দ্বিতীয় নিশ্চিতকরণ প্রয়োজন |
| ট্রান্সমিশন | HTTPS + JWT Bearer Token |
| ইন্টারফেস ID | Hashids এনক্রিপশন, বাইরে থেকে প্রকৃত ID অনুমান করা অসম্ভব |
| অনুরোধ বডি | AES-256-CBC সংবেদনশীল ফিল্ড এনক্রিপশন |
| ডেটাবেস | BIGINT প্রাইমারি কী (অটোইনক্রিমেন্ট প্রকাশ করে না) |
| ডেটাবেস | AES-128-ECB সংবেদনশীল ফিল্ড এনক্রিপ্টেড স্টোরেজ |
| প্রমাণীকরণ | JWT HS256, ২ ঘণ্টা মেয়াদ + refresh token |
| অনুমোদন | RBAC, method.path গ্র্যানুলারিটি অনুমতি নিয়ন্ত্রণ |
| অডিট | OperationLog সব অপারেশন রেকর্ড করে (source উৎস স্বয়ংক্রিয় শনাক্তকরণ সহ) |

### 6.2 কী ব্যবস্থাপনা

```
JWT_SECRET          → 环境变量注入，64位随机字符串
HASHIDS_SALT        → 唯一盐值，泄漏后需全局更换
ENCRYPTION_KEY      → API 传输加密密钥，32字节
ENCRYPTABLE_KEY     → DB 存储加密密钥，与传输密钥独立
SCOUT_HOSTS         → ES 地址，内网部署
```

### 6.3 সংবেদনশীল ডেটা সুরক্ষা

| পরিস্থিতি | ফিল্ড | ব্যবস্থা |
|------|------|------|
| তালিকা প্রদর্শন | phone | মাস্কিং: 138****1234 |
| তালিকা প্রদর্শন | email | মাস্কিং: a***@example.com |
| বিস্তারিত দেখা | phone/email | ডিক্রিপ্ট ইন্টারফেস প্রয়োজন |
| Excel এক্সপোর্ট | phone/email | মাস্কিংয়ের পরে এক্সপোর্ট |
| PDF এক্সপোর্ট | সব ফিল্ড | মাস্কিং + অপসারণযোগ্য নয় কপিরাইট ওয়াটারমার্ক |
| স্টোরেজ | phone/email/id_card | encryptable দিয়ে সাইফারটেক্সটে এনক্রিপ্ট |

## 7. এক্সপোর্ট ডিজাইন

### 7.1 Excel এক্সপোর্ট

```
请求: POST /admin/export/excel { table, columns, conditions, title }
  → fetchExportData() 查询数据 (limit 10000)
  → 脱敏敏感字段
  → PhpSpreadsheet 构建（蓝底白字表头 + 冻结首行 + 自动筛选）
  → 写入 runtime/tmp/ → download 响应
```

### 7.2 PDF এক্সপোর্ট

```
请求: POST /admin/export/pdf { type: table|dashboard, title, data }
  → buildPdfHtml() HTML + 内联CSS + 页头版权 + 页脚不可移除版权
  → Dompdf 渲染 A4 横向
  → 写入 runtime/tmp/ → download 响应
```

## 8. ডিপ্লয়মেন্ট আর্কিটেকচার

### 8.1 প্রস্তাবিত টপোলজি

```
Nginx (:443 HTTPS) → webman worker × N (:8787) → MySQL + ES + Redis
                    静态文件: Flutter Web build/
```

### 8.2 Docker Compose (প্রস্তাবিত প্রোডাকশন পরিবেশ)

প্রজেক্ট রুট ডিরেক্টরির `docker-compose.yml` উপরের টপোলজির সব পরিষেবা সাজিয়ে দেয়:

| পরিষেবা | ইমেজ/বিল্ড | পোর্ট | বিবরণ |
|------|----------|------|------|
| `nginx` | nginx:alpine | 80, 443 | রিভার্স প্রক্সি + স্ট্যাটিক ফাইল + Gzip |
| `app` | স্থানীয় `Dockerfile` বিল্ড | 8787 | PHP 8.3 + OPcache + webman |
| `mysql` | mysql:8.0 | 3306 | প্রধান ডেটাবেস, ডেটা ভলিউম পার্সিস্টেন্স |
| `redis` | redis:7-alpine | 6379 | ক্যাশ / রেট লিমিট / ক্যাপচা |
| `elasticsearch` | elasticsearch:8.x | 9200 | ফুল-টেক্সট সার্চ |

শুরুর আগে `docker-compose.yml`-এর `JWT_SECRET`, `HASHIDS_SALT`, `ENCRYPTION_KEY` ইত্যাদি কী র্যান্ডম স্ট্রিং দিয়ে প্রতিস্থাপন করুন।

```bash
cp .env.docker .env
docker-compose up -d
```

### 8.3 CI/CD

GitHub Actions নিরবচ্ছিন্ন ইন্টিগ্রেশন `.github/workflows/ci.yml`-এ সংজ্ঞায়িত:
- PHP সিনট্যাক্স পরীক্ষা (`php -l`)
- PHPUnit ইউনিট টেস্ট
- Flutter স্ট্যাটিক বিশ্লেষণ (`flutter analyze`)

### 8.4 ডেটাবেস ব্যাকআপ

`database/backup/backup.sh` — mysqldump + gzip ব্যাকআপ, ৩০ দিন আগের পুরনো ব্যাকআপ স্বয়ংক্রিয় পরিষ্কার।
`database/backup/restore.sh` — ইন্টারঅ্যাকটিভ নির্বাচন ও ব্যাকআপ পুনরুদ্ধার।

### 8.5 মনিটরিং

`GET /metrics` এন্ডপয়েন্ট (`MetricsController`) Prometheus text ফরম্যাটে ৫টি gauge মেট্রিক প্রকাশ করে: HTTP অনুরোধের মোট সংখ্যা, সক্রিয় ব্যবহারকারীর সংখ্যা, ডেটাবেস/Redis কানেকশন অবস্থা, মেমরি ব্যবহার।

### 8.6 পরিবেশ প্রয়োজনীয়তা

| কম্পোনেন্ট | সর্বনিম্ন সংস্করণ | প্রস্তাবিত কনফিগারেশন |
|------|---------|---------|
| PHP | 8.3+ | 8.3+ OPcache সক্ষম |
| MySQL | 8.0+ | 8.0+ মাস্টার-স্লেভ রেপ্লিকেশন |
| Elasticsearch | 7.x | 8.x ৩-নোড ক্লাস্টার |
| Redis | 6.x | 7.x সেন্টিনেল মোড |
| Nginx | 1.20+ | রিভার্স প্রক্সি + gzip + SSL |
| Flutter SDK | 3.41+ | সর্বশেষ স্থিতিশীল সংস্করণ |
| HarmonyOS | API 12 | DevEco Studio 5.x |
