# Dokument zur Sicherheitsarchitektur

**语言 / Languages:** [中文](SECURITY.md) · [English](SECURITY.en.md) · [한국어](SECURITY.ko.md) · [Русский](SECURITY.ru.md) · [Deutsch](SECURITY.de.md) · [Français](SECURITY.fr.md) · [Español](SECURITY.es.md) · [Português](SECURITY.pt.md) · [हिन्दी](SECURITY.hi.md) · [العربية](SECURITY.ar.md) · [বাংলা](SECURITY.bn.md) · [Bahasa Indonesia](SECURITY.id.md) · [日本語](SECURITY.ja.md)

> Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz

## 1. Überblick über die Verteidigung in der Tiefe

Das System verwendet ein 7-stufiges Modell der Verteidigung in der Tiefe. Bösartige Anfragen werden schichtweise von außen nach innen gefiltert, sodass auch bei Ausfall einer einzelnen Schicht die nachfolgenden Verteidigungslinien weiterhin greifen.

Die gesamte Middleware-Kette wird in der folgenden Reihenfolge ausgeführt (siehe `config/middleware.php`):

```
请求 → Cors → Locale(Accept-Language) → SecurityMiddleware (erikwang2013/security-php, 31种检测器) → RateLimit → [路由组中间件: AdminAuth → AdminPermission → OperationLog] → Controller
```

| Schicht | Middleware / Mechanismus | Schutzziel |
|----|--------|---------|
| 1 | SecurityMiddleware (erikwang2013/security-php) | 31 Angriffserkennungen + HTTP-Methodenprüfung + Begrenzung der Request-Body-Größe + Content-Type-Prüfung + CSRF + IP-Angriffs-Eskalations-Blacklist |
| 2 | Cors | Cross-Origin-Sicherheit + Einspritzung von Sicherheits-Response-Headern |
| 3 | RateLimit | Redis-Sliding-Window-Rate-Limiting, verhindert Brute Force |
| 4 | AdminAuth | JWT-Authentifizierung + Logout per Blacklist |
| 5 | AdminPermission | RBAC-Autorisierung mit method.path-Granularität |
| 6 | OperationLog | Betriebsprüfung (Audit) + Nachverfolgung der Client-Quelle |
| 7 | Datenverschlüsselung | Hashids-ID-Verschleierung + Encryptable-DB-Verschlüsselung + EncryptionService-Transportverschlüsselung |

Die Frontend-Schichten (Flutter) verfügen über eine eigene unabhängige Eingabevalidierung; das Backend vertraut nichts, und jede Schicht verteidigt unabhängig.

---

## 2. Angriffserkennungs-Engine

## 2. 攻击检测引擎 (erikwang2013/security-php)

Die Angriffserkennung wurde von der eigenen SecurityMiddleware auf das dedizierte Sicherheitspaket `erikwang2013/security-php` v1.1+ migriert, das **31 Detektoren** bietet, die 5 große Angriffskategorien abdecken.

### 2.1 Detektor-Klassifikation

**Injektionsangriffe (11):** XSS, SQL-Injection, Command Injection, NoSQL-Injection, LDAP-Injection, XPath-Injection, JNDI/Log4Shell, SSI-Server-Side-Includes, GraphQL-Injection, SSTI-Template-Injection

**Protokoll- und Request-Angriffe (9):** SSRF, XXE, HTTP-Response-Header-Injection, Host-Header-Angriff, Request Smuggling, Open Redirect, CORS-Bypass, WebSocket-Hijacking, DNS Rebinding

**Validierung der HTTP-Protokollebene (6):** HTTP-Methodenprüfung (405), Begrenzung der Request-Body-Größe (413), Content-Type-Prüfung (415), CSRF-Origin-Check, IP-Angriffs-Eskalations-Blacklist, Erkennung von Datenschutzverletzungen (Sensitive Data Leak)

**Daten- und Serialisierungsangriffe (5):** PHP-Deserialisierung, CSV-Formel-Injection, E-Mail-Header-Injection, JWT-Angriffe (strukturierte Analyse), JS Prototype Pollution

**Datei- und Pfadangriffe (2):** Pfad-Traversal, Upload bösartiger Dateien

### 2.2 Verarbeitungsmodi

Jeder Detektor unterstützt unabhängig zwei Modi:
- `block` — bei erkannter Attacke blockieren und den konfigurierten Statuscode zurückgeben
- `log` — nur protokollieren, nicht blockieren (`header_injection`, `ssti`, `nosql_injection` sind standardmäßig im log-Modus, um Fehlalarme zu vermeiden)

### 2.3 IP-Angriffs-Eskalations-Blacklist

Löst dieselbe IP innerhalb von 60 Sekunden 5 Angriffserkennungen aus, wird sie automatisch für 15 Minuten gesperrt. Als Speicher-Backend stehen Redis (verteilt), File (Einzelknoten-JSON) oder Cache (unabhängige Dateien für hohe Parallelität) zur Verfügung; die aktuelle Konfiguration verwendet Redis.

### 2.4 Sicherheitsprotokolle

Dateispeicherort: `runtime/logs/security.log` (automatische Rotation, 10 MB pro Datei)

---

## 4. Sicherheits-Response-Header

Alle Header werden in der `Cors`-Middleware injiziert und über `$response->withHeaders()` an jede Antwort angehängt.

| Header | Wert | Zweck |
|----|-----|------|
| Access-Control-Allow-Origin | `*` | Cross-Origin-Anfragen aus beliebigen Quellen erlauben (Szenario Intranet-Admin-Konsole) |
| Access-Control-Allow-Methods | `GET,POST,PUT,DELETE,OPTIONS` | Zulässige Methodenmenge |
| Access-Control-Allow-Headers | `Authorization,Content-Type,API-Version` | Zulässige benutzerdefinierte Header |
| Access-Control-Max-Age | `86400` | Preflight-Antwort 24 Stunden cachen |
| X-Content-Type-Options | `nosniff` | Browser-MIME-Sniffing verhindern |
| X-Frame-Options | `DENY` | Sämtliche iframe-Einbettungen verbieten, Clickjacking verhindern |
| X-XSS-Protection | `1; mode=block` | Eingebauten Browser-XSS-Filter aktivieren und Seitenrendering blockieren |
| Referrer-Policy | `strict-origin-when-cross-origin` | Gleiche Herkunft sendet vollständige URL, Cross-Origin nur die Domain |
| Permissions-Policy | `camera=(), microphone=(), geolocation=()` | Kamera-/Mikrofon-/Geolokalisierungs-APIs seitenweit deaktivieren |

OPTIONS-Preflight-Anfragen geben direkt eine leere 204-Antwort zurück und durchlaufen die nachfolgende Middleware-Kette nicht.

### 4.2 Content-Security-Policy (CSP)

Wird zusammen mit den anderen Sicherheits-Headern in der Cors-Middleware injiziert und bietet Verteidigung in der Tiefe, indem die Ressourcenquellen begrenzt werden, die der Browser laden und ausführen darf.

| Header | Wert | Zweck |
|----|-----|------|
| Content-Security-Policy | `default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'` | Quellen für Skripte/Styles/Bilder/Verbindungen/Frames/Formulare und andere Ressourcen begrenzen |
| X-Permitted-Cross-Domain-Policies | `none` | Laden von Cross-Domain-Policy-Dateien wie Adobe Flash/PDF verbieten |

CSP-Policy-Kernpunkte:
- `default-src 'self'`: standardmäßig nur Ressourcen gleicher Herkunft erlauben
- `script-src 'self' 'unsafe-inline' 'unsafe-eval'`: Skripte gleicher Herkunft + Inline-Skripte (für Flutter Web erforderlich) + eval (für Flutter-Web-Debugging erforderlich) erlauben
- `frame-ancestors 'none'`: Einbettung in iframes beliebiger Seiten verbieten, Doppelabsicherung mit X-Frame-Options: DENY
- `base-uri 'self'`: `<base>`-Tag auf gleiche Herkunft beschränken
- `form-action 'self'`: Formulare nur an gleiche Herkunft senden lassen

---

## 5. Rate-Limiting-Strategie

### Algorithmus

Redis-Sorted-Set-Sliding-Window + atomares Lua-Skript, für kritische Operationen:

```lua
-- 1. 清理窗口外的旧记录
redis.call('ZREMRANGEBYSCORE', KEYS[1], 0, windowStart)
-- 2. 检查当前窗口计数
local count = redis.call('ZCARD', KEYS[1])
-- 3. 超限则返回 {0, count}，未超限则 ZADD 并返回 {1, count+1}
if count >= limit then return {0, count} end
redis.call('ZADD', KEYS[1], now, now . '.' . random)  -- 随机后缀避免同毫秒覆盖
redis.call('EXPIRE', KEYS[1], window + 10)
```

Das Lua-Skript wird auf dem Redis-Server single-threaded ausgeführt, **von Natur aus atomar**, und eliminiert TOCTOU-Race-Conditions (Time-of-check to Time-of-use).

### Rate-Limit-Konfiguration

| Route | Limit | Fenster | Szenario |
|------|------|------|------|
| Standard (alle Routen) | 60 Mal/Minute | 60s | Allgemeines API |
| `/api/auth/login` | 10 Mal/Minute | 60s | Login (Anti-Brute-Force) |
| `/api/auth/register` | 5 Mal/Minute | 60s | Registrierung (Anti-Massenregistrierung) |

### Response-Header

Bei ausgelöstem Rate-Limit wird HTTP 429 mit JSON-Body zurückgegeben:
```json
{"code": 429, "message": "请求过于频繁，请稍后再试", "data": []}
```

Alle Antworten (einschließlich normaler) tragen die folgenden Header:

| Header | Beschreibung |
|----|------|
| X-RateLimit-Limit | Maximale im aktuellen Fenster erlaubte Anzahl von Anfragen |
| X-RateLimit-Remaining | Verbleibende verfügbare Anfragen im aktuellen Fenster |
| X-RateLimit-Reset | Unix-Zeitstempel des Fenster-Resets |
| Retry-After | Nur bei Rate-Limit vorhanden; empfohlene Wartezeit in Sekunden |

### Degradationsstrategie

Bei Redis-Störung (Verbindungs-Timeout, nicht verfügbar usw.) **fail-open**:

```php
try {
    $result = Redis::eval($lua, ...);
} catch (\Throwable $e) {
    return $handler($request); // Redis down, 放行所有请求
}
```

Lieber kurzzeitig den Rate-Limit-Schutz verlieren, als normale Geschäftsanfragen zu blockieren.

### 5.4 Konto-Sperrmechanismus

Zusätzlich zum Rate-Limit verfügt der Login-Endpunkt über einen **Konto-Sperrmechanismus**, der gezielte Brute-Force-Angriffe auf bestimmte Benutzer verhindert.

**Sperr-Ablauf**:

```
登录失败 → Redis INCR account_lockout:{userId} TTL=900s
连续 5 次失败 → Redis SETEX account_locked:{userId} 900 1
            → 返回 429 "账号已被锁定，请15分钟后再试"
            → 清除计数器 DEL account_lockout:{userId}
```

**Verhalten während der Sperre**:

Während der Sperrzeit geben alle Login-Anfragen direkt 429 zurück, ohne Passwortprüfung, und blockieren damit Brute-Force-Versuche vollständig.

**Konfigurationskonstanten**:

| Konstante | Wert | Bedeutung |
|------|-----|------|
| MAX_LOGIN_ATTEMPTS | 5 | Maximale Anzahl aufeinanderfolgender Fehlversuche |
| LOCKOUT_DURATION | 900 | Sperrdauer in Sekunden, d. h. 15 Minuten |

Hinweis: Die Konto-Sperre basiert auf `userId` und nicht auf der IP; ein IP-Wechsel kann die Sperre also nicht umgehen. Zusammen mit dem IP-Rate-Limit (10 Mal/Minute) ergibt sich ein doppelter Schutz:
- IP-Ebene: Rate-Limit von 10 Mal/Minute verhindert verteiltes Brute Force
- Kontoebene: Sperre nach 5 Fehlversuchen verhindert gezieltes Brute Force

---

## 6. Authentifizierung und Autorisierung

### 6.1 JWT-Authentifizierung

Implementiert durch die AdminAuth-Middleware, die auf authentifizierungspflichtigen Routengruppen montiert ist.

**Parametereinstellung** (`config/plugin/erikwang2013/jwt/jwt`, über `.env` injiziert):

| Parameter | Wert | Beschreibung |
|------|-----|------|
| Algorithmus | HS256 | Symmetrische HMAC-SHA256-Signatur |
| Secret | `JWT_SECRET` | Über Umgebungsvariable injiziert; in Produktion austauschen |
| access_token TTL | 7200s (2h) | `JWT_TTL` |
| refresh_token TTL | 1209600s (14d) | `JWT_REFRESH_TTL` |
| Aussteller | `open-admin` | `JWT_ISSUER` |
| Audience | `open-admin` | `JWT_AUDIENCE` |

**Token-Extraktion**: aus dem Header `Authorization: Bearer <token>`; das Präfix `Bearer ` entfernen, um das rohe JWT zu erhalten.

**Authentifizierungsablauf**:
1. Leeres Token → direkt 401 `{"code": 401, "message": "未登录"}`
2. Redis-Blacklist `jwt_blacklist:{md5(token)}` prüfen → Treffer → 401 `Token已失效，请重新登录`
3. JWT-Decode → Fehler (abgelaufen/Signatur nicht passend) → 401 `Token已过期或无效`
4. Erfolg → `$request->adminId` und `$request->adminUsername` injizieren

**Blacklist-Mechanismus**: Beim Logout wird `md5(token)` mit TTL in Redis geschrieben, das der verbleibenden JWT-Gültigkeit entspricht. Bei Redis-Ausfall wird die Blacklist-Prüfung übersprungen (fail-open); in diesem Fall kann ein abgemeldetes Token kurzzeitig weiterverwendet werden, aber die kurze Gültigkeit des JWT selbst (2h) dient als Fallback-Schutz.

### 6.2 Limitierung paralleler Sitzungen

Um den Missbrauch eines geleakten Tokens auf mehreren Geräten zu verhindern, begrenzt das System die Anzahl gleichzeitig gültiger Token eines Benutzers.

**Begrenzungslogik**:

```
登录成功 → 签发新 Token
         → 查询当前用户有效 Token 数量: Redis SCARD user_tokens:{userId}
         → 若数量 >= 3（MAX_CONCURRENT_SESSIONS）:
            → 按创建时间升序排列，移除最旧的 Token:
              Redis SREM user_tokens:{userId} <oldest_token_id>
              Redis SETEX jwt_blacklist:{md5(oldest_token)} TTL remaining
         → 将新 Token 加入集合: Redis SADD user_tokens:{userId} <new_token_id>
            Redis SET user_token:{token_id} {userId} EX {TTL}
```

**Konfigurationskonstante**:

| Konstante | Wert | Bedeutung |
|------|-----|------|
| MAX_CONCURRENT_SESSIONS | 3 | Maximale Anzahl paralleler Token pro Benutzer |

**Szenario erzwungener Abmeldung**: Wenn sich der Benutzer auf einem 4. Gerät anmeldet, wird das Token des 1. Geräts erzwungen in die Blacklist aufgenommen; nachfolgende Anfragen erhalten 401 "Token已失效，请重新登录".

Beim Logout wird das aktuelle Token aus der Menge entfernt. Läuft ein Token natürlich ab, verfällt der Redis-Key automatisch und die Menge schrumpft entsprechend.

### 6.3 RBAC-Berechtigungsmodell

Implementiert durch die AdminPermission-Middleware.

**Datenmodell**: dreistufige Zuordnung User -> Role -> Permission

- `erik_admin_user` (Benutzertabelle)
- `erik_admin_user_role` (Zuordnungstabelle Benutzer-Rolle)
- `erik_admin_role` (Rollentabelle)
- `erik_admin_role_permission` (Zuordnungstabelle Rolle-Berechtigung)
- `erik_admin_permission` (Berechtigungstabelle)

**Berechtigungstypen**:
| type | Bedeutung | Beispiel |
|------|------|------|
| 1 | Menü-Berechtigung | Sichtbarkeit der linken Navigation steuern |
| 2 | Button-Berechtigung | Aktions-Buttons im Seitenbereich steuern (Hinzufügen/Bearbeiten/Löschen) |
| 3 | API-Berechtigung | Backend-Schnittstellenaufrufe steuern |

Format des API-Berechtigungskennzeichens: `{method}.{path}`

Zum Beispiel:
- `post.admin/user` — Benutzer erstellen
- `put.admin/user` — Benutzer bearbeiten
- `delete.admin/user` — Benutzer löschen
- `get.admin/user` — Benutzerliste anzeigen

**Autorisierungsablauf**:
1. `$request->adminId` leer → durchlassen (Route hat keine Authentifizierungsvoraussetzung konfiguriert)
2. Benutzer abrufen → Rollen (deaktivierte Rollen mit `status=0` überspringen) → Berechtigungsliste
3. Superadmin (`slug = '*'`) → direkt durchlassen
4. `strtolower(method) . '.' . trim(path, '/')` bilden → mit Berechtigungsliste vergleichen
5. Kein Treffer → 403 `{"code": 403, "message": "无权限访问"}`

**Zweitbestätigung**: BaseController stellt die Methode `confirmPassword()` bereit; sensible Operationen (Benutzer löschen, Datencxport usw.) verlangen auf Controller-Ebene zusätzlich die Eingabe des aktuellen Passworts, um unbefugte Operationen nach Session-Hijacking zu verhindern.

---

## 7. Audit-Protokolle

### 7.1 Operationsprotokolle

Die OperationLog-Middleware protokolliert POST / PUT / DELETE-Anfragen automatisch. GET-Anfragen werden nicht protokolliert.

**Protokollierte Felder**:

| Feld | Quelle | Beschreibung |
|------|------|------|
| id | SnowflakeService::generate() | Global eindeutige ID |
| user_id | `$request->adminId` | Operateur-ID, 0 wenn nicht angemeldet |
| action | `$request->method()` | Entspricht method |
| method | `$request->method()` | POST / PUT / DELETE |
| path | `$request->path()` | Anforderungspfad |
| ip | `$request->getRealIp()` | Echte Client-IP |
| source | detectSource() | Client-Quellplattform |
| input | Request-Body (maskiertes JSON) | Übermittelte Operationsdaten |
| created_at | `date('Y-m-d H:i:s')` | Operationszeitpunkt |

**Filterung sensibler Felder**: rekursiver Durchlauf des Request-Bodys; die Werte folgender Felder werden durch `***` ersetzt:

`password`, `old_password`, `new_password`, `new_password_confirmation`, `token`, `secret`, `access_token`, `refresh_token`

**Quellenerkennung** (`detectSource()`): nach Priorität:

1. Zuerst den benutzerdefinierten Header `X-Client-Platform` lesen (von nativen Clients explizit deklariert)
2. Fallback auf User-Agent-String-Inferenz (Erkennungsreihenfolge der Methode `detectSource()`):

| Plattform | UA-Schlüsselwörter |
|------|----------|
| iPadOS | `iPad` |
| macOS | `Macintosh`, `Mac OS` |
| Windows | `Windows` |
| Linux | `Linux` |
| iOS | `iPhone`, `iOS`, `CFNetwork` |
| Android | `Android` |
| HarmonyOS | `HarmonyOS`, `OpenHarmony` |
| Web | Fallback-Standardwert |

**Fehlertoleranz**: Ausnahmen beim Protokollschreiben blockieren keine Geschäftsanfragen (`catch (\Throwable)` wird still verschluckt).

### 7.2 Sicherheitsprotokolle

**Dateispeicherort**: `runtime/logs/security.log`

**Protokollierter Inhalt**:
- Protokolle über Angriffsblockaden: Angriffskategorie, IP, Pfad, Feld, Quelle, Payload-Ausschnitt (erste 200 Zeichen)
- IP-Sperrbenachrichtigungen: gesperrte IP, Auslöseanzahl

Die Protokollberechtigung ist `FILE_APPEND | LOCK_EX`, was paralleles sicheres Schreiben gewährleistet.

---

## 8. Datenschutz

Das System verfolgt eine dreistufige Datenschutzstrategie, die den drei Phasen des Datenflusses entspricht.

### 8.1 Transportebene — EncryptionService

`EncryptionService` verwendet das Paket `erikwang2013/encryption` zur Ver-/Entschlüsselung sensibler Felder in API-Anfragen/-Antworten.

**Technische Details**:
- Algorithmus: `aes-256-cbc-hmac` (integrierte HMAC-Signatur verhindert Manipulation)
- Schlüssel: Umgebungsvariable `ENCRYPTION_KEY`, automatisch auf 32 Bytes ausgerichtet
- Verwendung: Übertragung von Feldern wie Telefonnummern und Personalausweisnummern zwischen Client und API

**Maskierungs-Hilfsmethoden**:
- `maskPhone('13812341234')` → `138****1234`
- `maskEmail('abc@example.com')` → `a***@example.com` (Benutzername länger als 2 Zeichen) oder `a**@example.com`

### 8.2 Speicherebene — Encryptable Cast

Das Modell `AdminUser` verwendet den Eloquent-Cast `Erikwang2013\Encryptable\Encryptable`, mit den entsprechenden Feldern:

- `email` → als Encryptable gecastet, automatische Ver-/Entschlüsselung
- `phone` → als Encryptable gecastet, automatische Ver-/Entschlüsselung
- `id_card` → als Encryptable gecastet, automatische Ver-/Entschlüsselung

Beim Schreiben in die Datenbank automatisch zu Chiffretext verschlüsselt, beim Lesen automatisch zu Klartext entschlüsselt. Der Speicherspaltentyp ist `VARCHAR(500)`, der Chiffretext wird als base64 gespeichert.

**Schlüsselsystem**: nutzt `ENCRYPTABLE_KEY` unabhängig von der Transportverschlüsselung (`ENCRYPTION_KEY`); ein Schlüsselleak bringt nicht die andere Ebene zu Fall.

Schlüsselrotation: die Umgebungsvariable `ENCRYPTION_PREVIOUS_KEYS` unterstützt eine Liste historischer Schlüssel (kommasepariert). Beim Lesen alter Daten wird die Entschlüsselung mit historischen Schlüsseln versucht, beim Rückschreiben wird mit dem aktuellen Schlüssel neu verschlüsselt.

### 8.3 Präsentationsebene — ID-Verschleierung und Maskierung

**Hashids-ID-Verschleierung**: `HashidsService` verwendet das Paket `erikwang2013/hashids`.

- Von externen APIs zurückgegebene BIGINT-IDs der DB werden als Hash-Strings kodiert (z. B. `xK3mN9qR2pL7wV8b`)
- Clients übergeben den Hash-String in Anfragen; das Backend dekodiert ihn automatisch zur ursprünglichen ID
- Salt wird über die Umgebungsvariable `HASHIDS_SALT` injiziert; unterschiedliche Salts ergeben völlig andere Kodier-/Dekodierergebnisse
- Mindestlänge des Hashs 16 Zeichen, 62-stelliger alphanumerischer Zeichensatz
- BaseController stellt die Komfortmethoden `encodeId()`, `decodeId()`, `encodeIds()` bereit

**Export-Maskierung**: beim Excel/PDF-Export (ExportController) werden sensible Felder einheitlich maskiert:
- Telefon: `138****1234`
- E-Mail: `a***@example.com`
- Personalausweis: vollständig verdeckt als `********`

---

## 9. Schlüsselverwaltung

Alle Schlüssel werden über `.env`-Umgebungsvariablen injiziert; Konfigurationsdateien lesen sie mit `getenv()` und haben eingebaute Fallback-Standardwerte (nur für Entwicklungsumgebungen sicher).

| Umgebungsvariable | Zweck | Paket | Produktionsanforderung |
|----------|------|-----|---------|
| JWT_SECRET | JWT-Signaturschlüssel | erikwang2013/jwt-webman | Zufallszeichenfolge mit 64+ Zeichen |
| JWT_ALGORITHM | JWT-Signaturalgorithmus | derselbe | HS256 beibehalten |
| HASHIDS_SALT | Salt für ID-Kodierung | erikwang2013/hashids | Zufallszeichenfolge |
| SNOWFLAKE_DATACENTER_ID | Rechenzentrums-ID (0-31) | erikwang2013/snowflake-php | Bei Einzel-RZ Standardwert belassen |
| ENCRYPTION_KEY | Verschlüsselungsschlüssel Transportebene API | erikwang2013/encryption | 32-Byte-Zufallszeichenfolge |
| ENCRYPTABLE_KEY | Verschlüsselungsschlüssel DB-Speicherebene | erikwang2013/encryptable | 32-Byte-Zufallszeichenfolge, verschieden vom Transportschlüssel |

**Sicherheitsanforderungen**:
- Die Datei `.env` steht in `.gitignore` und darf niemals ins Repository eingecheckt werden
- `.env.example` ist eine öffentliche Vorlagendatei ohne echte Schlüssel
- In der Produktion **müssen** alle Standardschlüssel durch Zufallszeichenfolgen ersetzt werden
- Empfohlen: Schlüssel mit `openssl rand -base64 32` generieren

### Isolierung der Schlüsselspeicherung

| Ebene | Konfigurationsschlüssel | Schlüssel-Umgebungsvariable |
|----|--------|-------------|
| Transportverschlüsselung | `config/encryption.php` → `key` | `ENCRYPTION_KEY` |
| Speicherverschlüsselung | `config/encryptable.php` → `key` | `ENCRYPTABLE_KEY` |
| ID-Verschleierung | `config/hashids.php` → `connections.main.salt` | `HASHIDS_SALT` |
| JWT-Signatur | `config/plugin/erikwang2013/jwt/jwt` | `JWT_SECRET` |

---

## 10. security.txt (RFC 9116)

Das System stellt unter `/.well-known/security.txt` einen RFC-9116-konformen Endpunkt für Sicherheitskontaktinformationen bereit, damit Sicherheitsforscher bei entdeckten Schwachstellen schnell einen Meldekanal finden.

**Zugriffsart**:

```
GET /.well-known/security.txt
```

**Antwortinhalt**:

```text
Contact: mailto:security@erik.xyz
Expires: 2027-05-20T00:00:00.000Z
Preferred-Languages: zh, en
Canonical: https://erik.xyz/.well-known/security.txt
Policy: https://erik.xyz/security-policy
```

**Felderklärung**:

| Feld | Beschreibung |
|------|------|
| Contact | Kontakt für die Meldung von Sicherheitsschwachstellen |
| Expires | Ablaufzeit der Datei, regelmäßige Aktualisierung erforderlich |
| Preferred-Languages | Bevorzugte Kommunikationssprachen |
| Canonical | Kanonische URL dieser Datei |
| Policy | Link zur Sicherheitsrichtlinie/Offenlegungsrichtlinie für Schwachstellen |

Dieser Endpunkt unterliegt keinem Rate-Limit, keiner Authentifizierung und keinem anderen Middleware-Eingriff; jeder kann direkt darauf zugreifen.

---

## 11. Nginx-Sicherheitskonfiguration

Das Projekt stellt `docs/nginx-security.conf` als Referenzkonfiguration zur Härtung des Nginx-Reverse-Proxys in der Produktion bereit.

**Enthaltene Sicherheitsmaßnahmen**:

| Konfigurationspunkt | Zweck |
|--------|------|
| `server_tokens off` | Nginx-Versionsnummer verbergen |
| `client_max_body_size 10m` | Request-Body-Größe begrenzen, in Abstimmung mit SecurityMiddleware (erikwang2013/security-php) |
| `limit_req_zone` | Anforderungsfrequenzbegrenzung auf Nginx-Ebene |
| `limit_conn_zone` | Begrenzung paralleler Verbindungen |
| `add_header`-Sicherheitsheader | Sicherheitsheader wie X-XSS-Protection auf Nginx-Ebene anhängen |
| `if ($request_method)` | Nicht standardkonforme HTTP-Methoden auf Nginx-Ebene ablehnen |
| SSL/TLS-Konfiguration | Moderne TLS-1.2/1.3-Konfiguration, schwache Cipher-Suiten deaktiviert |
| Backend-Header verbergen | `proxy_hide_header` entfernt sensible Header wie die webman-Version |

**Verwendung**: Die Konfiguration aus `docs/nginx-security.conf` in den eigenen Nginx-Server-Block einfügen und an die tatsächliche Domain und Zertifikatspfade anpassen.

---

## 12. Bedrohungsmodell

### 12.1 Geschützte Bedrohungen

| Bedrohungstyp | Angriffsvektor | Verteidigungsebene |
|----------|---------|---------|
| Missbrauch von HTTP-Methoden | TRACE/TRACK-XST-Angriffe, CONNECT-Tunnel-Proxy, WebDAV-Methodensondierung | http_method-Detektor der SecurityMiddleware, 405-Methoden-Whitelist |
| Gezieltes Brute Force | Wiederholte Passwortversuche gegen bestimmte Benutzer | Kontosperre (15 Min. nach 5 Fehlversuchen) + RateLimit (Login 10/min) + Captcha |
| Brute Force | Wiederholte Benutzername/Passwort-Versuche von verteilten IPs | RateLimit (Login 10/min) + Captcha |
| XSS (Cross-Site Scripting) | `<script>`, onerror, javascript: | SecurityMiddleware (erikwang2013/security-php) (5 Muster) + X-XSS-Protection-Response-Header + CSP |
| SQL-Injection | UNION SELECT, OR 1=1, Kommentar-Bypass | SecurityMiddleware (erikwang2013/security-php) (6 Muster) + parametrisierte Eloquent-ORM-Abfragen |
| CSRF (Cross-Site Request Forgery) | Böswillige Websites senden Anfragen im Namen des Nutzers | Origin/Referer-Prüfung in SecurityMiddleware (erikwang2013/security-php) |
| Pfad-Traversal | `../../etc/passwd` | Pfad-Traversal-Muster der SecurityMiddleware (erikwang2013/security-php) + UploadController-Erweiterungs-Whitelist |
| Command Injection | `;ls`, `` `whoami` ``, `$(cat ...)` | SecurityMiddleware (erikwang2013/security-php) (4 Muster) |
| Session-Hijacking | Stehlen von JWT-Tokens | Kurze JWT-Gültigkeit (2h) + Blacklist-Logout + Zweit-Passwortbestätigung für sensible Operationen |
| ID-Enumeration | Datenvolumen durch Durchlaufen numerischer IDs erraten | Hashids-Verschleierung zu Zufallszeichenfolgen |
| Datenleak | DB-Dump / Man-in-the-Middle / Log-Leak | Dreistufige Verschlüsselung/Maskierung + Sensible-Felder-Filterung der OperationLog |
| DoS-Angriffe | Überdimensionierte Request-Bodies / hochfrequente Anfragen | 10-MB-Request-Body-Limit + RateLimit 60/min + IP-Blacklist |
| Privilegienerweiterung | Benutzer mit niedrigen Rechten greifen auf Admin-Schnittstellen zu | RBAC-Autorisierung mit method.path-Granularität |
| Datei-Upload-Angriffe | Doppelerweiterung shell.php.png | Erkennung bösartiger Dateien in SecurityMiddleware (erikwang2013/security-php) |

### 12.2 Bekannte Einschränkungen

| Einschränkung | Auswirkungsbereich | Abhilfemaßnahmen |
|------|---------|---------|
| CSRF-Schutz funktioniert nur für Browser | Nicht-Browser-Clients (curl, Postman, mobile Apps) können Origin/Referer-Prüfungen überspringen | Nicht-Browser-Clients sind von Natur aus immun gegen CSRF; stattdessen auf JWT-Authentifizierung statt Cookies setzen |
| Bei Redis-Ausfall degradieren Rate-Limit und Blacklist zu fail-open | Angreifer können Rate-Limit und Hochfrequenzblockade umgehen | Redis-Verfügbarkeit mit Alerts überwachen; IP-Blacklist unterstützt file/redis/cache-Backends zur Degradation |
| Kein eigenständiger WAF-Engine | Regex-basierte Erkennung, kein dedizierter WAF-Regel-Engine | In Produktion Nginx ModSecurity oder Cloudflare WAF davor empfehlen |
| Zustandsloses JWT kann nicht aktiv ungültig gemacht werden | Tokens können vor Ablauf nicht serverseitig widerrufen werden (außer per Blacklist) | Blacklist + kurzer 2h-TTL reduziert das Risikofenster |
| Admin-Endpunkte ohne spezielles Rate-Limit | Admin-APIs teilen das Standardlimit von 60/min mit normalen APIs | Admin-Operationsfrequenz ist von Natur aus niedrig; vorerst keine Unterscheidung nötig |
| PCRE-Backtracking-Limit | Das Paket hat ein eingebautes Limit von 1.000.000 Backtracks + finally-Wiederherstellung; extrem komplexe Eingaben bergen weiterhin Leistungsrisiko | Request-Body-Größenlimit (10 MB) als Fallback |
