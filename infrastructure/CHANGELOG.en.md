# Changelog

**语言 / Languages:** [中文](CHANGELOG.md) · [English](CHANGELOG.en.md) · [한국어](CHANGELOG.ko.md) · [Русский](CHANGELOG.ru.md) · [Deutsch](CHANGELOG.de.md) · [Français](CHANGELOG.fr.md) · [Español](CHANGELOG.es.md) · [Português](CHANGELOG.pt.md) · [हिन्दी](CHANGELOG.hi.md) · [العربية](CHANGELOG.ar.md) · [বাংলা](CHANGELOG.bn.md) · [Bahasa Indonesia](CHANGELOG.id.md) · [日本語](CHANGELOG.ja.md)

## [1.0.6] — 2026-08-07

### Added
- `bee_cli` real implementations: `new` (project scaffolding), `generate controller/model`, `run` with `--watch` hot reload, `pack` (release build + copy to `dist/`)
- CLI unit tests for scaffolding and code generation (7 new tests)

### Fixed
- `bee_rust::init()` now gated behind the `logs` feature — reduced feature builds (e.g. `--no-default-features --features kv`) compile again
- Clippy `unnecessary_map_or` lint in `bee_kv::InMemoryKvStore::exists`
- `rustfmt.toml` removed nightly-only options that were silently ignored on stable; workspace now passes `cargo fmt --all --check`
- `bee_cli` binary `doc = false` to remove rustdoc output filename collision with `bee_rust`
- `hello` example port is now configurable via `PORT` env var

### Changed
- `bee-rust migrate` reports "not implemented" and exits non-zero (planned)
- README / README.en updated to describe actual CLI behavior

## [1.0.4] — 2026-07-29

### Added
- Security attack detection filter via `security-rust` (27 detectors)
- `SecurityFilter` with XSS, SQL injection, command injection, path traversal coverage
- `security` feature flag in `bee_rust` and `bee_router`

### Changed
- Updated README with security feature documentation
- Updated README with payment support section (WeChat Pay / Alipay)

### Fixed
- `bee_template` Tera raw identifier syntax for Rust 2024 edition

## [1.0.3] — 2026-07-29

### Added
- Initial workspace structure with 13 crates
- MVC routing with `Controller` trait and `Router`
- ORM with `QuerySet` builder and `Model` derive macro
- KV/Cache trait abstraction with Redis and Memory backends
- Session management with Memory/Redis backends
- Config management with INI/YAML/ENV support and hot-reload
- Template rendering via Tera
- Logging with tracing integration
- CLI scaffolding and code generation
- Search, Graph, Time-series engine trait stubs (drivers planned)

[1.0.4]: https://github.com/erikwang2013/bee-rust/compare/v1.0.3...v1.0.4
[1.0.3]: https://github.com/erikwang2013/bee-rust/releases/tag/v1.0.3
