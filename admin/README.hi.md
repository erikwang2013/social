# ओपन एडमिन पैनल (open-admin)
**语言 / Languages:** [中文](README.md) · [English](README.en.md) · [한국어](README.ko.md) · [Русский](README.ru.md) · [Deutsch](README.de.md) · [Français](README.fr.md) · [Español](README.es.md) · [Português](README.pt.md) · [हिन्दी](README.hi.md) · [العربية](README.ar.md) · [বাংলা](README.bn.md) · [Bahasa Indonesia](README.id.md) · [日本語](README.ja.md)

webman v2 + Flutter पर आधारित फुल-स्टैक एडमिन पैनल सिस्टम।

> [English version](README_EN.md) | [आर्किटेक्चर आरेख](docs/ARCHITECTURE.md) | [डिज़ाइन दस्तावेज़](docs/DESIGN.md) | [सुरक्षा आर्किटेक्चर](docs/SECURITY.md) | [API संदर्भ](docs/API.md)

## फीचर सूची

| क्षेत्र | फ़ीचर | विवरण |
|--------|------|------|
| 🔐 प्रमाणीकरण | लॉगिन / टोकन रीफ़्रेश / लॉगआउट | क्लिक कैप्चा + JWT + ब्लैकलिस्ट |
| | खाता लॉक | 5 असफल प्रयासों पर 15 मिनट लॉक |
| | समवर्ती सत्र सीमा | प्रति उपयोगकर्ता अधिकतम 3 मान्य टोकन |
| 📊 डैशबोर्ड | रीयल-टाइम आँकड़े / ट्रेंड चार्ट / वितरण / हाल की गतिविधियाँ | Redis कैश 5 मिनट |
| 👥 उपयोगकर्ता प्रबंधन | CRUD + बैच डिलीट / सक्रिय-निष्क्रिय | सॉफ्ट डिलीट + पासवर्ड पुष्टि |
| | Excel बैच इम्पोर्ट | पंक्ति-दर-पंक्ति सत्यापन + त्रुटि रिपोर्ट |
| 🔒 भूमिकाएँ और अनुमतियाँ | भूमिका CRUD + अनुमति ट्री | RBAC method.path ग्रैन्युलैरिटी प्राधिकरण |
| ⚙ सिस्टम कॉन्फ़िगरेशन | की-वैल्यू CRUD | समूह प्रबंधन |
| 📋 ऑपरेशन ऑडिट | लॉग क्वेरी + स्रोत पहचान | 8 प्लेटफ़ॉर्म स्वतः पहचाने जाते हैं |
| 📁 फ़ाइल प्रबंधन | अपलोड / Excel एक्सपोर्ट / PDF एक्सपोर्ट | संवेदनशील डेटा स्वतः मास्क |
| 🛡 सुरक्षा | 18-परत गहराई में रक्षा | XSS/SQL इंजेक्शन/पाथ ट्रैवर्सल/कमांड इंजेक्शन/CSRF/रेट लिमिट/CSP... |
| 🏥 संचालन | हेल्थ चेक / metrics / API दस्तावेज़ / security.txt | Prometheus + OpenAPI 3.0 + hg/apidoc इंटरैक्टिव दस्तावेज़ |
| 🌐 अंतर्राष्ट्रीयकरण | चीनी/अंग्रेज़ी स्विच | Accept-Language हेडर / ?lang= पैरामीटर |

## टेक्नोलॉजी स्टैक

| परत | तकनीक | विवरण |
|---|------|------|
| बैकएंड फ्रेमवर्क | webman v2 (workerman) | अति-उच्च प्रदर्शन PHP रेसिडेंट प्रोसेस फ्रेमवर्क |
| PHP संस्करण | 8.3+ | |
| डेटाबेस | MySQL 8.0+ | टेबल उपसर्ग `erik_`, BIGINT बिना ऑटो-इंक्रीमेंट प्राथमिक कुंजी |
| सर्च इंजन | Elasticsearch | `webman-scout` से सिंक और क्वेरी |
| एडमिन फ्रंटएंड | Flutter 3.x | Web डेस्कटॉप एडमिन पैनल शैली में (`apps/flutter/`) |
| मोबाइल | HarmonyOS ArkTS | हारमनीओएस नेटिव क्लाइंट (`apps/harmonyos/`), फ़ोन/टैबलेट/2-इन-1 समर्थन |

## मुख्य निर्भरताएँ

| पैकेज | उपयोग |
|---|------|
| `erikwang2013/snowflake-php` | Snowflake एल्गोरिदम से वैश्विक रूप से अद्वितीय BIGINT प्राथमिक कुंजी उत्पन्न करता है |
| `erikwang2013/hashids` | API परत पर ID एन्क्रिप्शन/डिक्रिप्शन, वास्तविक डेटाबेस ID छिपाता है |
| `erikwang2013/jwt-webman` | JWT प्रमाणीकरण टोकन जारी करना और सत्यापन |
| `erikwang2013/encryption` | इंटरफ़ेस ट्रांसपोर्ट परत पर संवेदनशील डेटा एन्क्रिप्शन/डिक्रिप्शन |
| `erikwang2013/encryptable` | डेटाबेस स्टोरेज परत पर संवेदनशील फ़ील्ड का स्वतः एन्क्रिप्शन/डिक्रिप्शन |
| `erikwang2013/webman-scout` | Elasticsearch डेटा सिंक और फुल-टेक्स्ट सर्च |
| `erikwang2013/season` | देश के झंडों का डेटा |
| `erikwang2013/poster-php` | क्लिक कैप्चा जनरेशन/सत्यापन + पोस्टर जनरेशन |
| `phpoffice/phpspreadsheet` | Excel एक्सपोर्ट |
| `barryvdh/laravel-dompdf` | PDF एक्सपोर्ट (Dompdf पर आधारित) |

## प्रोजेक्ट संरचना

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

## पर्यावरण आवश्यकताएँ

- PHP >= 8.3
- Composer 2.x
- MySQL >= 8.0
- Flutter >= 3.41 (केवल फ्रंटएंड डेवलपमेंट के लिए)
- Elasticsearch >= 7.x (वैकल्पिक, सर्च फ़ंक्शन के लिए आवश्यक)

## त्वरित आरंभ

### 1. निर्भरताएँ इंस्टॉल करें

```bash
composer install
```

### 2. पर्यावरण चर कॉन्फ़िगर करें

पर्यावरण चर कॉपी करके संशोधित करें (वैकल्पिक; कॉन्फ़िगर न करने पर `config/*.php` के डिफ़ॉल्ट मान उपयोग होते हैं):

```bash
cp .env.example .env
```

प्रमुख कॉन्फ़िगरेशन आइटम:

| पर्यावरण चर | विवरण | डिफ़ॉल्ट मान |
|---------|------|--------|
| `JWT_SECRET` | JWT हस्ताक्षर कुंजी | `open-admin-jwt-secret-change-in-production` |
| `HASHIDS_SALT` | Hashids सॉल्ट | `open-admin-hashids-salt-2026` |
| `ENCRYPTION_KEY` | API एन्क्रिप्शन कुंजी | 32-बाइट डिफ़ॉल्ट मान |
| `SNOWFLAKE_DATACENTER_ID` | डेटासेंटर ID (0-31) | `1` |
| `SNOWFLAKE_WORKER_ID` | वर्कर नोड ID (0-31) | `1` |
| `SCOUT_HOSTS` | ES पता | `http://localhost:9200` |

**प्रोडक्शन में सभी कुंजियाँ अनिवार्य रूप से रैंडम स्ट्रिंग्स में बदलें।**

### 3. वन-क्लिक इंस्टॉलेशन

सेवा शुरू करने के बाद ब्राउज़र में इंस्टॉलेशन विज़ार्ड खोलकर डेटाबेस इनिशियलाइज़ेशन और एडमिन निर्माण पूरा करें:

```bash
php start.php start
```

डिफ़ॉल्ट रूप से `http://0.0.0.0:8787` पर लिसन करता है (पोर्ट `config/server.php` में बदला जा सकता है)।

ब्राउज़र में **`http://localhost:8787/install`** खोलें और विज़ार्ड के अनुसार भरें:

| चरण | सामग्री |
|------|------|
| ① डेटाबेस कॉन्फ़िगरेशन | होस्ट पता, पोर्ट, डेटाबेस नाम, उपयोगकर्ता नाम, पासवर्ड |
| ② एडमिन सेटअप | एडमिन उपयोगकर्ता नाम और पासवर्ड (डिफ़ॉल्ट: admin / admin888) |

"इंस्टॉलेशन शुरू करें" पर क्लिक करते ही टेबल निर्माण, अनुमति डेटा सीडिंग, एडमिन खाता निर्माण और `.env` में डेटाबेस कॉन्फ़िगरेशन लिखना स्वतः पूरा हो जाता है।

> इंस्टॉलेशन के बाद `runtime/install.lock` लॉक फ़ाइल बनती है। पुनः इंस्टॉल करने के लिए इस फ़ाइल को हटा दें।

### 4. लॉगिन

`http://localhost:8787` पर जाएँ और इंस्टॉलेशन के समय सेट किए गए एडमिन खाते से लॉगिन करें।

### 5. फ्रंटएंड शुरू करें (वैकल्पिक)

**Flutter एडमिन पैनल (Web):**

```bash
cd apps/flutter
flutter pub get
flutter run -d chrome    # Web 端（PC 管理后台风格）
```

**HarmonyOS क्लाइंट (मोबाइल):**

DevEco Studio में `apps/harmonyos/` डायरेक्टरी खोलें और वास्तविक डिवाइस या एमुलेटर पर चलाएँ।

### 6. Docker Compose वन-क्लिक डिप्लॉयमेंट (प्रोडक्शन के लिए अनुशंसित)

प्रोजेक्ट 5 सेवाओं वाली पूर्ण Docker ऑर्केस्ट्रेशन प्रदान करता है: Nginx, PHP (webman app), MySQL, Redis, Elasticsearch।

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

- `Dockerfile`: PHP 8.3 + OPcache + Composer, `php:8.3-cli` पर आधारित
- `docker-compose.yml`: 5 सेवाओं का ऑर्केस्ट्रेशन, नेटवर्क आइसोलेशन, डेटा वॉल्यूम पर्सिस्टेंस
- `.env.docker`: Docker के लिए विशेष पर्यावरण चर


## डेटाबेस मानक

- **टेबल उपसर्ग**: `erik_`
- **प्राथमिक कुंजी**: सभी टेबलों की प्राथमिक कुंजी `id BIGINT UNSIGNED NOT NULL` है, **AUTO_INCREMENT निषिद्ध**
- **ID जनरेशन**: प्राथमिक कुंजी ID एप्लिकेशन परत के `SnowflakeService::generate()` से उत्पन्न होती है, वितरित रूप से अद्वितीय
- **अनिवार्य फ़ील्ड**: प्रत्येक टेबल में `id`, `created_at`, `updated_at` होना चाहिए
- **सॉफ्ट डिलीट**: जहाँ आवश्यक हो वहाँ `deleted_at DATETIME DEFAULT NULL` जोड़ें
- **संवेदनशील फ़ील्ड**: फ़ोन, ईमेल, आईडी कार्ड नंबर आदि `encryptable` प्लगइन से स्वतः एन्क्रिप्ट/डिक्रिप्ट होते हैं; डेटाबेस फ़ील्ड `VARCHAR(500)` में साइफरटेक्स्ट संग्रहीत करती है

## API संदर्भ

संपूर्ण API विनिर्देश (एकीकृत प्रतिक्रिया प्रारूप, व्यावसायिक त्रुटि कोड, ID प्रबंधन, API संस्करण, रेट लिमिट, मिडलवेयर आर्किटेक्चर, प्रमाणीकरण और कैप्चा प्रवाह) और सभी एंडपॉइंट्स की सूची **[API संदर्भ दस्तावेज़](docs/API.md)** में देखें।

## फ्रंटएंड नोट्स

### Flutter एडमिन पैनल (PC शैली)

- **लेआउट**: फोल्डेबल साइडबार (64px/240px) + टॉप बार + कंटेंट क्षेत्र, तीन रिस्पॉन्सिव ब्रेकपॉइंट (फ़ोन/टैबलेट/डेस्कटॉप)
- **पेज**: लॉगिन, डैशबोर्ड, उपयोगकर्ता प्रबंधन, भूमिकाएँ और अनुमतियाँ, सिस्टम कॉन्फ़िगरेशन, ऑपरेशन लॉग, प्रोफ़ाइल
- **स्टेट प्रबंधन**: GetX (`ApiService` सिंगलटन + `AuthService` टोकन पर्सिस्टेंस)
- **डैशबोर्ड**: स्टैट कार्ड, ट्रेंड लाइन चार्ट (fl_chart), पाई चार्ट, हाल के ऑपरेशन लॉग
- **एक्सपोर्ट**: Excel/PDF एक्सपोर्ट; PDF में हटाने योग्य न होने वाली कॉपीराइट जानकारी होती है
- **बैच ऑपरेशन**: मल्टी-सेलेक्ट बैच डिलीट, बैच सक्षम/अक्षम
- **थीम**: Material 3 लाइट/डार्क दोहरी थीम

### HarmonyOS मोबाइल क्लाइंट

- **पेज**: लॉगिन, डैशबोर्ड, उपयोगकर्ता सूची/विवरण, प्रोफ़ाइल
- **प्रमाणीकरण**: JWT Bearer + 401 पर स्वतः साइलेंट टोकन रीफ़्रेश; असफल होने पर स्वतः लॉगिन पेज पर रीडायरेक्ट
- **स्टोरेज**: टोकन AppStorage से प्रबंधित होता है

## डेवलपमेंट नियम

- ग्लोबल फ़ंक्शन/क्लास संदर्भों में आगे `\` नहीं लगाया जाता, सभी `use` से इम्पोर्ट होते हैं
- सभी PHP फ़ाइलों के शीर्ष पर कॉपीराइट घोषणा होनी चाहिए
- सभी कॉन्फ़िगरेशन फ़ाइलों में चीनी टिप्पणियाँ होनी चाहिए
- डेटाबेस प्राथमिक कुंजी एप्लिकेशन परत के snowflake से उत्पन्न होनी चाहिए; ऑटो-इंक्रीमेंट निषिद्ध है
- API परत के सभी पैरामीटर और प्रतिक्रिया ID hashids से एन्क्रिप्ट/डिक्रिप्ट होने चाहिए
- AdminPermission मिडलवेयर उपयोगकर्ता अनुमतियों को Redis में कैश करता है (TTL=60s), जिससे N+1 क्वेरी बाधा समाप्त होती है

## डिप्लॉयमेंट

### Docker Compose (अनुशंसित)

प्रोजेक्ट रूट में `docker-compose.yml` दिया गया है, जो 5 सेवाओं को ऑर्केस्ट्रेट करता है:

| सेवा | इमेज | पोर्ट |
|------|------|------|
| `nginx` | nginx:alpine | 80, 443 |
| `app` | स्थानीय `Dockerfile` से बिल्ड | 8787 |
| `mysql` | mysql:8.0 | 3306 |
| `redis` | redis:7-alpine | 6379 |
| `elasticsearch` | elasticsearch:8.x | 9200 |

PHP इमेज `Dockerfile` से बनती है, आधार इमेज `php:8.3-cli`, OPcache सक्षम।

```bash
cp .env.docker .env
docker-compose up -d
```

### CI/CD

GitHub Actions CI पाइपलाइन: `.github/workflows/ci.yml`

- PHP सिंटैक्स जाँच (`php -l`)
- PHPUnit यूनिट टेस्ट
- Flutter स्थैतिक विश्लेषण (`flutter analyze`)

### डेटाबेस बैकअप

`database/backup/` डायरेक्टरी:

- `backup.sh` — mysqldump + gzip बैकअप, 30 दिन पुराने बैकअप स्वतः साफ़ होते हैं
- `restore.sh` — इंटरैक्टिव रिस्टोर, चुनने के लिए उपलब्ध बैकअप सूचीबद्ध करता है

### Nginx सुरक्षा कॉन्फ़िगरेशन

प्रोडक्शन डिप्लॉयमेंट के लिए रिवर्स प्रॉक्सी सुरक्षा सख्ती हेतु `docs/nginx-security.conf` देखें।

## ओपन सोर्स आसान नहीं है — समर्थन का स्वागत है

| वीचैट | अलीपे |
|:---:|:---:|
| ![वीचैट](./docs/weixinpay.png "वीचैट") | ![अलीपे](./docs/alipay.png "अलीपे") |

---

## License

MIT

Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
