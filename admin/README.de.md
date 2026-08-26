# Offenes Admin-Panel (open-admin)
**语言 / Languages:** [中文](README.md) · [English](README.en.md) · [한국어](README.ko.md) · [Русский](README.ru.md) · [Deutsch](README.de.md) · [Français](README.fr.md) · [Español](README.es.md) · [Português](README.pt.md) · [हिन्दी](README.hi.md) · [العربية](README.ar.md) · [বাংলা](README.bn.md) · [Bahasa Indonesia](README.id.md) · [日本語](README.ja.md)

Ein Full-Stack-Admin-Panel auf Basis von webman v2 + Flutter.

> [English version](README_EN.md) | [Architekturdiagramme](docs/ARCHITECTURE.md) | [Design-Dokument](docs/DESIGN.md) | [Sicherheitsarchitektur](docs/SECURITY.md) | [API-Referenz](docs/API.md)

## Funktionsübersicht

| Bereich | Funktion | Beschreibung |
|--------|------|------|
| 🔐 Authentifizierung | Login / Token-Erneuerung / Logout | Klick-Captcha + JWT + Blacklist |
| | Kontosperrung | 5 Fehlversuche sperren 15 Minuten |
| | Begrenzung paralleler Sitzungen | Max. 3 gültige Token pro Benutzer |
| 📊 Dashboard | Echtzeitstatistik / Trenddiagramm / Verteilung / letzte Aktivitäten | Redis-Cache 5 Minuten |
| 👥 Benutzerverwaltung | CRUD + Batch-Löschen / Aktiv-Deaktivieren | Soft Delete + Passwortbestätigung |
| | Excel-Batchimport | Zeilenweise Validierung + Fehlerbericht |
| 🔒 Rollen & Rechte | Rollen-CRUD + Berechtigungsbaum | RBAC-Autorisierung in method.path-Granularität |
| ⚙ Systemkonfiguration | Schlüssel-Wert-CRUD | Gruppenverwaltung |
| 📋 Betriebsprüfung | Logabfrage + Erkennung der Quelle | 8 Plattformen automatisch erkannt |
| 📁 Dateiverwaltung | Upload / Excel-Export / PDF-Export | Automatische Maskierung sensibler Daten |
| 🛡 Sicherheit | 18 Ebenen Verteidigung in der Tiefe | XSS/SQL-Injection/Pfad-Traversal/Befehlsinjektion/CSRF/Rate-Limit/CSP... |
| 🏥 Betrieb | Health Check / metrics / API-Dokumentation / security.txt | Prometheus + OpenAPI 3.0 + interaktive hg/apidoc-Dokumentation |
| 🌐 Internationalisierung | Chinesisch/Englisch umschaltbar | Accept-Language-Header / ?lang=-Parameter |

## Technologie-Stack

| Ebene | Technologie | Beschreibung |
|---|------|------|
| Backend-Framework | webman v2 (workerman) | Hochleistungs-PHP-Framework mit residenten Prozessen |
| PHP-Version | 8.3+ | |
| Datenbank | MySQL 8.0+ | Tabellenpräfix `erik_`, BIGINT-Primärschlüssel ohne Auto-Increment |
| Suchmaschine | Elasticsearch | Synchronisierung und Abfrage über `webman-scout` |
| Admin-Frontend | Flutter 3.x | Web im Desktop-Admin-Panel-Stil (`apps/flutter/`) |
| Mobil | HarmonyOS ArkTS | Nativer HarmonyOS-Client (`apps/harmonyos/`), unterstützt Handy/Tablet/2in1 |

## Kernabhängigkeiten

| Paket | Zweck |
|---|------|
| `erikwang2013/snowflake-php` | Global eindeutige BIGINT-Primärschlüssel per Snowflake-Algorithmus |
| `erikwang2013/hashids` | ID-Verschlüsselung auf API-Ebene, verbirgt echte Datenbank-IDs |
| `erikwang2013/jwt-webman` | Ausstellung und Prüfung von JWT-Tokens |
| `erikwang2013/encryption` | Ver-/Entschlüsselung sensibler Daten auf Übertragungsebene |
| `erikwang2013/encryptable` | Automatische Ver-/Entschlüsselung sensibler DB-Felder |
| `erikwang2013/webman-scout` | Elasticsearch-Synchronisierung und Volltextsuche |
| `erikwang2013/season` | Länderflaggen-Daten |
| `erikwang2013/poster-php` | Klick-Captcha-Erzeugung/-Prüfung + Poster-Erzeugung |
| `phpoffice/phpspreadsheet` | Excel-Export |
| `barryvdh/laravel-dompdf` | PDF-Export (auf Basis von Dompdf) |

## Projektstruktur

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

## Umgebungsanforderungen

- PHP >= 8.3
- Composer 2.x
- MySQL >= 8.0
- Flutter >= 3.41 (nur für Frontend-Entwicklung nötig)
- Elasticsearch >= 7.x (optional, für die Suche erforderlich)

## Schnellstart

### 1. Abhängigkeiten installieren

```bash
composer install
```

### 2. Umgebungsvariablen konfigurieren

Umgebungsvariablen kopieren und anpassen (optional; ohne Konfiguration gelten die Standardwerte aus `config/*.php`):

```bash
cp .env.example .env
```

Wichtige Konfigurationsoptionen:

| Umgebungsvariable | Beschreibung | Standard |
|---------|------|--------|
| `JWT_SECRET` | JWT-Signaturschlüssel | `open-admin-jwt-secret-change-in-production` |
| `HASHIDS_SALT` | Hashids-Salt | `open-admin-hashids-salt-2026` |
| `ENCRYPTION_KEY` | API-Verschlüsselungsschlüssel | 32-Byte-Standardwert |
| `SNOWFLAKE_DATACENTER_ID` | Rechenzentrums-ID (0-31) | `1` |
| `SNOWFLAKE_WORKER_ID` | Worker-Knoten-ID (0-31) | `1` |
| `SCOUT_HOSTS` | ES-Adresse | `http://localhost:9200` |

**In der Produktion müssen alle Schlüssel unbedingt durch zufällige Zeichenfolgen ersetzt werden.**

### 3. Ein-Klick-Installation

Nach dem Start des Dienstes im Browser den Installationsassistenten öffnen, um Datenbank-Initialisierung und Admin-Erstellung abzuschließen:

```bash
php start.php start
```

Lauscht standardmäßig auf `http://0.0.0.0:8787` (Port in `config/server.php` änderbar).

Im Browser **`http://localhost:8787/install`** öffnen und dem Assistenten folgen:

| Schritt | Inhalt |
|------|------|
| ① Datenbankkonfiguration | Host, Port, Datenbankname, Benutzername, Passwort |
| ② Admin-Einrichtung | Admin-Benutzername und -Passwort (Standard: admin / admin888) |

Nach Klick auf „Installation starten" werden automatisch Tabellen angelegt, Berechtigungsdaten eingesät, das Admin-Konto erstellt und die DB-Konfiguration in `.env` geschrieben.

> Nach der Installation wird die Sperrdatei `runtime/install.lock` erzeugt. Zum Neuinstallieren einfach diese Datei löschen.

### 4. Anmelden

`http://localhost:8787` aufrufen und mit den bei der Installation festgelegten Admin-Zugangsdaten anmelden.

### 5. Frontend starten (optional)

**Flutter-Admin-Panel (Web):**

```bash
cd apps/flutter
flutter pub get
flutter run -d chrome    # Web 端（PC 管理后台风格）
```

**HarmonyOS-Client (Mobil):**

Das Verzeichnis `apps/harmonyos/` mit DevEco Studio öffnen und auf einem Gerät oder Emulator ausführen.

### 6. Ein-Klick-Deployment mit Docker Compose (für Produktion empfohlen)

Das Projekt bietet eine vollständige Docker-Orchestrierung mit 5 Diensten: Nginx, PHP (webman app), MySQL, Redis, Elasticsearch.

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

- `Dockerfile`: PHP 8.3 + OPcache + Composer, basierend auf `php:8.3-cli`
- `docker-compose.yml`: Orchestrierung von 5 Diensten, Netzwerkisolation, persistente Datenvolumes
- `.env.docker`: umgebungsspezifische Variablen für Docker


## Datenbank-Konventionen

- **Tabellenpräfix**: `erik_`
- **Primärschlüssel**: alle Tabellen verwenden `id BIGINT UNSIGNED NOT NULL`, **AUTO_INCREMENT ist deaktiviert**
- **ID-Erzeugung**: Primärschlüssel werden auf Anwendungsebene über `SnowflakeService::generate()` erzeugt, verteilt eindeutig
- **Pflichtfelder**: jede Tabelle muss `id`, `created_at`, `updated_at` enthalten
- **Soft Delete**: Tabellen mit Soft Delete erhalten `deleted_at DATETIME DEFAULT NULL`
- **Sensible Felder**: Handy, E-Mail, Ausweisnummern usw. werden automatisch per `encryptable`-Plugin ver-/entschlüsselt; DB-Felder speichern den Chiffretext in `VARCHAR(500)`

## API-Referenz

Die vollständige API-Spezifikation (einheitliches Antwortformat, Geschäftsfehlercodes, ID-Verarbeitung, API-Versionierung, Rate-Limiting, Middleware-Architektur, Authentifizierungs- und Captcha-Abläufe) sowie die vollständige Endpunktliste finden Sie in der **[API-Referenz](docs/API.md)**.

## Frontend-Hinweise

### Flutter-Admin-Panel (Desktop-Stil)

- **Layout**: einklappbare Seitenleiste (64px/240px) + Kopfzeile + Inhaltsbereich, drei responsive Breakpoints (Handy/Tablet/Desktop)
- **Seiten**: Login, Dashboard, Benutzerverwaltung, Rollen & Rechte, Systemkonfiguration, Betriebsprotokolle, Profil
- **State-Management**: GetX (`ApiService`-Singleton + `AuthService`-Token-Persistenz)
- **Dashboard**: Statistik-Karten, Trend-Liniendiagramm (fl_chart), Kreisdiagramm, letzte Betriebsprotokolle
- **Export**: Excel/PDF-Export; PDFs enthalten nicht entfernbare Urheberrechtshinweise
- **Batch-Operationen**: Mehrfachauswahl-Batch-Löschen, Batch-Aktivieren/Deaktivieren
- **Design**: Material 3, helles/dunkles Dual-Theme

### HarmonyOS-Mobilclient

- **Seiten**: Login, Dashboard, Benutzerliste/-details, Profil
- **Authentifizierung**: JWT Bearer + automatische stille Token-Erneuerung bei 401, bei Fehlschlag automatische Weiterleitung zur Login-Seite
- **Speicherung**: Token wird über AppStorage verwaltet

## Entwicklungsregeln

- Globale Funktionen/Klassen ohne führendes `\` referenzieren, einheitlich mit `use` importieren
- Alle PHP-Dateien müssen den Urheberrechtshinweis im Kopf enthalten
- Alle Konfigurationsdateien müssen chinesische Kommentare enthalten
- Datenbank-Primärschlüssel müssen auf Anwendungsebene per snowflake erzeugt werden; Auto-Increment ist verboten
- Alle IDs in API-Parametern und -Antworten müssen über hashids ver-/entschlüsselt werden
- Die AdminPermission-Middleware cached Benutzerrechte in Redis (TTL=60s) und beseitigt damit den N+1-Abfrage-Engpass

## Deployment

### Docker Compose (empfohlen)

Im Projektstamm liegt `docker-compose.yml`, das 5 Dienste orchestriert:

| Dienst | Image | Port |
|------|------|------|
| `nginx` | nginx:alpine | 80, 443 |
| `app` | Build aus lokalem `Dockerfile` | 8787 |
| `mysql` | mysql:8.0 | 3306 |
| `redis` | redis:7-alpine | 6379 |
| `elasticsearch` | elasticsearch:8.x | 9200 |

Das PHP-Image wird über `Dockerfile` gebaut, Basis `php:8.3-cli`, mit aktiviertem OPcache.

```bash
cp .env.docker .env
docker-compose up -d
```

### CI/CD

GitHub-Actions-CI-Pipeline: `.github/workflows/ci.yml`

- PHP-Syntaxprüfung (`php -l`)
- PHPUnit-Unit-Tests
- Flutter-Statikanalyse (`flutter analyze`)

### Datenbank-Backup

Verzeichnis `database/backup/`:

- `backup.sh` — mysqldump + gzip-Backup, automatische Bereinigung von Backups älter als 30 Tage
- `restore.sh` — interaktive Wiederherstellung, listet verfügbare Backups zur Auswahl auf

### Nginx-Sicherheitskonfiguration

Für Produktions-Deployments siehe `docs/nginx-security.conf` zur Härtung des Reverse-Proxys.

## Open Source ist ein harter Weg — Unterstützung willkommen

| WeChat | Alipay |
|:---:|:---:|
| ![WeChat](./docs/weixinpay.png "WeChat") | ![Alipay](./docs/alipay.png "Alipay") |

---

## License

MIT

Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
