# PHP-Unit-Testbericht
**语言 / Languages:** [中文](php-unit-report.md) · [English](php-unit-report.en.md) · [한국어](php-unit-report.ko.md) · [Русский](php-unit-report.ru.md) · [Deutsch](php-unit-report.de.md) · [Français](php-unit-report.fr.md) · [Español](php-unit-report.es.md) · [Português](php-unit-report.pt.md) · [हिन्दी](php-unit-report.hi.md) · [العربية](php-unit-report.ar.md) · [বাংলা](php-unit-report.bn.md) · [Bahasa Indonesia](php-unit-report.id.md) · [日本語](php-unit-report.ja.md)

- Datum: 2026-08-27
- Ausführung: `vendor/bin/phpunit` (PHPUnit 10.5.64, PHP 8.3.7)
- Umfang: admin/ (webman-Adminpanel) + service/ (webman-Hauptdienst)

## Gesamtübersicht

| Projekt | Testfälle | Assertions | Ergebnis |
|------|------|------|------|
| service | 159 | 408 | ✅ Alle bestanden (OK) |
| admin | 67 | 180 | ✅ Alle bestanden (OK) |

## Umgebungshinweise

- MySQL 127.0.0.1:3306 (root, leeres Passwort); Datenbanken `social` (social_*) und `open_admin` (erik_*) angelegt und mit Daten befüllt (super_admin-Rolle, 39 Berechtigungen)
- Redis 127.0.0.1:6379 läuft (Captcha-Speicher `poster:captcha:*`); Elasticsearch nicht gestartet (Health-Check degradiert auf unavailable, gilt nicht als Fehlschlag)
- service läuft auf 8788, admin auf 8791
- service und admin haben beide kein `.env` (das Repo hat die fälschlich eingecheckten env entfernt, commit e5379fc); die Apps laufen über die Fallbacks `getenv('X') ?: Standardwert` in `config/*.php`
- **Imagick-Erweiterung geladen, aber Konstante `RESOURCETYPE_PIXELS` fehlt** (dieser Build hat nur den neuen RESOURCETYPE_*-Konstantensatz); der ImagickDriver-Konstruktor von poster-php referenziert diese Konstante und stürzt ab

## service (159/159 komplett grün)

- Identisch mit der Baseline der letzten Charge; abgedeckt: Authentifizierung/Middleware/JWT, Benutzer, Beiträge, Kommentare, Follower, Benachrichtigungen, Such-Synchronisierung, IM, Räume, Anrufe (CallCenter/CallState), Sprache, Modellbeziehungen, Aktionsverarbeitung (WS)
- M5 ergänzte das Live-Modul (LiveCenter: erstellen/detail/danmaku/mikro-link/schließen), 23 Fälle, keine Regressionen

## admin (letzte Charge 49/60 → diese Charge 67/67 komplett grün)

### Fix: echter Code-Defekt (1 Stelle)

| Ort | Ursache | Fix |
|------|------|------|
| `config/poster.php` | `image.driver` standardmäßig `auto`; DriverFactory wählt bei erkannter Imagick-Erweiterung ImagickDriver, dem auf dieser Maschine die Konstante `RESOURCETYPE_PIXELS` fehlt → Captcha-Erzeugung/Poster direkt 500 (Online-Dienst ebenso betroffen) | Konstanten-Guard in der Treibererkennung ergänzt: `getenv('POSTER_IMAGE_DRIVER') ?: (defined('Imagick::RESOURCETYPE_PIXELS') ? 'auto' : 'gd')`; bei fehlender Konstante automatischer Fallback auf GD |

### Fix: veraltete Assertions (nach Abgleich mit dem aktuellen Code aktualisiert)

| Testdatei | Testfall | Ursache | Korrektur |
|----------|------|------|------|
| EnvConfigTest | env_file_exists / env_example_file_exists / getenv_reads_env_variables / config_env_keys_exist_in_dotenv (4 fehlgeschlagen + 1 Fehler) | Assertiert Existenz von `.env`/`.env.example` und getenv-Werte; das Repo hat die env-Dateien aber entfernt und sie sind nicht rekonstruierbar | Als „ohne .env laufen"-Kontrakt neu geschrieben: jeder `getenv()`-Schlüssel muss einen `?:`-Standardwert haben, Standardkonfiguration zeigt auf lokale Dienste (127.0.0.1:3306/open_admin), Typen der kritischen Konfiguration korrekt |
| BackendEnhancementTest | test_admin_user_source_contains_searchable | AdminUser nutzt das Searchable-Trait nicht mehr (stattdessen `Erikwang2013\Encryptable\Encryptable` für transparente Feld-Ver-/Entschlüsselung; `toSearchableArray()` bleibt erhalten) | Assertion auf das Encryptable-Trait umgestellt; die toSearchableArray-Assertion bestand ohnehin, bleibt |
| BackendEnhancementTest | test_middleware_config_contains_cors_and_rate_limit | `config/middleware.php` nutzt nun das `'@'`-Globalschlüssel-Format; das Top-Level-Array enthält die Middleware-Klassen nicht mehr direkt | Assertion prüft nun, ob `$middlewares['@']` Cors und RateLimit enthält |
| CaptchaTest | alle 7 Fälle (vorher 6 Fehler + 1 fehlgeschlagen) | Doppelte Veraltung: (a) fehlende Imagick-Konstante (bereits durch poster.php behoben); (b) Assertions basieren auf altem poster-php-Vertrag — `extra.targets` (mit x/y) wurde zu `extra.texts` (nur text+order), Koordinaten liegen nur in der Speicherschicht; Klick-Format von `['x'=>, 'y'=>]` zu `[x, y]`-Zahlenpaaren geändert | Nach aktuellem Vertrag neu geschrieben: Struktur/Schwierigkeits-Anzahlen (2/3/4)/Feldvalidierung; korrekter Klick liest Koordinaten aus Redis (`poster:captcha:{key}` → `data.targets`) und validiert; falscher Klick schlägt fehl; nach max_attempts (3) wird der key konsumiert/gelöscht; key-Eindeutigkeit |

### Neue Tests (1 Datei, 12 Fälle)

`tests/AdminControllerTest.php` (mit Copyright-Header), abgedeckt:

- **BaseController::decodeId** (das gerade korrigierte 404-Verhalten): encode/decode-Roundtrips konsistent; ungültige hashid wirft `support\exception\NotFoundException` mit code=404; encodeIds ändert nur ID-Felder
- **RoleController**: super_admin-Rolle update gibt 403 zurück (echte DB-Daten)
- **PermissionController::buildTree**: Berechtigungsbaum verschachtelt (2 Ebenen) + alle Knoten-IDs hashidisiert
- **ConfigController**: fehlendes group/key/value → Validierung 422; ungültige hashid → 404
- **ExportController**: `admin_user`-Export der sensiblen Felder als phone/email/id_card (übrige Tabellen leer); PDF-HTML escaped Titel/Zellenwerte mit htmlspecialchars (XSS-Schutz) und enthält Copyright-Hinweis

### Bekannte Hinweise

- Der in Tests konstruierte webman-Request wird als roher HTTP-Stream (buffer) übergeben — der workerman-Request-Konstruktorparameter ist buffer; nur method/uri reicht nicht, um den POST-Body zu parsen; siehe Kommentare in AdminControllerTest
- Der Captcha-Korrekt-Klick-Fall liest die gespeicherten Ziele aus Redis; ist Redis nicht verfügbar, wird der Fall mit markTestSkipped übersprungen und beeinflusst das Suite-Ergebnis nicht

## Nicht abgedeckt / nachzuholen

- Encryptable-Ver-/Entschlüsselung der admin-Modelle, OperationLog/AdminPermission-Middleware und die RBAC-Cache-Pfade haben weiterhin keine Unit-Tests; empfohlen per API-Tests oder in einer späteren Charge
- service-Pfade, die von externen Diensten (ES/gRPC) abhängen, bleiben unit-seitig nur per Stub validiert; die Integrationsebene wird über API-Tests abgedeckt
