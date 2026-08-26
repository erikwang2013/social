# Registro de cambios

**语言 / Languages:** [中文](CHANGELOG.md) · [English](CHANGELOG.en.md) · [한국어](CHANGELOG.ko.md) · [Русский](CHANGELOG.ru.md) · [Deutsch](CHANGELOG.de.md) · [Français](CHANGELOG.fr.md) · [Español](CHANGELOG.es.md) · [Português](CHANGELOG.pt.md) · [हिन्दी](CHANGELOG.hi.md) · [العربية](CHANGELOG.ar.md) · [বাংলা](CHANGELOG.bn.md) · [Bahasa Indonesia](CHANGELOG.id.md) · [日本語](CHANGELOG.ja.md)

## [1.0.6] — 2026-08-07

### Añadido
- Implementaciones reales de `bee_cli`: `new` (andamiaje de proyectos), `generate controller/model`, `run` con recarga en caliente `--watch`, `pack` (build de release + copia a `dist/`)
- Pruebas unitarias del CLI para el andamiaje y la generación de código (7 pruebas nuevas)

### Corregido
- `bee_rust::init()` ahora está detrás de la feature `logs` — los builds reducidos (p. ej. `--no-default-features --features kv`) vuelven a compilar
- Lint `unnecessary_map_or` de Clippy en `bee_kv::InMemoryKvStore::exists`
- Se eliminaron de `rustfmt.toml` opciones solo de nightly que se ignoraban silenciosamente en stable; el workspace ahora pasa `cargo fmt --all --check`
- Binario `bee_cli` con `doc = false` para eliminar la colisión de nombre de salida de rustdoc con `bee_rust`
- El puerto del ejemplo `hello` ahora es configurable mediante la variable de entorno `PORT`

### Cambiado
- `bee-rust migrate` informa «not implemented» y sale con código distinto de cero (planificado)
- README / README.en actualizados para describir el comportamiento real del CLI

## [1.0.4] — 2026-07-29

### Añadido
- Filtro de detección de ataques de seguridad mediante `security-rust` (27 detectores)
- `SecurityFilter` con cobertura de XSS, inyección SQL, inyección de comandos y path traversal
- Feature flag `security` en `bee_rust` y `bee_router`

### Cambiado
- README actualizado con documentación de las funciones de seguridad
- README actualizado con una sección de soporte de pagos (WeChat Pay / Alipay)

### Corregido
- Sintaxis de identificadores raw de Tera en `bee_template` para la edición Rust 2024

## [1.0.3] — 2026-07-29

### Añadido
- Estructura inicial del workspace con 13 crates
- Enrutamiento MVC con el trait `Controller` y `Router`
- ORM con el builder `QuerySet` y la macro derive `Model`
- Abstracción de trait KV/Cache con backends Redis y Memory
- Gestión de sesiones con backends Memory/Redis
- Gestión de configuración con soporte de INI/YAML/ENV y recarga en caliente
- Renderizado de plantillas mediante Tera
- Registro de logs con integración de tracing
- Andamiaje CLI y generación de código
- Stubs de traits para los motores de búsqueda, grafos y series temporales (drivers planificados)

[1.0.4]: https://github.com/erikwang2013/bee-rust/compare/v1.0.3...v1.0.4
[1.0.3]: https://github.com/erikwang2013/bee-rust/releases/tag/v1.0.3
