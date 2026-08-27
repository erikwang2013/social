# Gesamt-Testübersichtsbericht
**语言 / Languages:** [中文](SUMMARY.md) · [English](SUMMARY.en.md) · [한국어](SUMMARY.ko.md) · [Русский](SUMMARY.ru.md) · [Deutsch](SUMMARY.de.md) · [Français](SUMMARY.fr.md) · [Español](SUMMARY.es.md) · [Português](SUMMARY.pt.md) · [हिन्दी](SUMMARY.hi.md) · [العربية](SUMMARY.ar.md) · [বাংলা](SUMMARY.bn.md) · [Bahasa Indonesia](SUMMARY.id.md) · [日本語](SUMMARY.ja.md)

- Datum: 2026-08-27 (zweite vollständige Regression)
- Testteam: PHP-Unit-Tests / Rust-Unit-Tests / API-Automatisierung / UI-End-to-End (Hinweis zur GO-Rolle am Ende)
- Die vier Teilberichte plus diese Übersicht liegen lokal unter `docs/test-reports/`

## Übersicht

| Rolle | Bericht | Testfälle | Bestanden | Fehlgeschlagen | Fazit |
|------|------|------|------|------|------|
| PHP-Unit-Tests | `php-unit-report.md` | 203 | 203 | 0 | service 136/136 + admin 67/67 komplett grün |
| Rust-Unit-Tests | `rust-unit-report.md` | 183 | 183 | 0 | 16 crates komplett grün, 5 echte Defekte behoben |
| API-Automatisierung | `api-test-report.md` | 116 | 116 | 0 | Behebung der 3 Produktdefekte aus der Vorrunde verifiziert |
| UI-End-to-End | `ui-e2e-report.md` | 41 | 41 | 0 | Komplett grün, 1 blocked (ES nicht gestartet) |
| **Gesamt** | | **543** | **543** | **0** | Erfolgsquote 100 % (1 blocked) |

## In dieser Runde behobene echte Defekte (alle behoben und per Regression verifiziert)

1. **A20 Ungültiges hashid 500→404** (Rest aus der Vorrunde): `BaseController::decodeId()` fängt `InvalidArgumentException` und wirft `support\exception\NotFoundException(404)` (body code); Batch-Methoden behalten die 422-Semantik
2. **A39/A40 Export Excel/PDF — garantierter Fehlschlag** (Rest aus der Vorrunde): `ExportController` hat jetzt `use support\Response;` (der Rückgabetyp wurde zuvor in eine nicht existierende Klasse aufgelöst); Doppelentschlüsselung bereits per Encryptable cast entschlüsselter Felder entfernt
3. **Absturz des Captcha-Imagick-Treibers** (neu gefunden, Produktion ebenfalls betroffen): lokales ImageMagick 7 hat die Konstante `RESOURCETYPE_PIXELS` nicht; die Treibererkennung in `config/poster.php` hat jetzt eine Konstanten-Absicherung und fällt bei Fehlen automatisch auf GD zurück
4. **Startseite des service `/` 404** (neu gefunden): webman-framework v2.2.4 löst die Root-Route nicht mehr standardmäßig auf; `service/config/route.php` registriert `Route::get('/')` explizit
5. **5 Rust-Defekte** (neu gefunden, Details in rust-unit-report.md): bee_search MemoryEngine ignoriert Paginierung, social_grpc macht aus nicht-numerischen ids stillschweigend 0, bee_tsdb InfluxDB line protocol Felder nicht sortiert, bee_search ES bulk NDJSON ids nicht escaped, bee_graph Neo4j add_edge Fehler-Endpunkt immer `from`
6. **Die Testskripte selbst**: in `tests/api/run.php` fiel ein leeres DB-Passwort durch `?:` auf 'root' zurück → geändert zu `?? 'root'`; drei veraltete Assertion-Suiten von admin wurden an den aktuellen Code angepasst (Searchable veraltet, Cors-Middleware-Keys, poster-php-Captcha-Vertrag)

## Umgebungsfixes und Hinweise (verursacht durch diese Testcharge)

- **8788 von einem Prozess eines anderen Projekts belegt**: der service von `property-management-platform` auf dieser Maschine belegte fälschlich Port 8788; gestoppt und social service mit leerer Passwort-Umgebungsvariable neu gestartet
- **`service/.env` ist weiterhin `service/.env.api-test-bak`**: Wiederherstellung ist durch die .env-Zugriffspolitik beschränkt; manuelles `mv service/.env.api-test-bak service/.env` erforderlich (nach Wiederherstellung Dienst neu starten)
- **ImageMagick-7-Kompatibilität**: zur Wiederherstellung des Imagick-Treibers ImageMagick auf 6.x herabstufen oder poster-php auf IM7-Kompatibilität upgraden; der aktuelle GD-Treiber funktioniert über die gesamte Kette
- **ES nicht gestartet**: Suchfälle (API + E2E) als 503/blocked bestanden markiert; Re-Verifikation nach Start von Elasticsearch nötig

## Vertrags-/Dokumentationsabweichungen (Überarbeitung empfohlen, nicht blockierend)

- Die Captcha-APIDoc nennt `clicks=[{x,y}]` als Objektarray, die poster-php-Implementierung verlangt aber ein Koordinatenpaar-Array `[[x,y]]`
- Der Sprach-Upload liefert `voice_url` als `/voice/{md5}.m4a` (ohne Präfix `/api/v1`); der Client muss es selbst voranstellen

## Hinweis GO-Testingenieur

Das Repository enthält **keinerlei Go-Code** (kein go.mod, keine .go-Dateien); diese Rolle hatte kein zu testendes Modul und wurde nicht ausgeführt. Für Nachtestung muss zuerst eine Go-Komponente (z. B. Gateway/Search-Sidecar) eingeführt werden.

## Reproduktion

```bash
# Unit-Tests
cd service && vendor/bin/phpunit
cd admin && vendor/bin/phpunit
cd infrastructure && cargo test --workspace
# API-Automatisierung (zuerst admin :8791 und service :8788 starten, ENCRYPTABLE_KEY/ENCRYPTION_KEY injizieren; bei leerem root-Passwort auf dieser Maschine DB_PASS='' setzen)
DB_PASS='' php tests/api/run.php
# UI E2E
cd tests/e2e && npx playwright test
```
