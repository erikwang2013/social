# PHP-Unit-Testbericht
**语言 / Languages:** [中文](php-unit-report.md) · [English](php-unit-report.en.md) · [한국어](php-unit-report.ko.md) · [Русский](php-unit-report.ru.md) · [Deutsch](php-unit-report.de.md) · [Français](php-unit-report.fr.md) · [Español](php-unit-report.es.md) · [Português](php-unit-report.pt.md) · [हिन्दी](php-unit-report.hi.md) · [العربية](php-unit-report.ar.md) · [বাংলা](php-unit-report.bn.md) · [Bahasa Indonesia](php-unit-report.id.md) · [日本語](php-unit-report.ja.md)

- Datum: 2026-08-27
- Ausführung: `vendor/bin/phpunit` (PHPUnit 10.5.64, PHP 8.3.7)
- Umfang: admin/ (webman-Adminpanel) + service/ (webman-Hauptdienst)

## Gesamtübersicht

| Projekt | Testfälle | Assertions | Ergebnis |
|------|------|------|------|
| service | 136 | 348 | ✅ Alle bestanden (OK) |
| admin | 60 | 136 | ⚠️ 49 bestanden / 4 Fehler / 7 fehlgeschlagen |

## service (vollständig grün)

- Neue Testdateien (diese Charge): AuthMiddlewareTest, UserBriefTest, SearchSyncTest, ActionHandlerTest, JwtHelperTest, VoiceControllerTest, MonitorTest, ModelRelationTest usw.; nach Zusammenführung mit den bestehenden 24 Testdateien insgesamt 136 Fälle, alle bestanden
- Abgedeckte Module: Authentifizierung/Middleware/JWT, Benutzer, Beiträge, Kommentare, Follower, Benachrichtigungen, Such-Synchronisierung, IM, Räume, Anrufe (CallCenter/CallState), Sprache, Modellbeziehungen, Aktionsverarbeitung (WS)

### Fix: zufälliges Hängen der Testsuite (wichtig)

- Symptom: Beim Volllauf friert der Prozess zufällig ein; Einzeldatei-/Teilläufe bestehen
- Ursache: `new Worker()` in `ActionHandlerTest::setUp` registriert die Instanz in der **statischen Registry** `Worker::$workers`; danach sieht jeder `CallCenter::start` „es existiert ein Worker" und ruft `Timer::add` → `pcntl_alarm(1)` installiert einen SIGALRM-Timer, der Prozess hängt beim Beenden
- Fix: setUp erstellt einen Snapshot der Registry, tearDown stellt sie wieder her (`ReflectionProperty` schreibt `workers`/`pidMap` zurück)
- Ort: `service/tests/ActionHandlerTest.php`

## admin (49/60; Fehlschläge sind alle vorbestehende Tests und betreffen Umgebung/Konfiguration)

| Testfall | Fehlergrund | Kategorie |
|------|----------|------|
| EnvConfigTest (4 fehlgeschlagen + 1 Fehler) | `admin/.env` existiert nicht; getenv/dotenv-Assertions schlagen fehl | Testumgebung ohne .env |
| CaptchaTest (3 Fehler + 1 fehlgeschlagen + 1 risky) | Captcha hängt von laufendem Dienst/Redis ab; Unit-Test-Umgebung liefert null | Umgebungsabhängigkeit |
| BackendEnhancementTest (2 fehlgeschlagen) | Assertiert Existenz von `app/middleware/Cors` und searchable in admin_user — aktuelle Konfiguration entspricht den Assertions nicht | Veraltete Konfigurations-Assertions |

Hinweis: admin/tests sind alles historisch vorbestehende Dateien; in dieser Charge wurden keine neuen Admin-Unit-Tests hinzugefügt (Fokus lag auf service).

## Nicht abgedeckt / nachzuholen

- admin-Module (model/middleware/view) ohne Unit-Tests
- service-Pfade, die von externen Diensten (ES/gRPC) abhängen, wurden nur unit-seitig per Stub validiert; Integrationstests werden über API-Tests empfohlen
