# المساهمة

**语言 / Languages:** [中文](CONTRIBUTING.md) · [English](CONTRIBUTING.en.md) · [한국어](CONTRIBUTING.ko.md) · [Русский](CONTRIBUTING.ru.md) · [Deutsch](CONTRIBUTING.de.md) · [Français](CONTRIBUTING.fr.md) · [Español](CONTRIBUTING.es.md) · [Português](CONTRIBUTING.pt.md) · [हिन्दी](CONTRIBUTING.hi.md) · [العربية](CONTRIBUTING.ar.md) · [বাংলা](CONTRIBUTING.bn.md) · [Bahasa Indonesia](CONTRIBUTING.id.md) · [日本語](CONTRIBUTING.ja.md)

## الإعداد

```bash
git clone https://github.com/erikwang2013/bee-rust.git
cd bee-rust
cargo build --workspace
cargo test --workspace
```

## قبل الإرسال

- شغّل `cargo fmt --all` لتنسيق الكود
- شغّل `cargo clippy --workspace -- -D warnings` للفحص البرمجي (lint)
- شغّل `cargo test --workspace` للتأكد من نجاح جميع الاختبارات
- حافظ على الملفات بأقل من 500 سطر

## هيكل المشروع

```
crates/
  bee_rust/         # Meta crate, re-export + feature flags
  bee_router/       # Routing + Controller + Context + Filter chain
  bee_orm/          # ORM — Model trait + QuerySet + Migration
  bee_kv/           # KV/Cache unified abstraction
  bee_search/       # Search/Analytics engine
  bee_graph/        # Graph database
  bee_tsdb/         # Time series database
  bee_config/       # Config management + hot-reload
  bee_cache/        # Cache abstraction
  bee_session/      # Session management
  bee_logs/         # Logging
  bee_template/     # Template rendering
  bee_cli/          # CLI tooling
```

## الترخيص

Apache-2.0
