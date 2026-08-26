# Offene Admin-Konsole — Designdokument

**语言 / Languages:** [中文](DESIGN.md) · [English](DESIGN.en.md) · [한국어](DESIGN.ko.md) · [Русский](DESIGN.ru.md) · [Deutsch](DESIGN.de.md) · [Français](DESIGN.fr.md) · [Español](DESIGN.es.md) · [Português](DESIGN.pt.md) · [हिन्दी](DESIGN.hi.md) · [العربية](DESIGN.ar.md) · [বাংলা](DESIGN.bn.md) · [Bahasa Indonesia](DESIGN.id.md) · [日本語](DESIGN.ja.md)

> Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz

> Detaillierte Mermaid-Architekturdiagramme finden Sie in [ARCHITECTURE.md](ARCHITECTURE.md) (automatisch gerendert in GitHub/GitLab/VS Code).

## 1. Systemarchitektur

> **Funktionsliste**: Authentifizierung (login/register/refresh/logout + Kontosperre + Sitzungslimit) | Dashboard (Redis-Cache) | Benutzer-CRUD + Batch + Import | Rollenberechtigungen (RBAC) | Systemkonfiguration | Operations-Audit (8 Plattformquellen) | Dateien (Upload + Export + Maskierung) | Sicherheit (18 Ebenen Verteidigung) | Betrieb (health/metrics/docs/Docker/CI)

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

## 2. Backend-Architektur

### 2.1 Schichtdesign

| Schicht | Verzeichnis | Verantwortung |
|---|------|------|
| Routing | `config/route.php` | URL-zu-Controller-Zuordnung, Middleware-Bindung, versionierte Routen |
| Middleware | `app/middleware/` | Angriffsabfang (SecurityFilter), Rate-Limit (RateLimit), Authentifizierung (JWT), Autorisierung (RBAC), API-Version (ApiVersion) |
| Controller | 14: Dashboard/User/Role/Permission/Config/Log/Profile/Export/Import/Upload/Health/Docs (Admin) + Captcha/Auth (API v1) | Request-Parameter-Prüfung, Aufruf der Geschäftslogik, Antwortformatierung |
| Geschäftsdienste | `app/service/` | Wiederverwendbare Geschäftslogik (reserviert) |
| Datenmodelle | `app/model/` | ORM-Zuordnung, Beziehungen, Feldver-/Entschlüsselung |
| Gemeinsame Hilfsmittel | `app/common/` | Hashids-, Snowflake-, Encryption-Dienste |

### 2.2 Request-Lebenszyklus

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

### 2.3 ID-Lebenszyklus

```
生成 (Snowflake) → 存储 (MySQL BIGINT) → 传输 (Hashids 编码) → 外部 (hash 字符串)
                                                                    │
                            HashidsService::decode() ←──────────────┘
```

### 2.4 Datenverschlüsselungssystem

```
传输层 (encryption)     — AES-256-CBC，独立密钥
存储层 (encryptable)    — AES-128-ECB，独立密钥，Model $casts 自动处理
展示层 (mask)           — 手机号: 138****1234，邮箱: a***@example.com
```

## 3. Datenbankdesign

### 3.1 ER-Beziehungen

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

### 3.2 Kern-Tabellenstrukturen

| Tabelle | Feldanzahl | Beschreibung |
|------|-------|------|
| `erik_admin_user` | 14 | Administratoren, phone/email/id_card verschlüsselt gespeichert, Soft-Delete unterstützt |
| `erik_admin_role` | 7 | Rollen, slug eindeutig |
| `erik_admin_permission` | 10 | Berechtigungsbaum (parent_id Selbstreferenz), type: 1=Menü 2=Button 3=API |
| `erik_admin_user_role` | 2 | Viele-zu-viele-Zwischentabelle Benutzer-Rolle |
| `erik_admin_role_permission` | 2 | Viele-zu-viele-Zwischentabelle Rolle-Berechtigung |
| `erik_system_config` | 8 | Schlüssel-Wert-Konfiguration, group+key gemeinsam eindeutig |
| `erik_operation_log` | 9 | Operations-Audit-Protokolle (inkl. Quelle source) |

### 3.3 Primärschlüssel-Spezifikation

- Typ: `BIGINT UNSIGNED NOT NULL`
- Eigenschaft: **kein Auto-Increment**, auf Anwendungsebene vom Snowflake-Algorithmus generiert
- Vorteile: global eindeutig, verteilungsfreundlich, trendmäßig steigend für Indexeffizienz, gibt Geschäftsvolumen nicht preis
- Konfiguration: datacenter_id(0-31) + worker_id(0-31), unterstützt 1024 parallele Knoten

## 4. API-Design

### 4.1 URL-Konventionen

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

### 4.2 API-Versionsstrategie

Die API-Version wird über einen Request-Header gesteuert und **nicht im URL-Pfad abgebildet**:

```http
API-Version: v1
```

| Mechanismus | Beschreibung |
|------|------|
| Standardversion | Ohne `API-Version`-Header Standard `v1` |
| Prüfung | Prüfung durch die `ApiVersion`-Middleware; nicht unterstützte Versionen liefern 400 |
| Routing | Die Hilfsfunktion `v()` löst Controller-Klassen versionsabhängig dynamisch auf |
| Verzeichnis | Controller nach Version organisiert: `app/api/{version}/controller/` |

Erweiterungsbeispiel — Hinzufügen einer v2-API:
1. `app/api/v2/controller/AuthController.php` erstellen
2. `'v2'` zur `SUPPORTED`-Konstante der `ApiVersion`-Middleware hinzufügen
3. Keine Änderung der Routendefinitionen nötig

```bash
# 使用 v1
curl -H "API-Version: v1" /api/auth/login

# 使用 v2
curl -H "API-Version: v2" /api/auth/login

# 不传，默认 v1
curl /api/auth/login
```

### 4.3 Rate-Limiting-Strategie

Basiert auf dem Redis-Sorted-Set-Sliding-Window-Algorithmus, ausgeführt als atomares Lua-Skript:

| Schnittstelle | Limit |
|------|------|
| Standard | 60 Mal/Minute/IP/Route |
| POST /api/auth/login | 10 Mal/Minute |
| POST /api/auth/register | 5 Mal/Minute |

Bei Überschreitung wird 429 zurückgegeben; die Response-Header enthalten X-RateLimit-Limit / Remaining / Reset / Retry-After.

### 4.4 Einheitliche Antwort

```json
{
  "code": 0,
  "message": "success",
  "data": { ... }
}
```

| code | Bedeutung | Auslöseszenario |
|------|------|---------|
| 0 | Erfolg | Normale Antwort |
| 400 | Parameterfehler | Falsches Anforderungsformat |
| 401 | Nicht authentifiziert | Token fehlt/abgelaufen/ungültig |
| 403 | Keine Berechtigung | Benutzerrolle enthält die benötigte Berechtigung nicht |
| 404 | Nicht vorhanden | Ressource nicht gefunden |
| 422 | Validierung fehlgeschlagen | Formularparameter verletzen Regeln / Passwortbestätigung fehlgeschlagen |
| 500 | Serverfehler | Unerwartete Ausnahme |

### 4.5 Authentifizierungsablauf (mit Klick-Captcha)

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

### 4.6 Berechtigungsmodell (RBAC)

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

### 4.7 Zweitbestätigung bei sensiblen Operationen

Sensible Operationen wie das Löschen von Benutzern, Rollen und Berechtigungen erfordern die Übergabe des aktuellen Benutzerpassworts im Request-Body zur erneuten Identitätsprüfung:

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

Das Frontend zeigt vor dem Auslösen von Löschoperationen einen Bestätigungsdialog, erfasst das Benutzerpasswort und sendet dann die Anfrage.

## 5. Frontend-Design

### 5.1 Flutter-Web-Admin-Konsole

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

Merkmale: einklappbare Sidebar, Material-3-Doppelthemen, hochdichte Datentabellen, Dialog-Popups, Hover-Interaktionen

### 5.2 HarmonyOS-Mobillient

Seitenrouten:

| Seite | Route | Beschreibung |
|------|------|------|
| LoginPage | `pages/LoginPage` | Benutzername/Passwort + Klick-Captcha-Login |
| DashboardPage | `pages/DashboardPage` | Statistik-Karten + letzte Operationen |
| UserListPage | `pages/UserListPage` | Benutzerliste, Suche + Pull-to-Refresh + Scroll-up-Laden |
| UserDetailPage | `pages/UserDetailPage` | Hinzufügen/Bearbeiten/Anzeigen/Löschen (AlertDialog-Bestätigung) |
| ProfilePage | `pages/ProfilePage` | Profilbereich, Logout (AlertDialog-Bestätigung) |

Datenfluss: Page ← DataService ← ApiService (JWT Bearer) ← HTTP ← webman

## 6. Sicherheitsdesign

### 6.1 Verteidigung in der Tiefe

| Ebene | Maßnahme |
|------|------|
| Methodenbeschränkung | SecurityFilter-HTTP-Methoden-Whitelist, nur GET/POST/PUT/DELETE/OPTIONS/HEAD erlaubt, nicht standardkonforme Methoden liefern 405 |
| Angriffsabfang | SecurityFilter-Middleware, XSS/SQL-Injection/Pfad-Traversal/Command-Injection/CSRF-Erkennung und -Abfang |
| Menschliche Verifikation | Klick-Captcha, bei Login/Registrierung verpflichtend |
| Kontosperre | 5 aufeinanderfolgende Login-Fehler sperren das Konto für 15 Minuten; während der Sperre wird 429 zurückgegeben |
| Sitzungslimit | Max. 3 parallele Token pro Benutzer; bei Überschreitung wird das älteste Token automatisch in die Blacklist aufgenommen |
| Rate-Limit | RateLimit-Middleware, Redis-Sliding-Window, atomares Lua |
| CSP | Content-Security-Policy-Header begrenzt Ressourcenquellen, verhindert XSS und Dateninjektion |
| Operationsbestätigung | Sensible Operationen wie Löschen erfordern erneute Eingabe des aktuellen Benutzerpassworts |
| Transport | HTTPS + JWT Bearer Token |
| Schnittstellen-ID | Hashids-Verschlüsselung, echte IDs extern nicht rückrechenbar |
| Request-Body | AES-256-CBC-Verschlüsselung sensibler Felder |
| Datenbank | BIGINT-Primärschlüssel (Auto-Increment nicht preisgegeben) |
| Datenbank | AES-128-ECB-verschlüsselte Speicherung sensibler Felder |
| Authentifizierung | JWT HS256, 2h Ablauf + Refresh-Token |
| Autorisierung | RBAC, method.path-Granularitätsberechtigungskontrolle |
| Audit | OperationLog protokolliert alle Operationen (inkl. automatischer Quellenerkennung source) |

### 6.2 Schlüsselverwaltung

```
JWT_SECRET          → 环境变量注入，64位随机字符串
HASHIDS_SALT        → 唯一盐值，泄漏后需全局更换
ENCRYPTION_KEY      → API 传输加密密钥，32字节
ENCRYPTABLE_KEY     → DB 存储加密密钥，与传输密钥独立
SCOUT_HOSTS         → ES 地址，内网部署
```

### 6.3 Schutz sensibler Daten

| Szenario | Feld | Maßnahme |
|------|------|------|
| Listenanzeige | phone | Maskiert: 138****1234 |
| Listenanzeige | email | Maskiert: a***@example.com |
| Detailansicht | phone/email | Entschlüsselungs-Schnittstelle erforderlich |
| Excel-Export | phone/email | Maskiert exportieren |
| PDF-Export | Alle Felder | Maskiert + nicht entfernbares Copyright-Wasserzeichen |
| Speicherung | phone/email/id_card | encryptable verschlüsselt zu Chiffretext |

## 7. Export-Design

### 7.1 Excel-Export

```
请求: POST /admin/export/excel { table, columns, conditions, title }
  → fetchExportData() 查询数据 (limit 10000)
  → 脱敏敏感字段
  → PhpSpreadsheet 构建（蓝底白字表头 + 冻结首行 + 自动筛选）
  → 写入 runtime/tmp/ → download 响应
```

### 7.2 PDF-Export

```
请求: POST /admin/export/pdf { type: table|dashboard, title, data }
  → buildPdfHtml() HTML + 内联CSS + 页头版权 + 页脚不可移除版权
  → Dompdf 渲染 A4 横向
  → 写入 runtime/tmp/ → download 响应
```

## 8. Deployment-Architektur

### 8.1 Empfohlene Topologie

```
Nginx (:443 HTTPS) → webman worker × N (:8787) → MySQL + ES + Redis
                    静态文件: Flutter Web build/
```

### 8.2 Docker Compose (für Produktion empfohlen)

Die `docker-compose.yml` im Projektstamm orchestriert alle Dienste der obigen Topologie:

| Dienst | Image/Build | Ports | Beschreibung |
|------|----------|------|------|
| `nginx` | nginx:alpine | 80, 443 | Reverse-Proxy + statische Dateien + Gzip |
| `app` | Build aus lokalem `Dockerfile` | 8787 | PHP 8.3 + OPcache + webman |
| `mysql` | mysql:8.0 | 3306 | Hauptdatenbank, Datenvolumen-Persistenz |
| `redis` | redis:7-alpine | 6379 | Cache / Rate-Limit / Captcha |
| `elasticsearch` | elasticsearch:8.x | 9200 | Volltextsuche |

Ersetzen Sie vor dem Start in `docker-compose.yml` Schlüssel wie `JWT_SECRET`, `HASHIDS_SALT`, `ENCRYPTION_KEY` durch Zufallszeichenfolgen.

```bash
cp .env.docker .env
docker-compose up -d
```

### 8.3 CI/CD

Continuous Integration über GitHub Actions ist in `.github/workflows/ci.yml` definiert:
- PHP-Syntaxprüfung (`php -l`)
- PHPUnit-Unit-Tests
- Flutter-Statikanalyse (`flutter analyze`)

### 8.4 Datenbank-Backup

`database/backup/backup.sh` — mysqldump + gzip-Backup, automatische Bereinigung von Backups älter als 30 Tage.
`database/backup/restore.sh` — interaktive Auswahl und Wiederherstellung von Backups.

### 8.5 Monitoring

Der Endpunkt `GET /metrics` (`MetricsController`) exponiert 5 Gauge-Metriken im Prometheus-Textformat: Gesamtzahl der HTTP-Anfragen, aktive Benutzer, Verbindungsstatus Datenbank/Redis, Speichernutzung.

### 8.6 Umgebungsanforderungen

| Komponente | Mindestversion | Empfohlene Konfiguration |
|------|---------|---------|
| PHP | 8.3+ | 8.3+ mit aktiviertem OPcache |
| MySQL | 8.0+ | 8.0+ Master-Slave-Replikation |
| Elasticsearch | 7.x | 8.x 3-Knoten-Cluster |
| Redis | 6.x | 7.x Sentinel-Modus |
| Nginx | 1.20+ | Reverse-Proxy + gzip + SSL |
| Flutter SDK | 3.41+ | Neueste stabile Version |
| HarmonyOS | API 12 | DevEco Studio 5.x |
