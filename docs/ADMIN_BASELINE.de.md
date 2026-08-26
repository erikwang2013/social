# Admin-Basis-Abnahme (M0, 2026-08-17)

**语言 / Languages:** [中文](ADMIN_BASELINE.md) · [English](ADMIN_BASELINE.en.md) · [한국어](ADMIN_BASELINE.ko.md) · [Русский](ADMIN_BASELINE.ru.md) · [Deutsch](ADMIN_BASELINE.de.md) · [Français](ADMIN_BASELINE.fr.md) · [Español](ADMIN_BASELINE.es.md) · [Português](ADMIN_BASELINE.pt.md) · [हिन्दी](ADMIN_BASELINE.hi.md) · [العربية](ADMIN_BASELINE.ar.md) · [বাংলা](ADMIN_BASELINE.bn.md) · [Bahasa Indonesia](ADMIN_BASELINE.id.md) · [日本語](ADMIN_BASELINE.ja.md)

Basisstatus und Umbau-Einstiegspunkte für open-admin (webman v2 + Flutter-Verwaltungsoberfläche).

## Aktuelle Version und Laufzeitstatus

| Punkt | Wert |
|---|---|
| Framework | webman v2 (workerman/webman-framework **v2.2.3**) |
| PHP | 8.3.7 (CLI) |
| Abhängigkeiten | `composer install` erfolgreich, 69 Pakete |
| .env | **Vorhanden: nein** (das Repository enthält weder `.env` noch `.env.example`; muss lokal nach MySQL/Redis erstellt werden) |
| Migrations-Einstieg | Keine (`think`/`artisan` nicht vorhanden; webman hat keine eingebaute Migration, M0 hat keine Migrationsaufgaben) |
| Tests | `vendor/bin/phpunit`: 60 tests / 136 assertions, **4 errors / 7 failures / 6 warnings / 1 risky — nicht vollständig grün** |

## Aktivierte Module (laut README bestätigt)

- **JWT-Authentifizierung**: Login/Refresh/Logout, Klick-Captcha, Kontosperrung (5 Fehlversuche → 15 Minuten Sperre), Begrenzung paralleler Sitzungen (≤3 Tokens pro Benutzer)
- **RBAC**: Rollen-/Berechtigungsbaum, Autorisierung mit method.path-Granularität
- **Betriebsprüfung**: Log-Abfrage + Erkennung von 8 Plattform-Quellen
- **Dateiverwaltung**: Upload / Excel-Export / PDF-Export (maskiert)
- **i18n**: Umschalten Chinesisch/Englisch (Accept-Language / ?lang=)
- Sonstiges: Dashboard (Redis-Cache), Systemkonfiguration, Health Check/metrics/OpenAPI 3.0, 18-stufiger Sicherheitsschutz

## Details der Testfehler (allesamt bestehende Projektrlücken, nicht durch diese Änderung verursacht)

| Testgruppe | Fehler | Ursache |
|---|---|---|
| `EnvConfigTest` (5 Fälle) | 4 failure + 1 error | Tests fordern, dass `.env`/`.env.example` existieren müssen und getenv-Werte für `APP_NAME`/`JWT_SECRET_KEY`/`DB_HOST` usw. gesetzt sind; das Repository enthält keine Beispiel-env |
| `CaptchaTest` (4 Fälle) | 3 error + 1 failure (außerdem 1 risky ohne Assertions) | Klick-Captcha hängt von Redis-Speicher ab, lokal nicht vorhanden |
| `BackendEnhancementTest` (2 Fälle) | 2 failure | Behauptet, dass die Datenquelle `user` searchable und Middleware cors/rate_limit enthält — Drift zwischen Konfiguration und Test-Assertions |

Lokale Schritte zur Wiederherstellung von „alles grün": `.env` gemäß den Konfigurationsschlüsseln in `config/` erstellen (die von EnvConfigTest benötigten Schlüssel ergänzen), MySQL + Redis bereitstellen (für CaptchaTest), und der Verantwortliche entscheidet über die zwei Konfigurationsdrifts in BackendEnhancementTest.

## gRPC-Bereitschaft (T3)

- Composer-Pakete installiert: `grpc/grpc 1.82.0`, `google/protobuf 5.35` (`--no-plugins` umgeht den Bug des doppelten Ladens des security-php-Plugins)
- PHP-Stubs generiert: `admin/generated/` (`Social/Admin/V1/AdminServiceClient.php` usw., einschließlich der drei Vertragssätze: infra/user)
- **grpc-PHP-Erweiterung nicht installiert**: pecl hat keine Schreibrechte und sudo benötigt ein Passwort; `sudo pecl install grpc` ist vor dem Ausführen des gRPC-Clients erforderlich

## Umbau-Einstiegspunkte (acht neue Punkte aus §3.4 des Designdokuments)

1. Content-Moderation-Workbench: zweisprachige Gegenüberstellung von Beiträgen/Kommentaren/Bildern, mehrsprachige Vorlagen für Ablehnungsgründe, Nutzersanktionen
2. Warteschlange für Meldungsbearbeitung
3. GDPR-Anfrageschalter (Export-/Lösch-Tickets)
4. Daten-Dashboard-Anbindung an bee_tsdb
5. i18n-Begriffsverwaltung (gemeinsames CRUD für vier Clients)
6. Geschenk-Bibliotheksverwaltung (SKU, Preis, Effekte, mehrsprachige Namen)
7. Live-Provider-Konfiguration (Routing-Strategie, Umschaltreihenfolge)
8. Prüfung von Auszahlungsanträgen

**gRPC-Integrationspunkte**: die Vertrags-Stubs auf Admin-Seite liegen in `admin/generated/` (Wiederverwendung von `Social/Admin/V1` für Liveness-Probes + spätere Geschäftsnachrichten); Aufrufe an service laufen über `Social\User\V1\UserServiceClient`, an infrastructure über `Social\Infra\V1\InfraServiceClient`; die Liveness-Probe-Kette mit service/infrastructure ist in `service/README.grpcs.md` und den T10-Integrationsproben beschrieben.
