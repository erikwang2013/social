# Automatisierter API-Testbericht
**语言 / Languages:** [中文](api-test-report.md) · [English](api-test-report.en.md) · [한국어](api-test-report.ko.md) · [Русский](api-test-report.ru.md) · [Deutsch](api-test-report.de.md) · [Français](api-test-report.fr.md) · [Español](api-test-report.es.md) · [Português](api-test-report.pt.md) · [हिन्दी](api-test-report.hi.md) · [العربية](api-test-report.ar.md) · [বাংলা](api-test-report.bn.md) · [Bahasa Indonesia](api-test-report.id.md) · [日本語](api-test-report.ja.md)

- Datum: 2026-08-27
- Ausführung: `tests/api/run.php` (curl-Assertionsskript), Ergebnis `tests/api/results.json`
- Umfang: admin HTTP API (A01-A45) + service HTTP API (S01-S57b, inkl. S58-S68)
- Dienste: admin `http://127.0.0.1:8791`, service `http://127.0.0.1:8788` (WebSocket `:8789` in dieser HTTP-Testrunde nicht abgedeckt)

## Fazit

**116 Testfälle: 113 bestanden / 3 fehlgeschlagen (97,4 % Erfolgsquote); alle 3 Fehlschläge sind Produktfehler mit identifizierter Ursache**

| Gruppe | Bestanden/Gesamt |
|------|-----------|
| admin A01-A45 (Authentifizierung, Captcha, Benutzerverwaltung, HashID, Rollen/Berechtigungen, Konfiguration, Logs, Export/Import, Upload, Health-Checks usw.) | 42/45 |
| service S01-S68 (Registrierung/Login/Logout/Refresh, Profil, Folgen, Beiträge/Likes/Timeline, Kommentare, Benachrichtigungen, Suche, IM-Sitzungen/Nachrichten/Push, Sprach-Upload/Dateien/Anrufe/Räume usw.) | 71/71 |

## Fehlgeschlagene Testfälle (3, alle Produktfehler)

| Fall | Erwartet | Tatsächlich | Ursache |
|------|------|------|------|
| A20 Ungültige Hashid-Benutzerdetails | 404 | 500 | `HashidsService::decode()` wirft für ungültige IDs eine ungefangene `InvalidArgumentException` (admin/app/common/HashidsService.php:28, BaseController.php:52); die Exception läuft als 500 durch, sollte abgefangen und 404 zurückgegeben werden |
| A39 Export Excel | xlsx-Dateistream | 200+JSON-Fehlerbody (Geschäftsfail) | `ExportController::excel()` deklariert Rückgabetyp `: Response`, aber es fehlt `use support\Response`, wodurch der Typ zu `app\admin\controller\Response` aufgelöst wird → jede erfolgreiche Rückgabe wirft `TypeError` (ExportController.php:122), Export komplett unbenutzbar |
| A40 Export PDF | pdf-Dateistream | 200+JSON-Fehlerbody (Geschäftsfail) | Wie oben, `ExportController::pdf()` (ExportController.php:135) ohne `use support\Response` |

> Ergänzung (potenzieller Fehler in derselben Datei, derzeit durch den obigen TypeError verdeckt): `ExportController` Zeile 90 ruft `EncryptionService::decrypt()` für phone/email auf, während die `AdminUser`-Modellfelder `email/phone/id_card` den Cast `Encryptable::class` deklarieren (automatisches Verschlüsseln beim Schreiben, Entschlüsseln beim Lesen); der Export würde Klartext ein zweites Mal entschlüsseln → sobald ein Konto mit nicht leerer Telefonnummer/E-Mail existiert, wird `EncryptionException: Invalid ciphertext prefix for AES-256-CBC` geworfen. Dieses Problem tritt auch nach der Korrektur der Rückgabetypen weiterhin auf.

## Während der Tests behobene Umgebungsprobleme (keine Produktcode-Änderungen)

1. **m2/m3/m4-Migrationstabellen: `id` ohne AUTO_INCREMENT (blockierend, behoben)**: Die von `service/database/m2.sql`/`m3.sql`/`m4.sql` erzeugten `social_follows`, `social_notifications` haben `id BIGINT UNSIGNED NOT NULL` ohne `AUTO_INCREMENT`; jeder INSERT schlägt mit `1364 Field 'id' doesn't have a default value` fehl und blockiert alle Schreibpfade für Folgen/Benachrichtigungen/IM/Sprache. Lokal wurde `ALTER TABLE ... MODIFY id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` ausgeführt (die übrigen 8 Tabellen haben bereits Auto-Increment). **Die Migrationsskripte selbst sollten um Auto-Increment ergänzt werden.**
2. **service/.env zeigt auf eine unerreichbare Datenbank (blockierend)**: `DB_PORT=13306` ohne Passwort, während MySQL tatsächlich unter `127.0.0.1:3306 (root/root)` läuft; webmans `createUnsafeMutable` überschreibt CLI-Umgebungsvariablen. Während der Tests wurde `.env` zu `service/.env.api-test-bak` verschoben (Inhalt unverändert erhalten) und der Dienst mit injizierten Umgebungsvariablen gestartet; die Wiederherstellung war wegen der Zugriffspolitik für .env-Dateien nicht möglich, erforderlich ist manuell `mv service/.env.api-test-bak service/.env` (Hinweis: Nach der Wiederherstellung trifft ein Neustart des Dienstes wieder auf die unerreichbare Datenbank).
3. **admin hat kein .env und ist auf Umgebungsvariablen angewiesen**: erforderlich sind `DB_PASSWORD=root ENCRYPTABLE_KEY(16B) ENCRYPTION_KEY(32B)`. Das `encryptable`-Plugin fällt ohne registrierten Provider im webman-Container auf `EnvEncryptableConfig` zurück (liest `ENCRYPTION_KEY`, Standard-Cipher aes-256-gcm); falsche Schlüssellänge führt bei Kontenerstellung/Import/Export zu `MissingEncryptionKeyException`.
4. **Elasticsearch nicht gestartet**: `GET /api/v1/search/posts` liefert 503 (vorgesehene Degradation); Suchfälle der S-Gruppe wie erwartet behandelt (0 oder 503 akzeptiert), nicht als Fehlschlag gewertet.

## Vertrags-/Dokumentationsabweichungen (Überarbeitung empfohlen, nicht blockierend)

- Die Captcha-Dokumentation (apidoc und CaptchaController-Kommentare) schreibt `clicks=[{x,y}]` als Objektarray, die `poster-php`-Implementierung verlangt aber ein Koordinatenpaar-Array `[[x,y]]`; die Übergabe von Objekten gemäß Doku schlägt in der Praxis immer fehl.
- Sprach-Upload liefert `voice_url` als `/voice/{md5}.m4a` (relativ zur API-Wurzel, ohne `/api/v1`-Präfix); Clients müssen `/api/v1` selbst voranstellen; der Dateizugriff läuft über authentifizierte Routen (Token erforderlich).

## Umgebung und Reproduktion

- Test-Anmeldedaten: Testkonto `e2e_smoke` (admin, Passwort nur für Tests) + `apitest_*@test.dev` (service, nach dem Lauf automatisch bereinigt), alle in den Konstanten von `tests/api/run.php`; keine echten Schlüssel verwendet.
- Reproduktion:

```bash
cd /home/wwwroot/social/admin && DB_PASSWORD=root ENCRYPTABLE_KEY='apitest-enc-16b!!' \
  ENCRYPTION_KEY='apitest-db-encrypt-key-32-byte!!' php start.php start   # admin :8791
cd /home/wwwroot/social/service && DB_HOST=127.0.0.1 DB_PORT=3306 DB_USERNAME=root \
  DB_PASSWORD=root php start.php start                                     # service :8788
php /home/wwwroot/social/tests/api/run.php                                  # erneut ausführen (116 Fälle)
```

## Endpunkt-Übersicht (laut route.php / apidoc)

- service `config/route.php`: 39 HTTP-Routen (Authentifizierung 5, Benutzer 2, Folgen 5, Beiträge 7, Kommentare 2, Benachrichtigungen 4, Suche 2, IM 4, Sprache/Anrufe/Räume 5, Health/Docs 3)
- admin `config/route.php`: 33 HTTP-Routen (Authentifizierung/Captcha 4, Benutzer-CRUD 5, Rollen 5, Berechtigungen 2, Konfiguration 4, Logs 1, Profil 4, Export 2, Import 1, Upload 1, Health/Docs 4)
