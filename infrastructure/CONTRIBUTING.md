# Contributing

**语言 / Languages:** [中文](CONTRIBUTING.md)

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
  social_grpc/      # gRPC service entry (InfraService + live/voice bridge)
  bee_live/         # Live / voice-room / 1v1 call state machines + voice upload
```

## License

Apache-2.0