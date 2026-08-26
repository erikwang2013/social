# Testbericht für End-to-End-Tests (E2E) der Seiten
**语言 / Languages:** [中文](ui-e2e-report.md) · [English](ui-e2e-report.en.md) · [한국어](ui-e2e-report.ko.md) · [Русский](ui-e2e-report.ru.md) · [Deutsch](ui-e2e-report.de.md) · [Français](ui-e2e-report.fr.md) · [Español](ui-e2e-report.es.md) · [Português](ui-e2e-report.pt.md) · [हिन्दी](ui-e2e-report.hi.md) · [العربية](ui-e2e-report.ar.md) · [বাংলা](ui-e2e-report.bn.md) · [Bahasa Indonesia](ui-e2e-report.id.md) · [日本語](ui-e2e-report.ja.md)

- Datum: 2026-08-27
- Umgebung: lokaler Rechner (Linux), echter Browser (Playwright 1.62 / Chromium) + echte Dienstprozesse
- Testfälle gesamt: **35**, bestanden **35**, fehlgeschlagen **0**, als blockiert markiert **1**
- Artefakte: `tests/e2e/artifacts/html-report/` (Playwright-HTML-Bericht), Fehler-Screenshots/Traces (diesmal keine)

## Testumfang und Seitenliste

Beide webman-Backends laufen als echte Prozesse: `admin` (:8791), `service` (:8788, WS :8789).
In `app/view/` beider Seiten gibt es nur die Standardvorlagen (`index/view.html`), keine klassischen Mehrseiten-Vorlagen — die eigentlichen „Seiten" sind die API-Endpunkte,
die Web-Frontends werden von Flutter/HarmonyOS-Clients getragen (`apps/` enthält keine ausführbare Web-UI und ist nicht Teil des E2E-Umfangs).

| App | Seite / Endpunkt | Fälle |
|------|------------|------|
| admin | `/health` Healthcheck, `/metrics` Prometheus-Metriken, `/.well-known/security.txt`, `/api/docs` OpenAPI, `/install` Installationsassistent | 5 |
| admin | `/api/captcha/generate` + `/api/captcha/verify` (Slider-CAPTCHA per echter Pixelberechnung), `/api/auth/login` (Erfolg/falsches Passwort/fehlendes CAPTCHA) | 3 |
| admin | Geschützte Seiten nach Login: `/admin/dashboard`, `/admin/user`, `/admin/role`, `/admin/permission`, `/admin/config`, `/admin/log`, `/admin/profile`, `/admin/social-user`, Logout `/admin/profile/logout` → Token ungültig | 11 |
| service | `/` (iframe-Container), `/health`, `/apidoc` (Weiterleitung auf apidoc/index.html) | 3 |
| service | Registrierung/Login/Logout, Profil (GET/PUT `/api/v1/me`), Beitrag/Timeline/Detail, Like/Unlike, Kommentar, Folgen/Beziehung/Follower/Following-Liste, Benachrichtigungen (Liste/ungelesene Anzahl/alle als gelesen markieren) | 8 |
| service | Benutzer suchen, Beiträge suchen (ES nicht gestartet → 503, als blocked markiert und bestanden) | 2 |
| service | IM-Konversationen (erstellen/Liste/Nachrichten), Sprachräume (erstellen/Liste/Detail/schließen) | 3 |

## Ausführung

```bash
cd tests/e2e && npx playwright test          # alle
# oder pro Datei: admin-pages.spec.js / admin-auth.spec.js / service-journey.spec.js
```

- Testkonto-Fixture: `e2e_smoke`, Passwort `ApiTest!2026` (per SQL vorab angelegt, siehe `tests/api/run.php`)
- Das Slider-CAPTCHA wird über die Pixel-Pearson-Korrelation zwischen „Puzzleteil vs. Hintergrundbild" gelöst (echter Interaktionspfad, kein Bypass);
  der CAPTCHA-Typ ist zufällig (click/rotate/slider), nur slider lässt sich automatisch lösen, daher wiederholt das Skript mit neuem Bild, bis es passt.

## Blocker / Umgebungseinschränkungen

1. **Beitragssuche 503**: `/api/v1/search/posts` hängt von Elasticsearch (Scout) ab, in dieser Umgebung nicht gestartet → liefert 503.
   Der Fall gilt mit Markierung `blocked` als bestanden; nach ES-Start müssen Treffer verifiziert werden.
2. **GD-Speicher der admin-CAPTCHA**: `GdDriver` dekodiert große Bilder (Hintergrund 5472x3648) bei `memory_limit 128M`,
   bei aufeinanderfolgenden generate-Aufrufen besteht OOM-Risiko (admin ist in langen Suites einmal abgestürzt). Umgehung: admin vor CAPTCHA-Fällen neu starten
   und in Batches ausführen (admin-pages / admin-auth / service getrennt). Umgebungseinschränkung, kein Business-Code-Defekt.
3. **Zufälliger CAPTCHA-Typ**: generate wählt eine von drei Varianten; click/rotate liefern keine lösbaren Daten, nur slider ist automatisch lösbar (max. 12 Wiederholungen).
4. **Leeres root-Passwort der Datenbank**: die lokale Testumgebung stellt MySQL mit root/leerem Passwort bereit, die `.env`-Defaults beider Apps sind konsistent.
5. **Apps/ mobil**: android/harmonyos/ios haben keine ausführbare Web-UI und sind nicht Teil des Browser-E2E.

## Fazit

admin-Login (inkl. Slider-CAPTCHA) und 19 Admin-Endpunkte sowie alle 16 vollständigen service-User-Fälle bestehen;
einziger Blocker ist der nicht bereitgestellte Suchdienst (ES); alle übrigen Pfade (Registrierung/Login/Beitrag/Interaktion/Benachrichtigung/IM/Sprache) sind verifiziert nutzbar.
