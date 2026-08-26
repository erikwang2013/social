# API-Referenz
**语言 / Languages:** [中文](API.md) · [English](API.en.md) · [한국어](API.ko.md) · [Русский](API.ru.md) · [Deutsch](API.de.md) · [Français](API.fr.md) · [Español](API.es.md) · [Português](API.pt.md) · [हिन्दी](API.hi.md) · [العربية](API.ar.md) · [বাংলা](API.bn.md) · [Bahasa Indonesia](API.id.md) · [日本語](API.ja.md)

> Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz

## 1. Überblick

Das Open-Admin-Verwaltungssystem basiert auf webman v2 und bietet eine RESTful JSON API. Alle Admin-Endpunkte erfordern JWT-Authentifizierung und RBAC-Berechtigungsprüfungen; öffentliche Endpunkte werden über den API-Versions-Header an versionierte Controller weitergeleitet.

- **Basis-URL**: `http://localhost:8787`
- **API-Version**: gesteuert über den Request-Header `API-Version: v1` (Standard v1, falls fehlt)
- **Sprache**: umschaltbar über den `Accept-Language`-Header oder den Parameter `?lang=zh_CN|en` (Standard zh_CN), automatisch erkannt durch die Locale-Middleware

> **Endpunktübersicht**: Authentifizierung(5) | Dashboard(1) | Benutzer(7) | Rollen(4) | Berechtigungen(4) | Konfiguration(4) | Logs(1) | Profil(3) | Import/Export(3) | Upload(1) | Betrieb(4: health/metrics/docs/security.txt) | insgesamt 37 Endpunkte
- **Authentifizierung**: `Authorization: Bearer <token>` (JWT)
- **Antwortformat**: `{ "code": 0, "message": "success", "data": {...} }`
- **Dokumentations-Endpunkt**: `GET /api/docs` liefert die OpenAPI-3.0-JSON-Spezifikation

### Anforderungen an Anfragen

- Nur `GET` / `POST` / `PUT` / `DELETE` / `OPTIONS` / `HEAD` sind erlaubt; andere HTTP-Methoden (z. B. TRACE, CONNECT, PATCH) liefern 405
- Alle `POST` / `PUT`-Anfragen müssen `Content-Type: application/json` setzen (außer Datei-Uploads), sonst wird 415 zurückgegeben
- Der Request-Body darf 10MB nicht überschreiten, sonst wird 413 zurückgegeben
- Der Sicherheitsfilter scannt alle Eingaben auf XSS, SQL-Injection, Pfad-Traversal und Command-Injection; Treffer liefern 403
- 5 fehlgeschlagene Login-Versuche in Folge lösen eine Kontosperre aus (15 Minuten); Login-Anfragen während der Sperre liefern 429
- Ein Benutzer kann höchstens 3 gültige Tokens gleichzeitig halten; bei Überschreitung wird das älteste Token automatisch auf die Blacklist gesetzt

## 2. Fehlercodes

| code | Bedeutung | Auslöser |
|------|------|---------|
| 0 | Erfolg | |
| 400 | Ungültige Anfrageparameter | Anfrageformat ist falsch |
| 401 | Nicht authentifiziert | Token fehlt / abgelaufen / auf Blacklist |
| 403 | Keine Berechtigung / Sicherheitsblock | Unzureichende RBAC-Rechte / SecurityFilter-Treffer |
| 404 | Ressource nicht gefunden | Ziel von Abfrage/Update/Löschen existiert nicht |
| 405 | Methode nicht erlaubt | Nur GET/POST/PUT/DELETE/OPTIONS/HEAD erlaubt; nicht standardkonforme Methoden werden abgelehnt |
| 413 | Request-Body zu groß | Content-Length überschreitet 10MB |
| 415 | Nicht unterstützter Medientyp | Content-Type von POST/PUT ist weder JSON noch Datei-Upload |
| 422 | Parametervalidierung fehlgeschlagen | Pflichtfelder fehlen, falsches Format oder Geschäftsvalidierung fehlgeschlagen |
| 429 | Zu viele Anfragen | RateLimit ausgelöst / Kontosperre (5 fehlgeschlagene Logins in Folge sperren 15 Minuten) |
| 500 | Interner Serverfehler | |

## 3. Öffentliche Endpunkte

Alle öffentlichen Endpunkte sind unter der Gruppe `/api` gemountet; die `ApiVersion`-Middleware leitet sie anhand des `API-Version`-Headers an den passenden versionierten Controller weiter (z. B. `app\api\v1\controller\AuthController`).

### 3.1 Health-Check

```
GET /health
```

- **Authentifizierung**: keine erforderlich
- **Rate-Limit**: keines

**Antwortbeispiel**:
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

Werte von `database`, `redis`, `elasticsearch`: `"ok"` | `"unavailable"`. `elasticsearch` liefert `"unavailable"`, wenn ES nicht erreichbar ist; ist der Cluster-Status nicht green/yellow, wird der tatsächliche status-Wert zurückgegeben (z. B. `"red"`).

### 3.2 API-Dokumentation

```
GET /api/docs
```

- **Authentifizierung**: keine erforderlich
- **Rate-Limit**: globaler Standard (60/Min.)
- **Antwort**: OpenAPI-3.0.3-JSON-Spezifikation mit allen Endpunkt-Definitionen, Parametern und Schemas

### 3.3 Captcha generieren

```
POST /api/captcha/generate
```

- **Authentifizierung**: keine erforderlich
- **Request-Header**: `API-Version: v1` (erforderlich)
- **Rate-Limit**: globaler Standard (60/Min.)

**Request-Body**:
```json
{
  "difficulty": "medium"
}
```

| Feld | Typ | Erforderlich | Beschreibung |
|------|------|------|------|
| difficulty | string | Nein | `easy` / `medium` / `hard`, Standard `medium` |

**Antwortbeispiel** — Klick-Typ (`type: "click"`):
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

**Antwortbeispiel** — Slider-Typ (`type: "slider"`):
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

**Antwortbeispiel** — Rotations-Typ (`type: "rotate"`):
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

| Feld | Typ | Beschreibung |
|------|------|------|
| key | string | Captcha-Kennung, wird bei der Prüfung zurückgesendet |
| type | string | Captcha-Typ: `click` / `slider` / `rotate` |
| image | string | Bild als base64-data-URI |
| extra | object | Typspezifische Zusatzdaten (siehe unten) |

**`extra` nach Typ**:

| type | extra-Felder | Typ | Beschreibung |
|------|-----------|------|------|
| click | targets | array | Klickziele mit `order` (Reihenfolge), `text` (Hinweistext), `x`, `y` (Koordinaten) |
| slider | x, y | int | Koordinaten der oberen linken Ecke der Lücke (bezogen auf 300×200-Canvas) |
| slider | puzzle_w, puzzle_h | int | Breite und Höhe des Puzzle-Bilds |
| slider | puzzle | string | Puzzle-Bild als base64-data-URI |
| rotate | angle | int | Korrekter Rotationswinkel (0-359); um `360-angle` drehen, um das Bild auszurichten |

### 3.4 Captcha prüfen

```
POST /api/captcha/verify
```

- **Authentifizierung**: keine erforderlich
- **Request-Header**: `API-Version: v1` (erforderlich)
- **Rate-Limit**: globaler Standard (60/Min.)

**Request-Body** — Klick-Typ (`type: "click"`):
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

**Request-Body** — Slider-Typ (`type: "slider"`):
```json
{
  "key": "def456abc789",
  "type": "slider",
  "clicks": 120
}
```

**Request-Body** — Rotations-Typ (`type: "rotate"`):
```json
{
  "key": "ghi789abc012",
  "type": "rotate",
  "clicks": 315
}
```

| Feld | Typ | Erforderlich | Beschreibung |
|------|------|------|------|
| key | string | Ja | Captcha-Key, von generate zurückgegeben |
| type | string | Ja | Captcha-Typ, muss dem von generate zurückgegebenen `type` entsprechen |
| clicks | Variante | Ja | Antwortdaten, Format variiert je nach type (siehe unten) |

**`clicks` nach Typ**:

| type | clicks-Typ | Beschreibung | Fehlertoleranz |
|------|------------|------|---------|
| click | `[{x:int, y:int}]` | Array von Klickkoordinaten, in order-Reihenfolge | Radius 18px |
| slider | `int` | X-Achsen-Offset des Sliders | ±4px |
| rotate | `int` | Rotationswinkel (0-359) | ±5° |

**Antwortbeispiel**:
```json
{
  "code": 0,
  "message": "验证通过",
  "data": { "valid": true }
}
```

Nach erfolgreicher Prüfung schreibt das Backend `captcha_verified:{key}` in Redis (TTL 300s); der Login-Endpunkt lässt die Anfrage daraufhin durch.
Bei fehlgeschlagener Prüfung ist `code` 422, `message` `"验证失败，请重试"` und `data.valid` `false`.

### 3.5 Login

```
POST /api/auth/login
```

- **Authentifizierung**: keine erforderlich
- **Request-Header**: `API-Version: v1` (erforderlich)
- **Rate-Limit**: 10/Min. (pro IP + Pfad)

**Request-Body**:
```json
{
  "username": "admin",
  "password": "djGYscnyS5V6mW6KyDFjB8vGwjBBnB3Odpyxu8LY...",
  "captcha_key": "abc123def456"
}
```

| Feld | Typ | Erforderlich | Validierungsregel | Beschreibung |
|------|------|------|---------|------|
| username | string | Ja | min:3, max:50 | Benutzername |
| password | string | Ja | min:6, max:32 (Klartext) | AES-256-CBC-HMAC-verschlüsselt und dann Base64-kodiert (Klartext kompatibel) |
| captcha_key | string | Ja | | Captcha-Key (muss zuerst über `/api/captcha/verify` geprüft werden) |

### Passwort-Verschlüsselungsprotokoll

Verwendet **asymmetrische RSA-2048-Verschlüsselung**; der öffentliche Schlüssel liegt im Frontend-Code (darf offengelegt werden), der private Schlüssel wird nur vom Server gehalten.

```
Ablauf der Verschlüsselung (Client):
  RSA-öffentlicher Schlüssel (PEM) → PKCS1v1.5-Verschlüsselung → Base64-Kodierung → Übertragung

Ablauf der Entschlüsselung (Server, schrittweises Fallback):
  1. RSA-Privatschlüssel-Entschlüsselung → Erfolg und gültiges UTF-8 → entschlüsseltes Ergebnis verwenden
  2. AES-256-CBC-HMAC-Entschlüsselung → Erfolg → entschlüsseltes Ergebnis verwenden (Kompatibilität mit alten Clients)
  3. Klartext-Fallback → rohe Eingabe direkt verwenden
```

Der öffentliche Schlüssel ist in der Frontend-App eingebettet und muss nicht über das Netzwerk übertragen werden. Der private Schlüssel wird nur in `RSA_PRIVATE_KEY` in `.env` gespeichert und darf nie offengelegt werden.

> AES-Symmetrieverschlüsselung ist ein Kompatibilitätsschema für alte Versionen und wird entfernt, sobald alle Clients auf RSA migriert sind.

**Antwortbeispiel**:
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

| Feld | Typ | Beschreibung |
|------|------|------|
| access_token | string | JWT-Zugriffstoken |
| refresh_token | string | JWT-Refresh-Token |
| expires_in | int | Gültigkeitsdauer des Zugriffstokens (Sekunden), Standard 7200 |
| user.id | string | Hashid-verschlüsselte Benutzer-ID |
| user.username | string | Benutzername |
| user.real_name | string | Echter Name |

**Mögliche Fehler**:
- 422: Parametervalidierung fehlgeschlagen (Pflichtfelder fehlen, falsches Format)
- 422: Bitte zuerst die Captcha-Prüfung abschließen (captcha_key hat `/api/captcha/verify` nicht bestanden)
- 401: Benutzername oder Passwort falsch
- 403: Konto ist deaktiviert
- 429: Konto ist gesperrt, versuchen Sie es in 15 Minuten erneut (ausgelöst durch 5 fehlgeschlagene Logins in Folge)

### 3.6 Registrierung

```
POST /api/auth/register
```

- **Authentifizierung**: keine erforderlich
- **Request-Header**: `API-Version: v1` (erforderlich)
- **Rate-Limit**: 5/Min. (pro IP + Pfad)

**Request-Body**:
```json
{
  "username": "newuser",
  "password": "djGYscnyS5V6mW6KyDFjB8vGwjBBnB3Odpyxu8LY...",
  "real_name": "新用户",
  "captcha_key": "abc123def456"
}
```

| Feld | Typ | Erforderlich | Validierungsregel | Beschreibung |
|------|------|------|---------|------|
| username | string | Ja | min:3, max:50 | Benutzername (eindeutig) |
| password | string | Ja | min:6, max:32 (Klartext) | AES-256-CBC-HMAC-verschlüsselt und dann Base64-kodiert |
| real_name | string | Ja | max:50 | Echter Name |
| captcha_key | string | Ja | | Captcha-Key (muss zuerst über `/api/captcha/verify` geprüft werden) |

**Antwortbeispiel**:
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

Nach erfolgreicher Registrierung werden direkt JWT-Tokens zurückgegeben; der Benutzerstatus ist standardmäßig aktiviert (status=1).

### 3.7 Token aktualisieren

```
POST /api/auth/refresh
```

- **Authentifizierung**: keine erforderlich
- **Request-Header**: `API-Version: v1` (erforderlich)
- **Rate-Limit**: globaler Standard (60/Min.)

**Request-Body**:
```json
{
  "refresh_token": "eyJhbGciOiJIUzI1NiIs..."
}
```

| Feld | Typ | Erforderlich | Beschreibung |
|------|------|------|------|
| refresh_token | string | Ja | refresh_token, das bei Login/Registrierung erhalten wurde |

**Antwortbeispiel**:
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

Bei erfolgreicher Aktualisierung werden neue access_token und refresh_token zurückgegeben; die alten Tokens verlieren automatisch ihre Gültigkeit. Beim Aktualisieren werden letzte Login-Zeit und IP des Benutzers aktualisiert.

**Mögliche Fehler**:
- 422: Refresh-Token fehlt
- 401: Refresh-Token ungültig oder abgelaufen

### 3.8 Prometheus-Metriken

```
GET /metrics
```

- **Authentifizierung**: keine erforderlich
- **Rate-Limit**: keines
- **Antwortformat**: Prometheus-Textformat (`text/plain; version=0.0.4`)

Öffentlicher Prometheus-Metriken-Endpunkt zum Abrufen durch Grafana/Prometheus.

**Antwortbeispiel**:
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

| Metrikname | Typ | Beschreibung |
|------|------|------|
| `openadmin_http_requests_total` | gauge | Gesamtzahl der HTTP-Anfragen |
| `openadmin_active_users` | gauge | Aktive Benutzer (innerhalb von 24 Stunden eingeloggt) |
| `openadmin_db_connection_status` | gauge | Datenbank-Verbindungsstatus, 1=ok, 0=Fehler |
| `openadmin_redis_connection_status` | gauge | Redis-Verbindungsstatus, 1=ok, 0=Fehler |
| `openadmin_memory_usage_bytes` | gauge | Aktuelle Speichernutzung des PHP-Prozesses (Bytes) |

## 4. Dashboard

Alle Admin-Endpunkte sind unter der Gruppe `/admin` gemountet und durchlaufen drei Middlewares: `AdminAuth` (JWT-Authentifizierung), `AdminPermission` (RBAC-Berechtigungsprüfung) und `OperationLog` (Betriebsprotokollierung).

### 4.1 Dashboard-Daten

```
GET /admin/dashboard
```

- **Authentifizierung**: JWT + RBAC
- **Cache**: Redis 5 Minuten

**Antwortbeispiel**:
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

| stats-Felder | Typ | Beschreibung |
|------|------|------|
| label | string | Metrikname |
| value | string | Metrikwert (String-Typ) |
| icon | string | Material-Icon-Name |
| color | string | Kartenfarbe |
| trend | float? | Tagesvergleichs-Wachstumsrate (Prozent); nur "Benutzer gesamt" hat dieses Feld |

| trends-Felder | Typ | Beschreibung |
|------|------|------|
| dates | array{string} | Datumsfolge der letzten 30 Tage |
| series | array{object} | Trendliniendaten mit name (Name), data (Wertearray), color (Farbe) |

## 5. Benutzerverwaltung

Die von allen Benutzerverwaltungs-Endpunkten zurückgegebene `id` ist ein hashid-verschlüsselter String. Passwortfelder sind aus den Antworten ausgeschlossen. Telefonnummern und E-Mails werden in Listen-Endpunkten maskiert und in Detail-Endpunkten im Klartext zurückgegeben (verschlüsselte Datenbankfelder werden vom Encryptable-Trait automatisch entschlüsselt).

### 5.1 Benutzerliste

```
GET /admin/user
```

- **Authentifizierung**: JWT + RBAC

**Abfrageparameter**:

| Parameter | Typ | Erforderlich | Standard | Beschreibung |
|------|------|------|------|------|
| page | int | Nein | 1 | Seitennummer |
| limit | int | Nein | 15 | Einträge pro Seite |
| keyword | string | Nein | | Suchbegriff, passt auf Benutzername und echten Namen |
| status | int | Nein | | Statusfilter, 0=deaktiviert, 1=aktiviert |

**Antwortbeispiel**:
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

| Feld | Typ | Beschreibung |
|------|------|------|
| id | string | Hashid-verschlüsselte Benutzer-ID |
| username | string | Benutzername |
| real_name | string | Echter Name |
| phone | string | Maskierte Telefonnummer (`138****5678`-Format) |
| email | string | Maskierte E-Mail (`a***@example.com`-Format) |
| status | int | 1=aktiviert, 0=deaktiviert |
| last_login_at | string | Letzte Login-Zeit (datetime) |
| created_at | string | Erstellungszeit (datetime) |

### 5.2 Benutzer erstellen

```
POST /admin/user
```

- **Authentifizierung**: JWT + RBAC

**Request-Body**:
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

| Feld | Typ | Erforderlich | Validierungsregel | Beschreibung |
|------|------|------|---------|------|
| username | string | Ja | min:3, max:50 | Benutzername (eindeutig) |
| password | string | Ja | min:6, max:32 | Passwort (mit bcrypt gespeichert) |
| real_name | string | Ja | max:50 | Echter Name |
| phone | string | Nein | | Telefonnummer (mit Encryptable verschlüsselt) |
| email | string | Nein | | E-Mail (mit Encryptable verschlüsselt) |
| status | int | Nein | in:0,1 | Status, Standard 1 (aktiviert) |

**Antwortbeispiel**:
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

**Mögliche Fehler**:
- 422: Benutzername existiert bereits
- 422: Parametervalidierung fehlgeschlagen (Pflichtfelder fehlen)

### 5.3 Benutzerdetails

```
GET /admin/user/{id}
```

- **Authentifizierung**: JWT + RBAC
- **Pfadparameter**: `{id}` ist die hashid-verschlüsselte Benutzer-ID

**Antwortbeispiel**:
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

Im Detail-Endpunkt werden `phone` und `email` im Klartext zurückgegeben (in der Datenbank verschlüsselt gespeichert, vom Encryptable-Cast automatisch entschlüsselt) und nicht maskiert. `password` und `id_card` sind nie in der Antwort enthalten.

**Mögliche Fehler**:
- 404: Benutzer nicht gefunden

### 5.4 Benutzer aktualisieren

```
PUT /admin/user/{id}
```

- **Authentifizierung**: JWT + RBAC
- **Pfadparameter**: `{id}` ist die hashid-verschlüsselte Benutzer-ID

**Request-Body**:
```json
{
  "real_name": "新姓名",
  "password": "",
  "phone": "13900139000",
  "email": "new@example.com",
  "status": 1
}
```

| Feld | Typ | Erforderlich | Beschreibung |
|------|------|------|------|
| real_name | string | Nein | Echter Name; wenn nicht übergeben, wird der bisherige Wert beibehalten |
| password | string | Nein | Neues Passwort; bei leerem String oder fehlender Angabe keine Änderung |
| phone | string | Nein | Telefonnummer |
| email | string | Nein | E-Mail |
| status | int | Nein | 0=deaktiviert, 1=aktiviert |

**Antwortbeispiel**:
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

**Mögliche Fehler**:
- 404: Benutzer nicht gefunden

### 5.5 Benutzer löschen

```
DELETE /admin/user/{id}
```

- **Authentifizierung**: JWT + RBAC
- **Pfadparameter**: `{id}` ist die hashid-verschlüsselte Benutzer-ID
- **Sensible Operation**: Passwort-Bestätigung erforderlich

**Request-Body**:
```json
{
  "password": "admin_password"
}
```

| Feld | Typ | Erforderlich | Beschreibung |
|------|------|------|------|
| password | string | Ja | Passwort des aktuell angemeldeten Benutzers (Bestätigung) |

**Antwortbeispiel**:
```json
{
  "code": 0,
  "message": "删除成功",
  "data": []
}
```

Führt ein Soft-Delete durch (Eloquent SoftDeletes); Daten werden mit deleted_at markiert und nicht physisch gelöscht.

**Mögliche Fehler**:
- 404: Benutzer nicht gefunden
- 422: Sensible Operationen erfordern eine Passwortbestätigung (password leer)
- 422: Passwortprüfung fehlgeschlagen (Passwort stimmt nicht überein)

### 5.6 Benutzer in Masse löschen

```
POST /admin/user/batch/destroy
```

- **Authentifizierung**: JWT + RBAC
- **Sensible Operation**: Passwort-Bestätigung erforderlich

**Request-Body**:
```json
{
  "ids": ["a1b2c3d4", "e5f6g7h8"],
  "password": "admin_password"
}
```

| Feld | Typ | Erforderlich | Beschreibung |
|------|------|------|------|
| ids | array{string} | Ja | Array hashid-verschlüsselter Benutzer-IDs |
| password | string | Ja | Passwort des aktuell angemeldeten Benutzers (Bestätigung) |

**Antwortbeispiel**:
```json
{
  "code": 0,
  "message": "删除成功",
  "data": {
    "count": 2
  }
}
```

Führt ein Soft-Delete durch; `data.count` ist die tatsächlich gelöschte Anzahl.

**Mögliche Fehler**:
- 422: Bitte Benutzer zum Löschen auswählen (ids leer)
- 422: Ungültige ID (hashid-Dekodierung fehlgeschlagen)
- 422: Passwortprüfung fehlgeschlagen

### 5.7 Benutzer in Masse aktivieren/deaktivieren

```
POST /admin/user/batch/status
```

- **Authentifizierung**: JWT + RBAC

**Request-Body**:
```json
{
  "ids": ["a1b2c3d4", "e5f6g7h8"],
  "status": 1
}
```

| Feld | Typ | Erforderlich | Beschreibung |
|------|------|------|------|
| ids | array{string} | Ja | Array hashid-verschlüsselter Benutzer-IDs |
| status | int | Ja | 0=deaktiviert, 1=aktiviert |

**Antwortbeispiel**:
```json
{
  "code": 0,
  "message": "批量启用成功",
  "data": {
    "count": 2
  }
}
```

message ändert sich dynamisch je nach status: `"批量启用成功"` oder `"批量禁用成功"`.

**Mögliche Fehler**:
- 422: Bitte Benutzer auswählen (ids leer)
- 422: Ungültiger Statuswert (status ist weder 0 noch 1)

## 6. Rollenverwaltung

### 6.1 Rollenliste

```
GET /admin/role
```

- **Authentifizierung**: JWT + RBAC

**Abfrageparameter**:

| Parameter | Typ | Erforderlich | Standard | Beschreibung |
|------|------|------|------|------|
| page | int | Nein | 1 | Seitennummer |
| limit | int | Nein | 15 | Einträge pro Seite |

**Antwortbeispiel**:
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

| Feld | Typ | Beschreibung |
|------|------|------|
| id | string | Hashid-verschlüsselte Rollen-ID |
| name | string | Rollenname |
| slug | string | Rollen-Kennung (eindeutig, für Berechtigungsprüfungen) |
| description | string | Rollenbeschreibung |
| status | int | 1=aktiviert, 0=deaktiviert |
| users_count | int | Anzahl der Benutzer mit dieser Rolle |

### 6.2 Rolle erstellen

```
POST /admin/role
```

- **Authentifizierung**: JWT + RBAC

**Request-Body**:
```json
{
  "name": "编辑员",
  "slug": "editor",
  "description": "内容编辑角色",
  "status": 1,
  "permission_ids": [1, 5, 12, 15]
}
```

| Feld | Typ | Erforderlich | Validierungsregel | Beschreibung |
|------|------|------|---------|------|
| name | string | Ja | max:50 | Rollenname |
| slug | string | Ja | max:50 | Rollen-Kennung |
| description | string | Nein | | Rollenbeschreibung, Standard leerer String |
| status | int | Nein | | Status, Standard 1 |
| permission_ids | array{int} | Nein | | Array von Berechtigungs-IDs (rohe INT-IDs, keine Hashids) |

**Antwortbeispiel**:
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

### 6.3 Rolle aktualisieren

```
PUT /admin/role/{id}
```

- **Authentifizierung**: JWT + RBAC

**Request-Body**:
```json
{
  "name": "内容编辑",
  "description": "更新后的描述",
  "status": 1,
  "permission_ids": [1, 5, 12, 20]
}
```

| Feld | Typ | Erforderlich | Beschreibung |
|------|------|------|------|
| name | string | Nein | Rollenname |
| description | string | Nein | Beschreibung |
| status | int | Nein | 0=deaktiviert, 1=aktiviert |
| permission_ids | array{int} | Nein | Array von Berechtigungs-IDs; bei Übergabe werden die Rollenrechte synchronisiert (überschrieben) |

**Antwortbeispiel**:
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

### 6.4 Rolle löschen

```
DELETE /admin/role/{id}
```

- **Authentifizierung**: JWT + RBAC
- **Sensible Operation**: Passwort-Bestätigung erforderlich

**Request-Body**:
```json
{
  "password": "admin_password"
}
```

**Antwortbeispiel**:
```json
{
  "code": 0,
  "message": "删除成功",
  "data": []
}
```

Beim Löschen werden die Verknüpfungen der Rolle mit allen Berechtigungen und Benutzern automatisch entfernt, dann wird der Rollen-Datensatz physisch gelöscht.

## 7. Berechtigungsverwaltung

Berechtigungen verwenden eine Baumstruktur (parent_id-Selbstreferenz) und werden in drei Typen unterteilt. Der Listen-Endpunkt liefert den vollständigen Berechtigungsbaum.

### 7.1 Berechtigungsbaum

```
GET /admin/permission
```

- **Authentifizierung**: JWT + RBAC

**Antwortbeispiel**:
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

| Feld | Typ | Beschreibung |
|------|------|------|
| id | string | Hashid-verschlüsselt |
| parent_id | string | Hashid der übergeordneten Berechtigung; "0" steht für den Wurzelknoten |
| name | string | Berechtigungsname |
| slug | string | Berechtigungs-Kennung (Routen-/Button-Kennung) |
| type | int | 1=Menü, 2=Button, 3=API |
| icon | string | Menü-Icon (Material-Icon-Name) |
| path | string | Frontend-Routenpfad |
| sort | int | Sortierwert (aufsteigend) |
| children | array? | Liste der Unterberechtigungen (rekursiv); fehlt, wenn keine Unterknoten vorhanden sind |

### 7.2 Berechtigung erstellen

```
POST /admin/permission
```

- **Authentifizierung**: JWT + RBAC

**Request-Body**:
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

| Feld | Typ | Erforderlich | Validierungsregel | Beschreibung |
|------|------|------|---------|------|
| parent_id | int | Nein | | ID der übergeordneten Berechtigung (roher INT-Typ), Standard 0 |
| name | string | Ja | max:50 | Berechtigungsname |
| slug | string | Ja | max:100 | Berechtigungs-Kennung |
| type | int | Ja | in:1,2,3 | 1=Menü, 2=Button, 3=API |
| icon | string | Nein | | Menü-Icon, Standard leer |
| path | string | Nein | | Frontend-Routenpfad, Standard leer |
| sort | int | Nein | | Sortierwert, Standard 0 |

**Antwortbeispiel**:
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

### 7.3 Berechtigung aktualisieren

```
PUT /admin/permission/{id}
```

- **Authentifizierung**: JWT + RBAC

**Request-Body**:
```json
{
  "name": "系统配置",
  "icon": "tune",
  "path": "/system/config",
  "sort": 50
}
```

| Feld | Typ | Erforderlich | Beschreibung |
|------|------|------|------|
| name | string | Nein | Berechtigungsname |
| icon | string | Nein | Icon |
| path | string | Nein | Routenpfad |
| sort | int | Nein | Sortierwert |

### 7.4 Berechtigung löschen

```
DELETE /admin/permission/{id}
```

- **Authentifizierung**: JWT + RBAC
- **Sensible Operation**: Passwort-Bestätigung erforderlich

**Request-Body**:
```json
{
  "password": "admin_password"
}
```

**Antwortbeispiel**:
```json
{
  "code": 0,
  "message": "删除成功",
  "data": []
}
```

Beim Löschen werden alle Unterberechtigungen kaskadiert gelöscht (Datensätze, deren `parent_id` der aktuellen Berechtigungs-ID entspricht) und die Verknüpfungen mit allen Rollen entfernt.

## 8. Systemkonfiguration

Systemkonfigurationen sind durch die Kombination aus `group` + `key` eindeutig.

### 8.1 Konfigurationsliste

```
GET /admin/config
```

- **Authentifizierung**: JWT + RBAC

**Abfrageparameter**:

| Parameter | Typ | Erforderlich | Standard | Beschreibung |
|------|------|------|------|------|
| page | int | Nein | 1 | Seitennummer |
| limit | int | Nein | 15 | Einträge pro Seite |
| group | string | Nein | | Nach Konfigurationsgruppe filtern |

**Antwortbeispiel**:
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

| Feld | Typ | Beschreibung |
|------|------|------|
| id | string | hashid |
| group | string | Konfigurationsgruppe (z. B. `system`, `email`, `storage`) |
| key | string | Konfigurationsschlüssel |
| value | string | Konfigurationswert |
| type | string | Hinweis zum Werttyp (`string`, `integer`, `boolean`, `json` usw.) |
| description | string | Konfigurationsbeschreibung |

### 8.2 Konfiguration erstellen

```
POST /admin/config
```

- **Authentifizierung**: JWT + RBAC

**Request-Body**:
```json
{
  "group": "email",
  "key": "smtp_host",
  "value": "smtp.example.com",
  "type": "string",
  "description": "SMTP 服务器地址"
}
```

| Feld | Typ | Erforderlich | Validierungsregel | Beschreibung |
|------|------|------|---------|------|
| group | string | Ja | max:100 | Konfigurationsgruppe |
| key | string | Ja | max:100 | Konfigurationsschlüssel (innerhalb derselben Gruppe eindeutig) |
| value | string | Ja | | Konfigurationswert |
| type | string | Nein | | Werttyp, Standard `string` |
| description | string | Nein | | Konfigurationsbeschreibung, Standard leer |

**Antwortbeispiel**:
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

**Mögliche Fehler**:
- 422: Konfigurationseintrag existiert bereits (gleiche group + key)

### 8.3 Konfiguration aktualisieren

```
PUT /admin/config/{id}
```

- **Authentifizierung**: JWT + RBAC

**Request-Body**:
```json
{
  "value": "smtp.newhost.com",
  "type": "string",
  "description": "更新后的 SMTP 地址"
}
```

| Feld | Typ | Erforderlich | Beschreibung |
|------|------|------|------|
| value | string | Nein | Konfigurationswert aktualisieren |
| type | string | Nein | Werttyp aktualisieren |
| description | string | Nein | Beschreibungstext aktualisieren |

### 8.4 Konfiguration löschen

```
DELETE /admin/config/{id}
```

- **Authentifizierung**: JWT + RBAC
- **Sensible Operation**: Passwort-Bestätigung erforderlich

**Request-Body**:
```json
{
  "password": "admin_password"
}
```

Löscht den Konfigurationsdatensatz physisch.

## 9. Betriebsprotokoll

Betriebsprotokolle sind nur lesbar; die `OperationLog`-Middleware schreibt bei jeder POST/PUT/DELETE-Anfrage automatisch. Gespeicherte Felder: `user_id`, `action`, `method`, `path`, `ip`, `source`, `input`.

### 9.1 Betriebsprotokoll-Liste

```
GET /admin/log
```

- **Authentifizierung**: JWT + RBAC

**Abfrageparameter**:

| Parameter | Typ | Erforderlich | Standard | Beschreibung |
|------|------|------|------|------|
| page | int | Nein | 1 | Seitennummer |
| limit | int | Nein | 15 | Einträge pro Seite |
| user_id | int | Nein | | Exakter Filter nach Benutzer-ID (roher INT-Typ) |
| action | string | Nein | | Exakter Filter nach Aktion |
| path | string | Nein | | Unscharfer Filter nach Anfragepfad |
| start_date | string | Nein | | Startdatum (Y-m-d-Format) |
| end_date | string | Nein | | Enddatum (Y-m-d-Format) |

**Antwortbeispiel**:
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

| Feld | Typ | Beschreibung |
|------|------|------|
| id | string | hashid |
| user_name | string | Benutzername der Aktion (über die user-Beziehung; nicht angemeldete Aktionen zeigen "系统") |
| action | string | Beschreibung der Aktion |
| method | string | HTTP-Methode (POST/PUT/DELETE) |
| path | string | Anfragepfad |
| ip | string | Client-IP |
| source | string | Anfragequelle |
| input | string | Anfrageparameter als JSON-String (ohne Dateien) |
| created_at | string | Zeitpunkt der Aktion (datetime) |

## 10. Persönliches Profil

Profil-Endpunkte benötigen nur JWT-Authentifizierung (keine RBAC-Prüfung — die `AdminPermission`-Middleware sollte sie in die Whitelist aufnehmen).

### 10.1 Persönliche Informationen aktualisieren

```
PUT /admin/profile
```

- **Authentifizierung**: JWT

**Request-Body**:
```json
{
  "real_name": "新姓名",
  "phone": "13800138000",
  "email": "me@example.com"
}
```

| Feld | Typ | Erforderlich | Beschreibung |
|------|------|------|------|
| real_name | string | Nein | Echter Name |
| phone | string | Nein | Telefonnummer (mit Encryptable verschlüsselt) |
| email | string | Nein | E-Mail (mit Encryptable verschlüsselt) |

**Antwortbeispiel**:
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

In der Antwort werden `phone` und `email` im Klartext zurückgegeben; `password` und `id_card` sind entfernt.

### 10.2 Passwort ändern

```
PUT /admin/profile/password
```

- **Authentifizierung**: JWT

**Request-Body**:
```json
{
  "old_password": "current_pass",
  "new_password": "new_pass_123"
}
```

| Feld | Typ | Erforderlich | Validierungsregel | Beschreibung |
|------|------|------|---------|------|
| old_password | string | Ja | | Aktuelles Passwort |
| new_password | string | Ja | min:6, max:32 | Neues Passwort |

**Antwortbeispiel**:
```json
{
  "code": 0,
  "message": "密码修改成功",
  "data": []
}
```

**Mögliche Fehler**:
- 422: Bitte altes und neues Passwort angeben
- 422: Altes Passwort ist falsch
- 422: Neues Passwort muss 6-32 Zeichen lang sein

### 10.3 Abmelden

```
POST /admin/profile/logout
```

- **Authentifizierung**: JWT

**Request-Body**: keiner (kein requestBody; das Token wird aus dem Authorization-Header gelesen)

**Antwortbeispiel**:
```json
{
  "code": 0,
  "message": "已登出",
  "data": []
}
```

Abmeldelogik: JWT dekodieren, Restlaufzeit (exp - now) ermitteln, md5-Hash des Tokens in die Redis-Blacklist `jwt_blacklist:{md5}` schreiben, TTL = Restlaufzeit. Tokens auf der Blacklist werden von der `AdminAuth`-Middleware blockiert und liefern 401.

Ohne Token wird 401 zurückgegeben. Abgelaufene/ungültige Tokens (Dekodierung wirft eine Exception) gelten weiterhin als erfolgreiche Abmeldung.

## 11. Import und Export

### 11.1 Excel exportieren

```
POST /admin/export/excel
```

- **Authentifizierung**: JWT + RBAC
- **Antworttyp**: Dateidownload (`application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`)

**Request-Body**:
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

| Feld | Typ | Erforderlich | Standard | Beschreibung |
|------|------|------|------|------|
| table | string | Nein | `admin_user` | Zu exportierende Tabelle. Unterstützt: `admin_user`, `operation_log`, `admin_role`, `system_config` |
| columns | array{string} | Nein | | Array der zu exportierenden Spaltennamen; leer exportiert alle Spalten der Tabelle |
| conditions | object | Nein | `{}` | Filterbedingungen, Schlüssel-Wert-Paare; nicht-leere Werte werden in WHERE verwendet |
| title | string | Nein | `数据导出` | Excel-Titel (wird als Blattname angezeigt) |

**Unterstützte Tabellen und Spalten**:

| table | Verfügbare Spalten |
|-------|-------|
| `admin_user` | id, username, real_name, phone, email, status, last_login_at, last_login_ip, created_at |
| `operation_log` | id, user_id, action, method, path, ip, created_at |
| `admin_role` | id, name, slug, description, status, created_at |
| `system_config` | id, group, key, value, type, description, created_at |

Die sensiblen Felder `phone`, `email`, `id_card` werden beim Export automatisch maskiert. Datenlimit: 10000 Zeilen. Die erste Excel-Zeile wird eingefroren, AutoFilter ist aktiviert.

### 11.2 PDF exportieren

```
POST /admin/export/pdf
```

- **Authentifizierung**: JWT + RBAC
- **Antworttyp**: Dateidownload (`application/pdf`, A4 quer)

**Request-Body**:
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

Oder Tabellenmodus:
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

| Feld | Typ | Erforderlich | Standard | Beschreibung |
|------|------|------|------|------|
| type | string | Nein | `table` | Exporttyp: `table` / `dashboard` |
| title | string | Nein | `数据导出` | PDF-Titel |
| data | object | Nein | `{}` | Exportdaten |

Bei `type=dashboard` muss `data` ein `stats`-Array enthalten (als Karten gerendert); bei `type=table` muss `data` die Arrays `columns` und `rows` enthalten.

Die PDF-Vorlage enthält Urheberrechtshinweise und einen Export-Zeitstempel.

### 11.3 Benutzer importieren (Excel)

```
POST /admin/import/users
```

- **Authentifizierung**: JWT + RBAC
- **Anfragetyp**: `multipart/form-data` (Datei-Upload)

**Formularfelder**:

| Feld | Typ | Erforderlich | Beschreibung |
|------|------|------|------|
| file | file | Ja | `.xlsx`- oder `.xls`-Format |

**Excel-Spaltenanforderungen**:

| Spaltenname | Erforderlich | Beschreibung |
|------|------|------|
| username | Ja | Benutzername (eindeutig) |
| password | Ja | Passwort (als bcrypt-Hash gespeichert) |
| real_name | Ja | Echter Name |
| phone | Nein | Telefonnummer |
| email | Nein | E-Mail |
| status | Nein | Status, Standard 1 |

Zeile 1 ist die Spaltenüberschrift (Groß-/Kleinschreibung irrelevant), ab Zeile 2 folgen die Daten.

**Antwortbeispiel**:
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

| Feld | Typ | Beschreibung |
|------|------|------|
| total | int | Gesamtzahl der Zeilen (ohne Überschriftenzeile) |
| success | int | Anzahl erfolgreich importierter |
| failed | int | Anzahl fehlgeschlagener |
| errors | array | Fehlerdetails mit row (Excel-Zeilennummer) und reason (Fehlerursache) |

## 12. Datei-Upload

```
POST /admin/upload
```

- **Authentifizierung**: JWT + RBAC
- **Anfragetyp**: `multipart/form-data`

**Formularfelder**:

| Feld | Typ | Erforderlich | Beschreibung |
|------|------|------|------|
| file | file | Ja | Die hochzuladende Datei |

**Erlaubte Dateitypen**: `jpg`, `jpeg`, `png`, `gif`, `pdf`, `xlsx`, `docx`
**Maximale Dateigröße**: 10MB

**Antwortbeispiel**:
```json
{
  "code": 0,
  "message": "上传成功",
  "data": {
    "url": "/upload/2026-05-21/abc123def456.png"
  }
}
```

Dateien werden in datumsbasierten Verzeichnissen `public/upload/{Y-m-d}/` gespeichert; der Dateiname ist `md5(uniqid) + ursprüngliche Erweiterung`. `url` ist ein relativer Pfad ab dem Site-Root.

**Mögliche Fehler**:
- 422: Bitte eine Datei auswählen (keine hochgeladen)
- 422: Nicht unterstützter Dateityp
- 422: Die Dateigröße darf 10MB nicht überschreiten
- 500: Datei-Upload fehlgeschlagen (ungültige Datei)

## 13. Antwort-Header

Alle Endpunkte (auf globaler Middleware-Ebene injiziert) enthalten die folgenden Antwort-Header:

| Header | Beschreibung |
|----|------|
| `X-RateLimit-Limit` | Rate-Limit-Obergrenze (Anzahl) |
| `X-RateLimit-Remaining` | Verbleibende Anzahl von Anfragen |
| `X-RateLimit-Reset` | Zeitstempel zum Zurücksetzen des Rate-Limit-Fensters |
| `Retry-After` | Nur bei ausgelöstem Rate-Limit; empfohlene Wartezeit in Sekunden |
| `X-Content-Type-Options` | `nosniff` (webman-Standard, deaktiviert MIME-Sniffing) |
| `X-Frame-Options` | `DENY` (durch die CORS-Middleware/Basiskonfiguration von webman) |

Details zum Rate-Limit:
- Standardmäßiges globales Limit: 60/Min. / IP+Pfad
- Login-Endpunkt `/api/auth/login`: 10/Min.
- Registrierungs-Endpunkt `/api/auth/register`: 5/Min.
- Verwendet den atomaren Sliding-Window-Algorithmus von Redis (Lua ZSET), vermeidet TOCTOU-Races
- Bei nicht verfügbarem Redis fail open (durchlassen), Anfragen werden nicht blockiert

## 14. Authentifizierungsablauf

Die vollständige Authentifizierungssequenz:

```
1. Client sendet POST /api/captcha/generate
   (Request-Header: API-Version: v1)
    ↓
   Server antwortet: key + type(click|slider|rotate) + base64-Bild + extra(typspezifische Daten)
   
2. Der Benutzer absolviert die Captcha-Interaktion (Klick/Ziehen/Rotation), der Client sammelt die Antwort
   
3. Client sendet POST /api/captcha/verify
   (Request-Header: API-Version: v1, Content-Type: application/json)
   Request-Body: { key, type, clicks }
   - type=click:  clicks = [{x, y}, ...]        // Koordinatenarray
   - type=slider: clicks = 120                   // X-Offset
   - type=rotate: clicks = 315                   // Rotationswinkel
    ↓
   Server:
   a. Liest die captcha:key-Daten aus dem Speicher (TTL 300s)
   b. Prüft die Antwort nach type (click: euklidischer Abstand ≤18px / slider: ±4px / rotate: ±5°)
   c. Prüfung bestanden → Redis `captcha_verified:{key}` = 1 schreiben (TTL 300s)
   d. Prüfung fehlgeschlagen → 422 zurückgeben, Zähler +1, nach 3 Versuchen wird key ungültig
    ↓
   Server antwortet: { valid: true/false }

4. Client sendet POST /api/auth/login
   (Request-Header: API-Version: v1, Content-Type: application/json)
   Request-Body: { username, password(verschlüsselt), captcha_key }
    ↓
   Server:
   a. Parametervalidierung → 422
   b. Prüfen, ob captcha_verified:{key} existiert → 422
   c. captcha_verified:{key} löschen (Einmalverwendung)
   d. Passwort entschlüsseln: EncryptionService::decrypt(password) → Klartext
   e. Anmeldeinformationen prüfen (password_verify) → 401
   f. Kontostatus prüfen → 403/429
   g. JWT ausstellen (access + refresh) → 200
   h. last_login_at / last_login_ip aktualisieren
    ↓
   Client speichert: access_token, refresh_token, expires_in

5. Nachfolgende Anfragen tragen das JWT
   Request-Header: Authorization: Bearer <access_token>
    ↓
   AdminAuth-Middleware:
   a. Bearer-Token extrahieren
   b. Blacklist prüfen (Redis jwt_blacklist:{md5}) → 401
   c. JWT dekodieren, Ablauf prüfen → 401
   d. $request->adminId = sub-Feld setzen
    ↓
   AdminPermission-Middleware:
   a. Berechtigungskennung für die Ressourcenroute auflösen
   b. Benutzerrollen → Rollenberechtigungen abfragen und abgleichen
   c. Keine Berechtigung → 403
    ↓
   Controller verarbeitet die Anfrage
    ↓
   Response + X-RateLimit-*-Header

6. Vor Ablauf des Access Tokens aktualisieren
   Client sendet POST /api/auth/refresh
   Request-Body: { refresh_token: "..." }
    ↓
   Server dekodiert refresh_token → stellt neue access + refresh aus
    ↓
   Client aktualisiert die lokalen Tokens

7. Abmelden
   Client sendet POST /admin/profile/logout
   Request-Header: Authorization: Bearer <access_token>
    ↓
   Server:
   a. JWT dekodieren, Rest-TTL ermitteln
   b. In die Redis-Blacklist schreiben: jwt_blacklist:{md5(token)} = 1, TTL = Restlaufzeit
   c. Erfolg zurückgeben
```

### JWT-Struktur

- **access_token**: `{ sub: <user_id>, username: "<name>" }`, Standard-TTL 7200 Sekunden (gesteuert durch die JWT-Konfiguration `default_expire`)
- **refresh_token**: `{ sub: <user_id>, token_type: "refresh" }`, Standard-TTL 1209600 Sekunden (gesteuert durch die JWT-Konfiguration `refresh_expire`, also 14 Tage)

### Sicherheitsverwaltung

- Passwörter werden als `PASSWORD_BCRYPT`-Hashes gespeichert
- Passwörter werden bei der Übertragung mit AES-256-CBC-HMAC verschlüsselt (Client verschlüsselt → Server entschlüsselt), mit Klartext-Fallback
- Sensible Felder (phone, email, id_card) werden mit `erikwang2013/encryptable` auf Datenbankebene transparent ver- und entschlüsselt
- IDs auf API-Ebene werden mit `erikwang2013/hashids` verschlüsselt übertragen, um die rohe snowflake-ID-Sequenz nicht offenzulegen
- SecurityFilter scannt global nach XSS, SQL-Injection, Pfad-Traversal und Command-Injection; gleiche IP 5×/60s → temporäre Blacklist für 15 Minuten
- Sensible Operationen (Löschen von Benutzern, Rollen, Berechtigungen, Konfigurationen) erfordern eine Passwortbestätigung des aktuell angemeldeten Benutzers
- Begrenzung gleichzeitiger Sitzungen: höchstens 3 gültige Tokens pro Benutzer; beim Login von einem 4. Gerät wird das älteste Token zwangsweise auf die Blacklist gesetzt
- Kontosperre: 5 fehlgeschlagene Logins in Folge lösen eine 15-minütige Sperre aus; während der Sperre wird 429 zurückgegeben

## 15. Bereitstellung und Betrieb

### Docker Compose

Im Projektstamm liegt `docker-compose.yml`, das 5 Dienste orchestriert (Nginx, webman app, MySQL, Redis, Elasticsearch). PHP wird über das `Dockerfile` gebaut (basiert auf `php:8.3-cli`, mit aktiviertem OPcache).

```bash
cp .env.docker .env
docker-compose up -d
```

### CI/CD

`.github/workflows/ci.yml` definiert die GitHub-Actions-CI-Pipeline:
- Syntaxprüfung mit `php -l`
- PHPUnit-Unit-Tests
- Statische Analyse mit `flutter analyze`

### Datenbank-Backup

Das Verzeichnis `database/backup/` enthält Skripte für Backup und Wiederherstellung:
- `backup.sh` — mysqldump + gzip-komprimierte Backups, löscht automatisch Backups, die älter als 30 Tage sind
- `restore.sh` — interaktive Wiederherstellung mit Liste der verfügbaren Backups

### Nginx-Sicherheitskonfiguration

Für die Produktionsbereitstellung siehe `docs/nginx-security.conf` zur Härtung des Reverse-Proxys.
