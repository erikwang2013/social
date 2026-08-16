# Social Platform

多语言社交平台 monorepo：图文社区 + IM + 直播/语音 + 虚拟经济。

| 目录 | 说明 | 技术 |
|------|------|------|
| contracts/ | gRPC 契约（proto，buf 生成入口） | protobuf / buf |
| service/ | 用户端业务服务 | webman v2 (PHP 8.3) |
| admin/ | 管理后台（open-admin 改造） | webman v2 + Flutter |
| infrastructure/ | 高吞吐计算层 | bee-rust (tonic) |
| media/ | 自建媒体层（mediasoup/SRS/coturn） | Node.js（M4/M5 启用） |
| apps/ios, apps/android, apps/harmonyos | 原生客户端 | SwiftUI / Kotlin+Compose / ArkTS |

完整设计见 `docs/superpowers/specs/2026-08-16-social-platform-design.md`。
