// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
//! M6: 直播/语音状态机 + 熔断/降级/限流（从 PHP service 移植）。
//! 状态机为纯逻辑，存储经 trait 抽象；Redis 键与广播协议与 PHP 版逐字节兼容。

pub mod http;
pub mod live;
pub mod protocol;
pub mod resilience;
pub mod store;
pub mod upload;
pub mod voice;
