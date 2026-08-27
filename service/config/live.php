<?php
// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
/**
 * 直播第三方管线配置（M5）。
 * 媒体面走独立 RTMP 服务器（SRS / nginx-rtmp），service 只签发地址不转发媒体流。
 */
return [
    // RTMP 推流服务器 host（不含协议前缀），如 srs.example.com
    'rtmp_host' => getenv('LIVE_RTMP_HOST') ?: '127.0.0.1',
    // HLS 播放服务器 host（不含协议前缀）
    'hls_host' => getenv('LIVE_HLS_HOST') ?: '127.0.0.1',
    // 单房间连麦/麦位上限（与 RoomCenter::MIC_LIMIT 一致）
    'mic_limit' => (int) (getenv('LIVE_MIC_LIMIT') ?: 8),
    // 弹幕 Redis List 保留条数（上限即全量，不入库）
    'danmaku_keep' => (int) (getenv('LIVE_DANMAKU_KEEP') ?: 200),
    // Rust live/voice gRPC 服务地址（M6 状态机迁移）
    'grpc_host' => getenv('GRPC_HOST') ?: '127.0.0.1:50051',
];
