# Änderungsprotokoll

**语言 / Languages:** [中文](CHANGELOG.md) · [English](CHANGELOG.en.md) · [한국어](CHANGELOG.ko.md) · [Русский](CHANGELOG.ru.md) · [Deutsch](CHANGELOG.de.md) · [Français](CHANGELOG.fr.md) · [Español](CHANGELOG.es.md) · [Português](CHANGELOG.pt.md) · [हिन्दी](CHANGELOG.hi.md) · [العربية](CHANGELOG.ar.md) · [বাংলা](CHANGELOG.bn.md) · [Bahasa Indonesia](CHANGELOG.id.md) · [日本語](CHANGELOG.ja.md)

## [1.0.6] — 2026-08-07

### Hinzugefügt
- Echte Implementierungen für `bee_cli`: `new` (Projekt-Gerüst), `generate controller/model`, `run` mit `--watch`-Hot-Reload, `pack` (Release-Build + Kopie nach `dist/`)
- CLI-Unit-Tests für Gerüstbau und Codegenerierung (7 neue Tests)

### Behoben
- `bee_rust::init()` ist jetzt hinter dem `logs`-Feature verborgen — reduzierte Feature-Builds (z. B. `--no-default-features --features kv`) kompilieren wieder
- Clippy-Lint `unnecessary_map_or` in `bee_kv::InMemoryKvStore::exists`
- Aus `rustfmt.toml` wurden nur-für-Nightly-Optionen entfernt, die auf Stable still ignoriert wurden; der Workspace besteht jetzt `cargo fmt --all --check`
- `bee_cli`-Binary `doc = false`, um die Namenskollision der rustdoc-Ausgabe mit `bee_rust` zu beseitigen
- Der Port des `hello`-Beispiels ist jetzt über die Umgebungsvariable `PORT` konfigurierbar

### Geändert
- `bee-rust migrate` meldet „not implemented" und beendet sich mit Code ungleich Null (geplant)
- README / README.en aktualisiert, um das tatsächliche CLI-Verhalten zu beschreiben

## [1.0.4] — 2026-07-29

### Hinzugefügt
- Sicherheitsangriffserkennungsfilter über `security-rust` (27 Detektoren)
- `SecurityFilter` mit Abdeckung von XSS, SQL-Injection, Command-Injection und Path Traversal
- `security`-Feature-Flag in `bee_rust` und `bee_router`

### Geändert
- README mit Dokumentation der Sicherheitsfunktionen aktualisiert
- README mit Abschnitt zur Zahlungsunterstützung aktualisiert (WeChat Pay / Alipay)

### Behoben
- `bee_template`-Tera-Rohbezeichnersyntax für die Rust-2024-Edition

## [1.0.3] — 2026-07-29

### Hinzugefügt
- Anfängliche Workspace-Struktur mit 13 Crates
- MVC-Routing mit dem `Controller`-Trait und `Router`
- ORM mit `QuerySet`-Builder und `Model`-Derive-Makro
- KV/Cache-Trait-Abstraktion mit Redis- und Memory-Backends
- Sessionverwaltung mit Memory/Redis-Backends
- Konfigurationsverwaltung mit INI/YAML/ENV-Unterstützung und Hot-Reload
- Template-Rendering über Tera
- Protokollierung mit tracing-Integration
- CLI-Gerüstbau und Codegenerierung
- Trait-Stubs für Such-, Graph- und Zeitreihen-Engines (Treiber geplant)

[1.0.4]: https://github.com/erikwang2013/bee-rust/compare/v1.0.3...v1.0.4
[1.0.3]: https://github.com/erikwang2013/bee-rust/releases/tag/v1.0.3
