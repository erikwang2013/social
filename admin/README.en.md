# Open Admin (open-admin)
**语言 / Languages:** [中文](README.md) · [English](README.en.md) · [한국어](README.ko.md) · [Русский](README.ru.md) · [Deutsch](README.de.md) · [Français](README.fr.md) · [Español](README.es.md) · [Português](README.pt.md) · [हिन्दी](README.hi.md) · [العربية](README.ar.md) · [বাংলা](README.bn.md) · [Bahasa Indonesia](README.id.md) · [日本語](README.ja.md)

A full-stack admin dashboard built on webman v2 + Flutter.

> [English version](README_EN.md) | [Architecture Diagrams](docs/ARCHITECTURE.md) | [Design Doc](docs/DESIGN.md) | [Security Architecture](docs/SECURITY.md) | [API Reference](docs/API.md)

## Features

| Domain | Feature | Notes |
|--------|------|------|
| 🔐 Auth | Login / refresh token / logout | Click captcha + JWT + blacklist |
| | Account lockout | 5 failed attempts lock for 15 minutes |
| | Concurrent session limit | Max 3 valid tokens per user |
| 📊 Dashboard | Real-time stats / trend chart / distribution / recent activity | Redis cached for 5 minutes |
| 👥 User Management | CRUD + batch delete / enable-disable | Soft delete + password confirmation |
| | Excel batch import | Row-by-row validation + error report |
| 🔒 Roles & Permissions | Role CRUD + permission tree | RBAC method.path granularity authorization |
| ⚙ System Config | Key-value CRUD | Grouped management |
| 📋 Operation Audit | Log query + client source detection | 8 platforms auto-detected |
| 📁 File Management | Upload / Excel export / PDF export | Sensitive data auto-masked |
| 🛡 Security | 18-layer defense-in-depth | XSS/SQL injection/path traversal/command injection/CSRF/rate limit/CSP... |
| 🏥 Ops | Health check / metrics / API docs / security.txt | Prometheus + OpenAPI 3.0 + hg/apidoc interactive docs |
| 🌐 i18n | Chinese/English toggle | Accept-Language header / ?lang= param |

## Tech Stack

| Layer | Technology | Notes |
|---|------|------|
| Backend framework | webman v2 (workerman) | Ultra-high-performance persistent PHP process framework |
| PHP version | 8.3+ | |
| Database | MySQL 8.0+ | Table prefix `erik_`, BIGINT non-auto-increment primary keys |
| Search engine | Elasticsearch | Synced and queried via `webman-scout` |
| Admin frontend | Flutter 3.x | Web renders as a desktop admin panel (`apps/flutter/`) |
| Mobile | HarmonyOS ArkTS | Native HarmonyOS client (`apps/harmonyos/`), supports phone/tablet/2in1 |

## Core Dependencies

| Package | Purpose |
|---|------|
| `erikwang2013/snowflake-php` | Snowflake algorithm generating globally unique BIGINT primary keys |
| `erikwang2013/hashids` | API-layer ID encryption/decryption, hides real database IDs |
| `erikwang2013/jwt-webman` | JWT token issuance and verification |
| `erikwang2013/encryption` | Sensitive data encryption/decryption at the transport layer |
| `erikwang2013/encryptable` | Automatic encryption/decryption of sensitive DB fields |
| `erikwang2013/webman-scout` | Elasticsearch data sync and full-text search |
| `erikwang2013/season` | Country flag data |
| `erikwang2013/poster-php` | Click captcha generation/verification + poster generation |
| `phpoffice/phpspreadsheet` | Excel export |
| `barryvdh/laravel-dompdf` | PDF export (based on Dompdf) |

## Project Structure

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

## Requirements

- PHP >= 8.3
- Composer 2.x
- MySQL >= 8.0
- Flutter >= 3.41 (frontend development only)
- Elasticsearch >= 7.x (optional, required for search)

## Quick Start

### 1. Install Dependencies

```bash
composer install
```

### 2. Configure Environment Variables

Copy and modify the environment variables (optional; if not set, the defaults in `config/*.php` are used):

```bash
cp .env.example .env
```

Key configuration items:

| Environment Variable | Description | Default |
|---------|------|--------|
| `JWT_SECRET` | JWT signing secret | `open-admin-jwt-secret-change-in-production` |
| `HASHIDS_SALT` | Hashids salt | `open-admin-hashids-salt-2026` |
| `ENCRYPTION_KEY` | API encryption key | 32-byte default |
| `SNOWFLAKE_DATACENTER_ID` | Datacenter ID (0-31) | `1` |
| `SNOWFLAKE_WORKER_ID` | Worker ID (0-31) | `1` |
| `SCOUT_HOSTS` | ES address | `http://localhost:9200` |

**In production, always change all secrets to random strings.**

### 3. One-Click Install

After starting the service, open the install wizard in a browser to initialize the database and create the admin account:

```bash
php start.php start
```

Listens on `http://0.0.0.0:8787` by default (the port can be changed in `config/server.php`).

Open **`http://localhost:8787/install`** in a browser and fill in the wizard:

| Step | Contents |
|------|------|
| ① Database config | Host, port, database name, username, password |
| ② Admin setup | Admin username and password (default: admin / admin888) |

Click "Start Install" and the system automatically creates the tables, seeds permission data, creates the admin account, and writes the database config to `.env`.

> After installation, a `runtime/install.lock` lock file is generated. Delete this file to re-install.

### 4. Login

Visit `http://localhost:8787` and log in with the admin credentials set during installation.

### 5. Start the Frontend (Optional)

**Flutter admin panel (Web):**

```bash
cd apps/flutter
flutter pub get
flutter run -d chrome    # Web 端（PC 管理后台风格）
```

**HarmonyOS client (Mobile):**

Open the `apps/harmonyos/` directory with DevEco Studio and run it on a real device or emulator.

### 6. One-Click Deployment with Docker Compose (Recommended for Production)

The project provides a complete Docker orchestration with 5 services: Nginx, PHP (webman app), MySQL, Redis, Elasticsearch.

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

- `Dockerfile`: PHP 8.3 + OPcache + Composer, based on `php:8.3-cli`
- `docker-compose.yml`: orchestrates 5 services, network isolation, persistent data volumes
- `.env.docker`: environment variables specific to Docker


## Database Conventions

- **Table prefix**: `erik_`
- **Primary key**: all primary keys are `id BIGINT UNSIGNED NOT NULL`, **AUTO_INCREMENT is disabled**
- **ID generation**: primary key IDs are generated by `SnowflakeService::generate()` at the application layer, distributed and unique
- **Required fields**: every table must include `id`, `created_at`, `updated_at`
- **Soft delete**: tables that need soft delete add `deleted_at DATETIME DEFAULT NULL`
- **Sensitive fields**: phone, email, ID card numbers, etc. are encrypted/decrypted automatically by the `encryptable` plugin; DB columns use `VARCHAR(500)` to store ciphertext

## API Reference

For the complete API specification (unified response format, business error codes, ID handling, API versioning, rate limiting, middleware architecture, authentication and captcha flows) and the full endpoint list, see the **[API Reference](docs/API.md)**.

## Frontend Notes

### Flutter Admin Panel (Desktop Style)

- **Layout**: collapsible sidebar (64px/240px) + header + content area, three responsive breakpoints (phone/tablet/desktop)
- **Pages**: login, dashboard, user management, roles & permissions, system config, operation logs, profile
- **State management**: GetX (`ApiService` singleton + `AuthService` token persistence)
- **Dashboard**: stat cards, trend line chart (fl_chart), pie chart, recent operation logs
- **Export**: Excel/PDF export; PDFs include non-removable copyright info
- **Batch operations**: multi-select batch delete, batch enable/disable
- **Theme**: Material 3 light/dark dual theme

### HarmonyOS Mobile Client

- **Pages**: login, dashboard, user list/detail, profile
- **Auth**: JWT Bearer + silent token refresh on 401; auto-redirect to login on refresh failure
- **Storage**: tokens managed via AppStorage

## Development Rules

- No leading `\` on global function/class references - always import them with `use`
- All PHP files must include the copyright notice at the top
- All config files must include Chinese comments
- Database primary keys must be generated by snowflake at the application layer; auto-increment is forbidden
- All IDs in API parameters and responses must be encrypted/decrypted with hashids
- The AdminPermission middleware caches user permissions in Redis (TTL=60s), eliminating the N+1 query bottleneck

## Deployment

### Docker Compose (Recommended)

The project root provides `docker-compose.yml`, orchestrating 5 services:

| Service | Image | Port |
|------|------|------|
| `nginx` | nginx:alpine | 80, 443 |
| `app` | Built from local `Dockerfile` | 8787 |
| `mysql` | mysql:8.0 | 3306 |
| `redis` | redis:7-alpine | 6379 |
| `elasticsearch` | elasticsearch:8.x | 9200 |

The PHP image is built from `Dockerfile`, base image `php:8.3-cli`, with OPcache enabled.

```bash
cp .env.docker .env
docker-compose up -d
```

### CI/CD

GitHub Actions CI pipeline: `.github/workflows/ci.yml`

- PHP syntax check (`php -l`)
- PHPUnit unit tests
- Flutter static analysis (`flutter analyze`)

### Database Backup

`database/backup/` directory:

- `backup.sh` - mysqldump + gzip backup, auto-cleans backups older than 30 days
- `restore.sh` - interactive restore, lists available backups to choose from

### Nginx Security Configuration

For production, refer to `docs/nginx-security.conf` for reverse-proxy security hardening.

## Open Source Is Hard - Support Welcome

| WeChat | Alipay |
|:---:|:---:|
| ![WeChat](./docs/weixinpay.png "WeChat") | ![Alipay](./docs/alipay.png "Alipay") |

---

## License

MIT

Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
