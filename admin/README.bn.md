# ওপেন অ্যাডমিন প্যানেল (open-admin)
**语言 / Languages:** [中文](README.md) · [English](README.en.md) · [한국어](README.ko.md) · [Русский](README.ru.md) · [Deutsch](README.de.md) · [Français](README.fr.md) · [Español](README.es.md) · [Português](README.pt.md) · [हिन्दी](README.hi.md) · [العربية](README.ar.md) · [বাংলা](README.bn.md) · [Bahasa Indonesia](README.id.md) · [日本語](README.ja.md)

webman v2 + Flutter ভিত্তিক একটি ফুল-স্ট্যাক অ্যাডমিন প্যানেল সিস্টেম।

> [English version](README_EN.md) | [আর্কিটেকচার ডায়াগ্রাম](docs/ARCHITECTURE.md) | [ডিজাইন ডকুমেন্ট](docs/DESIGN.md) | [নিরাপত্তা আর্কিটেকচার](docs/SECURITY.md) | [API রেফারেন্স](docs/API.md)

## ফিচার তালিকা

| ক্ষেত্র | ফিচার | বিবরণ |
|--------|------|------|
| 🔐 প্রমাণীকরণ | লগইন / টোকেন রিফ্রেশ / লগআউট | ক্লিক ক্যাপচা + JWT + ব্ল্যাকলিস্ট |
| | অ্যাকাউন্ট লক | ৫ বার ব্যর্থ হলে ১৫ মিনিট লক |
| | সমবর্তী সেশন সীমা | প্রতি ব্যবহারকারীর সর্বোচ্চ ৩টি বৈধ টোকেন |
| 📊 ড্যাশবোর্ড | রিয়েল-টাইম পরিসংখ্যান / ট্রেন্ড চার্ট / বিতরণ / সাম্প্রতিক কার্যক্রম | Redis ক্যাশ ৫ মিনিট |
| 👥 ব্যবহারকারী ব্যবস্থাপনা | CRUD + ব্যাচ ডিলিট / সক্রিয়-নিষ্ক্রিয় | সফট ডিলিট + পাসওয়ার্ড নিশ্চিতকরণ |
| | Excel ব্যাচ ইমপোর্ট | সারি-ভিত্তিক যাচাই + ত্রুটি রিপোর্ট |
| 🔒 ভূমিকা ও অনুমতি | ভূমিকা CRUD + অনুমতি ট্রি | RBAC method.path গ্র্যানুলারিটি অনুমোদন |
| ⚙ সিস্টেম কনফিগারেশন | কী-ভ্যালু CRUD | গ্রুপভিত্তিক ব্যবস্থাপনা |
| 📋 অপারেশন অডিট | লগ কোয়েরি + উৎস শনাক্তকরণ | ৮টি প্ল্যাটফর্ম স্বয়ংক্রিয়ভাবে চেনা যায় |
| 📁 ফাইল ব্যবস্থাপনা | আপলোড / Excel এক্সপোর্ট / PDF এক্সপোর্ট | সংবেদনশীল ডেটা স্বয়ংক্রিয় মাস্কিং |
| 🛡 নিরাপত্তা | ১৮-স্তর গভীর প্রতিরক্ষা | XSS/SQL ইনজেকশন/পাথ ট্রাভার্সাল/কমান্ড ইনজেকশন/CSRF/রেট লিমিট/CSP... |
| 🏥 পরিচালনা | হেলথ চেক / metrics / API ডকুমেন্টেশন / security.txt | Prometheus + OpenAPI 3.0 + hg/apidoc ইন্টারঅ্যাকটিভ ডকুমেন্টেশন |
| 🌐 আন্তর্জাতিককরণ | চীনা/ইংরেজি সুইচ | Accept-Language হেডার / ?lang= প্যারামিটার |

## টেকনোলজি স্ট্যাক

| স্তর | প্রযুক্তি | বিবরণ |
|---|------|------|
| ব্যাকএন্ড ফ্রেমওয়ার্ক | webman v2 (workerman) | অতি-উচ্চ কর্মক্ষমতা সম্পন্ন PHP রেসিডেন্ট প্রসেস ফ্রেমওয়ার্ক |
| PHP সংস্করণ | 8.3+ | |
| ডেটাবেস | MySQL 8.0+ | টেবিল উপসর্গ `erik_`, BIGINT নন-অটো-ইনক্রিমেন্ট প্রাইমারি কী |
| সার্চ ইঞ্জিন | Elasticsearch | `webman-scout` দিয়ে সিঙ্ক ও কোয়েরি |
| অ্যাডমিন ফ্রন্টএন্ড | Flutter 3.x | ওয়েব পিসি অ্যাডমিন প্যানেল স্টাইলে (`apps/flutter/`) |
| মোবাইল | HarmonyOS ArkTS | হারমনিওএস নেটিভ ক্লায়েন্ট (`apps/harmonyos/`), ফোন/ট্যাবলেট/২-ইন-১ সাপোর্ট |

## মূল নির্ভরতা

| প্যাকেজ | উদ্দেশ্য |
|---|------|
| `erikwang2013/snowflake-php` | Snowflake অ্যালগরিদম দিয়ে বিশ্বব্যাপী অনন্য BIGINT প্রাইমারি কী তৈরি |
| `erikwang2013/hashids` | API স্তরে ID এনক্রিপশন/ডিক্রিপশন, আসল ডেটাবেস ID লুকায় |
| `erikwang2013/jwt-webman` | JWT প্রমাণীকরণ টোকেন ইস্যু ও যাচাই |
| `erikwang2013/encryption` | ইন্টারফেস ট্রান্সপোর্ট স্তরে সংবেদনশীল ডেটা এনক্রিপশন/ডিক্রিপশন |
| `erikwang2013/encryptable` | ডেটাবেস স্টোরেজ স্তরে সংবেদনশীল ফিল্ড স্বয়ংক্রিয় এনক্রিপশন/ডিক্রিপশন |
| `erikwang2013/webman-scout` | Elasticsearch ডেটা সিঙ্ক ও ফুল-টেক্সট সার্চ |
| `erikwang2013/season` | দেশের পতাকার ডেটা |
| `erikwang2013/poster-php` | ক্লিক ক্যাপচা তৈরি/যাচাই + পোস্টার তৈরি |
| `phpoffice/phpspreadsheet` | Excel এক্সপোর্ট |
| `barryvdh/laravel-dompdf` | PDF এক্সপোর্ট (Dompdf ভিত্তিক) |

## প্রকল্প কাঠামো

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

## পরিবেশের প্রয়োজনীয়তা

- PHP >= 8.3
- Composer 2.x
- MySQL >= 8.0
- Flutter >= 3.41 (শুধুমাত্র ফ্রন্টএন্ড ডেভেলপমেন্টের জন্য)
- Elasticsearch >= 7.x (ঐচ্ছিক, সার্চ ফিচারের জন্য প্রয়োজন)

## দ্রুত শুরু

### 1. নির্ভরতা ইনস্টল করুন

```bash
composer install
```

### 2. পরিবেশ চলক কনফিগার করুন

পরিবেশ চলক কপি করে পরিবর্তন করুন (ঐচ্ছিক; কনফিগার না করলে `config/*.php`-এর ডিফল্ট মান ব্যবহৃত হয়):

```bash
cp .env.example .env
```

মূল কনফিগারেশন আইটেম:

| পরিবেশ চলক | বিবরণ | ডিফল্ট মান |
|---------|------|--------|
| `JWT_SECRET` | JWT স্বাক্ষর কী | `open-admin-jwt-secret-change-in-production` |
| `HASHIDS_SALT` | Hashids সল্ট | `open-admin-hashids-salt-2026` |
| `ENCRYPTION_KEY` | API এনক্রিপশন কী | ৩২-বাইট ডিফল্ট মান |
| `SNOWFLAKE_DATACENTER_ID` | ডেটাসেন্টার ID (0-31) | `1` |
| `SNOWFLAKE_WORKER_ID` | ওয়ার্কার নোড ID (0-31) | `1` |
| `SCOUT_HOSTS` | ES ঠিকানা | `http://localhost:9200` |

**প্রোডাকশন পরিবেশে অবশ্যই সব কী র্যান্ডম স্ট্রিংয়ে পরিবর্তন করুন।**

### 3. ওয়ান-ক্লিক ইনস্টলেশন

সার্ভিস চালু করার পর ব্রাউজারে ইনস্টলেশন উইজার্ড খুলে ডেটাবেস ইনিশিয়ালাইজেশন ও অ্যাডমিন তৈরি সম্পূর্ণ করুন:

```bash
php start.php start
```

ডিফল্টভাবে `http://0.0.0.0:8787` লিসেন করে (পোর্ট `config/server.php`-এ পরিবর্তন করা যায়)।

ব্রাউজারে **`http://localhost:8787/install`** খুলে উইজার্ড অনুযায়ী পূরণ করুন:

| ধাপ | বিষয়বস্তু |
|------|------|
| ① ডেটাবেস কনফিগারেশন | হোস্ট ঠিকানা, পোর্ট, ডেটাবেস নাম, ব্যবহারকারীর নাম, পাসওয়ার্ড |
| ② অ্যাডমিন সেটআপ | অ্যাডমিন ব্যবহারকারীর নাম ও পাসওয়ার্ড (ডিফল্ট: admin / admin888) |

«ইনস্টলেশন শুরু করুন» চাপলে টেবিল তৈরি, অনুমতি ডেটা সিডিং, অ্যাডমিন অ্যাকাউন্ট তৈরি এবং `.env`-এ ডেটাবেস কনফিগারেশন লেখা স্বয়ংক্রিয়ভাবে সম্পন্ন হয়।

> ইনস্টলেশনের পর `runtime/install.lock` লক ফাইল তৈরি হয়। পুনরায় ইনস্টল করতে এই ফাইলটি মুছে ফেলুন।

### 4. লগইন

`http://localhost:8787`-এ গিয়ে ইনস্টলেশনের সময় নির্ধারিত অ্যাডমিন অ্যাকাউন্ট দিয়ে লগইন করুন।

### 5. ফ্রন্টএন্ড চালু করুন (ঐচ্ছিক)

**Flutter অ্যাডমিন প্যানেল (ওয়েব):**

```bash
cd apps/flutter
flutter pub get
flutter run -d chrome    # Web 端（PC 管理后台风格）
```

**HarmonyOS ক্লায়েন্ট (মোবাইল):**

DevEco Studio দিয়ে `apps/harmonyos/` ডিরেক্টরি খুলে আসল ডিভাইস বা এমুলেটরে চালান।

### 6. Docker Compose ওয়ান-ক্লিক ডিপ্লয়মেন্ট (প্রোডাকশনের জন্য সুপারিশকৃত)

প্রকল্পটি ৫টি সার্ভিসসহ সম্পূর্ণ Docker অর্কেস্ট্রেশন প্রদান করে: Nginx, PHP (webman app), MySQL, Redis, Elasticsearch।

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

- `Dockerfile`: PHP 8.3 + OPcache + Composer, `php:8.3-cli` ভিত্তিক
- `docker-compose.yml`: ৫টি সার্ভিস অর্কেস্ট্রেশন, নেটওয়ার্ক আইসোলেশন, ডেটা ভলিউম পার্সিস্টেন্স
- `.env.docker`: Docker-এর জন্য বিশেষ পরিবেশ চলক


## ডেটাবেস নিয়মাবলী

- **টেবিল উপসর্গ**: `erik_`
- **প্রাইমারি কী**: সব টেবিলের প্রাইমারি কী `id BIGINT UNSIGNED NOT NULL`, **AUTO_INCREMENT নিষিদ্ধ**
- **ID তৈরি**: প্রাইমারি কী ID অ্যাপ্লিকেশন স্তরের `SnowflakeService::generate()` দিয়ে তৈরি, ডিস্ট্রিবিউটেডভাবে অনন্য
- **অবশ্যই প্রয়োজনীয় ফিল্ড**: প্রতিটি টেবিলে `id`, `created_at`, `updated_at` থাকতে হবে
- **সফট ডিলিট**: যেখানে দরকার সেখানে `deleted_at DATETIME DEFAULT NULL` যোগ করুন
- **সংবেদনশীল ফিল্ড**: ফোন, ইমেইল, আইডি কার্ড নম্বর ইত্যাদি `encryptable` প্লাগইন দিয়ে স্বয়ংক্রিয় এনক্রিপ্ট/ডিক্রিপ্ট হয়; ডেটাবেস ফিল্ড `VARCHAR(500)`-এ সাইফারটেক্সট সংরক্ষণ করে

## API রেফারেন্স

সম্পূর্ণ API স্পেসিফিকেশন (একীভূত রেসপন্স ফরম্যাট, ব্যবসায়িক ত্রুটি কোড, ID প্রসেসিং, API সংস্করণ, রেট লিমিট, মিডলওয়্যার আর্কিটেকচার, প্রমাণীকরণ ও ক্যাপচা প্রবাহ) এবং সব এন্ডপয়েন্টের তালিকা **[API রেফারেন্স ডকুমেন্ট](docs/API.md)**-এ দেখুন।

## ফ্রন্টএন্ড নোট

### Flutter অ্যাডমিন প্যানেল (PC স্টাইল)

- **লেআউট**: ভাঁজযোগ্য সাইডবার (64px/240px) + টপ বার + কনটেন্ট এলাকা, তিনটি রেসপনসিভ ব্রেকপয়েন্ট (ফোন/ট্যাবলেট/ডেস্কটপ)
- **পেজ**: লগইন, ড্যাশবোর্ড, ব্যবহারকারী ব্যবস্থাপনা, ভূমিকা ও অনুমতি, সিস্টেম কনফিগারেশন, অপারেশন লগ, প্রোফাইল
- **স্টেট ম্যানেজমেন্ট**: GetX (`ApiService` সিংগলটন + `AuthService` টোকেন পার্সিস্টেন্স)
- **ড্যাশবোর্ড**: পরিসংখ্যান কার্ড, ট্রেন্ড লাইন চার্ট (fl_chart), পাই চার্ট, সাম্প্রতিক অপারেশন লগ
- **এক্সপোর্ট**: Excel/PDF এক্সপোর্ট; PDF-এ অপসারণযোগ্য নয় এমন কপিরাইট তথ্য থাকে
- **ব্যাচ অপারেশন**: মাল্টি-সিলেক্ট ব্যাচ ডিলিট, ব্যাচ সক্রিয়/নিষ্ক্রিয়
- **থিম**: Material 3 লাইট/ডার্ক ডুয়াল থিম

### HarmonyOS মোবাইল ক্লায়েন্ট

- **পেজ**: লগইন, ড্যাশবোর্ড, ব্যবহারকারী তালিকা/বিস্তারিত, প্রোফাইল
- **প্রমাণীকরণ**: JWT Bearer + 401-এ স্বয়ংক্রিয় নীরব টোকেন রিফ্রেশ; ব্যর্থ হলে স্বয়ংক্রিয়ভাবে লগইন পেজে রিডাইরেক্ট
- **স্টোরেজ**: টোকেন AppStorage দিয়ে পরিচালিত হয়

## ডেভেলপমেন্ট নিয়ম

- গ্লোবাল ফাংশন/ক্লাস রেফারেন্সে সামনে `\` দেওয়া হয় না, সব `use` দিয়ে ইমপোর্ট করা হয়
- সব PHP ফাইলের শীর্ষে কপিরাইট ঘোষণা থাকতে হবে
- সব কনফিগারেশন ফাইলে চীনা মন্তব্য থাকতে হবে
- ডেটাবেস প্রাইমারি কী অ্যাপ্লিকেশন স্তরের snowflake দিয়ে তৈরি হতে হবে; অটো-ইনক্রিমেন্ট নিষিদ্ধ
- API স্তরের সব প্যারামিটার ও রেসপন্সের ID hashids দিয়ে এনক্রিপ্ট/ডিক্রিপ্ট করতে হবে
- AdminPermission মিডলওয়্যার ব্যবহারকারীর অনুমতি Redis-এ ক্যাশ করে (TTL=60s), N+1 কোয়েরি বাধা দূর হয়

## ডিপ্লয়মেন্ট

### Docker Compose (সুপারিশকৃত)

প্রকল্পের রুটে `docker-compose.yml` দেওয়া আছে, যা ৫টি সার্ভিস অর্কেস্ট্রেট করে:

| সার্ভিস | ইমেজ | পোর্ট |
|------|------|------|
| `nginx` | nginx:alpine | 80, 443 |
| `app` | স্থানীয় `Dockerfile` থেকে বিল্ড | 8787 |
| `mysql` | mysql:8.0 | 3306 |
| `redis` | redis:7-alpine | 6379 |
| `elasticsearch` | elasticsearch:8.x | 9200 |

PHP ইমেজ `Dockerfile` দিয়ে তৈরি হয়, বেস ইমেজ `php:8.3-cli`, OPcache সক্রিয়।

```bash
cp .env.docker .env
docker-compose up -d
```

### CI/CD

GitHub Actions CI পাইপলাইন: `.github/workflows/ci.yml`

- PHP সিনট্যাক্স চেক (`php -l`)
- PHPUnit ইউনিট টেস্ট
- Flutter স্ট্যাটিক বিশ্লেষণ (`flutter analyze`)

### ডেটাবেস ব্যাকআপ

`database/backup/` ডিরেক্টরি:

- `backup.sh` — mysqldump + gzip ব্যাকআপ, ৩০ দিনের পুরনো ব্যাকআপ স্বয়ংক্রিয় পরিষ্কার
- `restore.sh` — ইন্টারঅ্যাকটিভ রিস্টোর, নির্বাচনের জন্য উপলব্ধ ব্যাকআপ তালিকাভুক্ত করে

### Nginx নিরাপত্তা কনফিগারেশন

প্রোডাকশন ডিপ্লয়মেন্টে রিভার্স প্রক্সি নিরাপত্তা শক্তিশালী করতে `docs/nginx-security.conf` দেখুন।

## ওপেন সোর্স সহজ নয় — সমর্থন স্বাগত

| উইচ্যাট | আলিপে |
|:---:|:---:|
| ![উইচ্যাট](./docs/weixinpay.png "উইচ্যাট") | ![আলিপে](./docs/alipay.png "আলিপে") |

---

## License

MIT

Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
