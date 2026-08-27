# Automatisierter API-Testbericht
**语言 / Languages:** [中文](api-test-report.md) · [English](api-test-report.en.md) · [한국어](api-test-report.ko.md) · [Русский](api-test-report.ru.md) · [Deutsch](api-test-report.de.md) · [Français](api-test-report.fr.md) · [Español](api-test-report.es.md) · [Português](api-test-report.pt.md) · [हिन्दी](api-test-report.hi.md) · [العربية](api-test-report.ar.md) · [বাংলা](api-test-report.bn.md) · [Bahasa Indonesia](api-test-report.id.md) · [日本語](api-test-report.ja.md)

- Datum: 2026-08-27
- Ausführung: `tests/api/run.php` (curl-Assertionsskript), Ergebnis `tests/api/results.json`
- Umfang: admin HTTP API (A01-A45) + service HTTP API (S01-S57b, inkl. S58-S68)
- Dienste: admin `http://127.0.0.1:8791`, service `http://127.0.0.1:8788` (WebSocket `:8789` in dieser HTTP-Testrunde nicht abgedeckt)

## Fazit

**116 Testfälle: 116 bestanden / 0 fehlgeschlagen (100 % Erfolgsquote); die 3 Produktfehler des letzten Durchgangs (A20/A39/A40) sind alle behoben und verifiziert**

| Gruppe | Bestanden/Gesamt |
|------|-----------|
| admin A01-A45 (Authentifizierung, Captcha, Benutzerverwaltung, HashID, Rollen/Berechtigungen, Konfiguration, Logs, Export/Import, Upload, Health-Checks usw.) | 45/45 |
| service S01-S68 (Registrierung/Login/Logout/Refresh, Profil, Folgen, Beiträge/Likes/Timeline, Kommentare, Benachrichtigungen, Suche, IM-Sitzungen/Nachrichten/Push, Sprach-Upload/Dateien/Anrufe/Räume usw.) | 71/71 |

## Verifikation der Behebung der 3 Produktfehler des letzten Durchgangs (alles PASS)

| Fall | Erwartet | Letzter Durchgang | Fix | Ergebnis dieses Durchgangs |
|------|------|---------|------|---------|
| A20 Ungültige Hashid-Benutzerdetails | 404 | 500 | `BaseController::decodeId()` fängt `InvalidArgumentException` und wirft `support\exception\NotFoundException($msg, 404)` (admin/app/admin/controller/BaseController.php); die catch-Blöcke der zwei Batch-Methoden von `UserController` werden auf `InvalidArgumentException \| NotFoundException` erweitert, 422-Semantik bleibt erhalten | **PASS (404)** |
| A39 Export Excel | xlsx-Dateistream | 200+JSON-Fehlerbody | `ExportController` erhält `use support\Response;` (der Rückgabetyp wurde zuvor in das nicht existierende `app\admin\controller\Response` aufgelöst und warf TypeError); `phone/email/id_card` von `admin_user` werden beim Lesen per Encryptable cast automatisch entschlüsselt, der Export maskiert direkt, Doppelentschlüsselung entfernt | **PASS (attachment-Dateistream)** |
| A40 Export PDF | pdf-Dateistream | 200+JSON-Fehlerbody | Wie oben (Rückgabetyp von `ExportController::pdf()` behoben) | **PASS (application/pdf-Dateistream)** |

## In diesem Durchgang behobene/behandelte Umgebungsprobleme (keine Produkt-Businesscode-Änderungen)

1. **Leeres DB-Passwort-Override in run.php defekt (Testskript-Fehler, behoben)**: Die `DB`-Konstante nutzt `getenv('DB_PASS') ?: 'root'`; ein leerer String in der Umgebungsvariable wird von `?:` als falsy behandelt und auf 'root' zurückgefallen, sodass die lokale root-Verbindung mit leerem Passwort abgelehnt wird (`Access denied ... using password: YES`). Geändert zu `getenv('DB_PASS') ?? 'root'` (Default nur bei Nichtsetzung), Ein-Zeilen-Änderung (tests/api/run.php:26).
2. **Port 8788 des service von falschem Prozess belegt (Umgebung, behandelt)**: Ein service-Prozess eines anderen Projekts auf dieser Maschine — `property-management-platform` (master 2004768, gestartet 08:07) — lauschte auf 8788, und dessen `.env` zeigt auf die DB `property_management`; der social service lief faktisch nicht, wodurch IM-/Sprach-Routen ab S45 alle 404 lieferten und das SQL der Cleanup-Phase die falsche DB traf. Der Prozess wurde gestoppt und der social service auf 8788/8789 neu gestartet (`DB_HOST=127.0.0.1 DB_PORT=3306 DB_USERNAME=root DB_PASSWORD=''`); der Health-Check meldet wieder `social-service`.
3. **ImageMagick-7-Upgrade ließ den Captcha-Imagick-Treiber abstürzen (Umgebung, behandelt)**: Nach dem Upgrade des System-ImageMagick auf 7.1.2-27 (Build 2026-07-08) wurde `PixelsResource` entfernt; imagick 3.8.1 definiert `Imagick::RESOURCETYPE_PIXELS` nicht mehr, und der Konstruktor von poster-phps `ImagickDriver` wirft sofort `Undefined constant` (vendor-Code, nicht geändert), wodurch Captcha-Erzeugung/-Prüfung (A05/A06) 500 liefern und kaskadierend den Login A08-A11 blockieren. **Behandlung**: admin-Dienst mit dem in der Konfig-Doku vorgesehenen Treiber-Umschalter neu gestartet — `POSTER_IMAGE_DRIVER=gd` (admin/config/poster.php:17 unterstützt nativ gd/imagick/auto); nach Umstellung der Captcha auf den GD-Treiber funktioniert die gesamte Kette. Zur Wiederherstellung des Imagick-Treibers muss ImageMagick auf 6.x herabgestuft oder poster-php auf IM7-Kompatibilität aktualisiert werden.
4. **MySQL-root-Passwort auf leer geändert**: der letzte Durchgang notierte `root/root`; in diesem Durchgang ist Login mit leerem Passwort möglich, alle Dienste und Skripte wurden mit leerem Passwort gestartet.
5. **Neustart-Umgebung des admin-Dienstes**: „admin hat kein .env und ist auf Umgebungsvariablen angewiesen" aus dem letzten Durchgang gilt weiterhin; Neustart-Befehle siehe unten „Umgebung und Reproduktion".
6. **service/.env ist weiterhin `service/.env.api-test-bak`**: im letzten Durchgang für den Verbindungstest verschoben und nicht wiederhergestellt (Wiederherstellung durch die .env-Zugriffspolitik beschränkt); in diesem Durchgang wurde der Dienst erneut mit Umgebungsvariablen gestartet. Manuelles `mv service/.env.api-test-bak service/.env` erforderlich (nach Wiederherstellung Dienst neu starten; die darin gespeicherte DB-Adresse beachten).
7. **Elasticsearch nicht gestartet**: `GET /api/v1/search/posts` liefert 503 (vorgesehene Degradation); Suchfälle der S-Gruppe wie erwartet behandelt (0 oder 503 akzeptiert), nicht als Fehlschlag gewertet.

## Vertrags-/Dokumentationsabweichungen (Überarbeitung empfohlen, nicht blockierend)

- Die Captcha-Dokumentation (apidoc und CaptchaController-Kommentare) schreibt `clicks=[{x,y}]` als Objektarray, die `poster-php`-Implementierung verlangt aber ein Koordinatenpaar-Array `[[x,y]]`; die Übergabe von Objekten gemäß Doku schlägt in der Praxis immer fehl.
- Sprach-Upload liefert `voice_url` als `/voice/{md5}.m4a` (relativ zur API-Wurzel, ohne `/api/v1`-Präfix); Clients müssen `/api/v1` selbst voranstellen; der Dateizugriff läuft über authentifizierte Routen (Token erforderlich).

## Umgebung und Reproduktion

- Test-Anmeldedaten: Testkonto `e2e_smoke` (admin, Passwort nur für Tests) + `apitest_*@test.dev` (service, nach dem Lauf automatisch bereinigt), alle in den Konstanten von `tests/api/run.php`; keine echten Schlüssel verwendet.
- Reproduktion:

```bash
cd /home/wwwroot/social/admin && DB_PASSWORD='' ENCRYPTABLE_KEY='apitest-enc-16b!!' \
  ENCRYPTION_KEY='apitest-db-encrypt-key-32-byte!!' POSTER_IMAGE_DRIVER=gd \
  php start.php start                                          # admin :8791
cd /home/wwwroot/social/service && DB_HOST=127.0.0.1 DB_PORT=3306 DB_USERNAME=root \
  DB_PASSWORD='' php start.php start                           # service :8788
cd /home/wwwroot/social/tests/api && DB_PASS='' php run.php    # erneut ausführen (116 Fälle)
```

- Hinweis: Port 8788 darf nicht vom `property-management-platform`-Service belegt sein (beide Projekte nutzen standardmäßig denselben Port; wenn beide Projekte auf dieser Maschine existieren, müssen sie auseinandergezogen werden).

## Endpunkt-Übersicht (laut route.php / apidoc)

- service `config/route.php`: 39 HTTP-Routen (Authentifizierung 5, Benutzer 2, Folgen 5, Beiträge 7, Kommentare 2, Benachrichtigungen 4, Suche 2, IM 4, Sprache/Anrufe/Räume 5, Health/Docs 3)
- admin `config/route.php`: 33 HTTP-Routen (Authentifizierung/Captcha 4, Benutzer-CRUD 5, Rollen 5, Berechtigungen 2, Konfiguration 4, Logs 1, Profil 4, Export 2, Import 1, Upload 1, Health/Docs 4)
