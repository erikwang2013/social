# Contributing

## Setup

```bash
git clone https://github.com/erikwang2013/bee-rust.git
cd bee-rust
cargo build --workspace
cargo test --workspace
```

## Before Submitting

- Run `cargo fmt --all` to format code
- Run `cargo clippy --workspace -- -D warnings` to lint
- Run `cargo test --workspace` to verify all tests pass
- Keep files under 500 lines

## Project Structure

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

## License

Apache-2.0
