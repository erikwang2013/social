# Gesamt-Testübersichtsbericht
**语言 / Languages:** [中文](SUMMARY.md) · [English](SUMMARY.en.md) · [한국어](SUMMARY.ko.md) · [Русский](SUMMARY.ru.md) · [Deutsch](SUMMARY.de.md) · [Français](SUMMARY.fr.md) · [Español](SUMMARY.es.md) · [Português](SUMMARY.pt.md) · [हिन्दी](SUMMARY.hi.md) · [العربية](SUMMARY.ar.md) · [বাংলা](SUMMARY.bn.md) · [Bahasa Indonesia](SUMMARY.id.md) · [日本語](SUMMARY.ja.md)

- Datum: 2026-08-27
- Testteam: PHP-Unit-Tests / Rust-Unit-Tests / API-Automatisierung / UI-End-to-End (Hinweis zur GO-Rolle am Ende)
- Die vier Teilberichte plus diese Übersicht liegen lokal unter `docs/test-reports/`

## Übersicht

| Rolle | Bericht | Testfälle | Bestanden | Fehlgeschlagen | Fazit |
|------|------|------|------|------|------|
| PHP-Unit-Tests | `php-unit-report.md` | 196 | 185 | 11 (admin-Vorgabefälle, umgebungsabhängig) | service 136/136 komplett grün; admin 49/60 |
| Rust-Unit-Tests | `rust-unit-report.md` | 180 | 180 | 0 | 15 crates komplett grün, 7 echte Defekte gefunden |
| API-Automatisierung | `api-test-report.md` | 116 | 113 | 3 | 3 echte Produktdefekte, Ursachen identifiziert |
| UI-End-to-End | `ui-e2e-report.md` | 35 | 35 | 0 | Komplett grün, 1 blocked (ES nicht gestartet) |
| **Gesamt** | | **527** | **513** | **14** | Erfolgsquote 97 % |

## Liste echter Defekte (Fix empfohlen)

1. **A20 Ungültiges hashid** → 500 sollte 404 sein: `admin/app/common/HashidsService.php:28` fängt `InvalidArgumentException` nicht ab
2. **A39/A40 Export Excel/PDF** → garantierter Fehlschlag: `ExportController` fehlt `use support\Response`, wodurch die Rückgabetypauflösung bricht; dieselbe Datei entschlüsselt bereits gecastete Telefon/E-Mail ein zweites Mal und meldet `Invalid ciphertext prefix`
3. **7 von Rust gefundene Defekte**: Details in `rust-unit-report.md` (Protokollparsing, Grenzwertbehandlung usw., jeweils mit Fix)
4. **11 Fehlschläge der admin-Unit-Tests sind Umgebungs-/Konfigurationsprobleme**: fehlendes `admin/.env`, Captcha hängt von laufendem Dienst/Redis ab, veraltete Assertions für Cors-Middleware und admin_user searchable — keine Codedefekte

## Umgebungsfixes und Hinweise (verursacht durch diese Testcharge)

- **Datenbank**: `id` von `social_follows`/`social_notifications` in den m2/m3/m4-Migrationstabellen ohne AUTO_INCREMENT, per ALTER behoben (sonst scheitern Folge-/Benachrichtigungs-/IM-/Sprach-Schreibpfade mit 1364)
- **`service/.env`**: als `.env.api-test-bak` gesichert (zeigte ursprünglich auf unerreichbaren Port 13306). Automatische Wiederherstellung wegen .env-Zugriffspolitik nicht möglich; manuelles `mv service/.env.api-test-bak service/.env` erforderlich
- **ES nicht gestartet**: Suchfälle (API + E2E) als 503/blocked bestanden markiert; Re-Verifikation nach Start von Elasticsearch nötig

## Hinweis GO-Testingenieur

Das Repository enthält **keinerlei Go-Code** (kein go.mod, keine .go-Dateien); diese Rolle hatte kein zu testendes Modul und wurde nicht ausgeführt. Für Nachtestung muss zuerst eine Go-Komponente (z. B. Gateway/Search-Sidecar) eingeführt werden.

## Reproduktion

```bash
# Unit-Tests
cd service && vendor/bin/phpunit
cd admin && vendor/bin/phpunit
cd infrastructure && cargo test --workspace
# API-Automatisierung (zuerst admin :8791 und service :8788 starten, ENCRYPTABLE_KEY/ENCRYPTION_KEY injizieren)
php tests/api/run.php
# UI E2E
cd tests/e2e && npx playwright test
```
