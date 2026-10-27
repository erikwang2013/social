<!-- Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz -->
# Social Rust 基础设施

本目录保留 social 产品实际使用的两个 Rust crate，作为原 bee-rust 框架的精简子集：

| Crate | 职责 |
|---|---|
| `crates/social_grpc` | gRPC 服务入口，承载 `InfraService` 探活与 live/voice 桥接 |
| `crates/bee_live` | 直播、语聊房、1v1 通话状态机；语音上传（ffmpeg → m4a → S3 兼容对象存储） |

`social_grpc` 依赖 `bee_live`。PHP 侧通过 gRPC 调用，WebSocket 仍由 PHP 承载。

## 构建与测试

```bash
cd infrastructure
cargo build --workspace
cargo test --workspace
cargo check -p social_grpc
```

`cargo metadata --no-deps` 可用于快速校验 workspace 成员。

## 说明

- 其余 bee-rust crate（router/orm/kv/search/graph/tsdb/config/session/logs/template/cli 等）已移除，不再随本仓库维护。
- 原 `wiki`、示例工程与多语言 README 已删除，避免与产品文档重复。
