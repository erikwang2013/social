<?php
// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
namespace app\live;

/**
 * 直播地址签发（纯函数）。媒体面走第三方 RTMP 服务器，service 只签地址。
 */
class LiveStreamService
{
    public static function signPushUrl(int $roomId): string
    {
        return 'rtmp://' . config('live.rtmp_host', '127.0.0.1') . '/live/' . $roomId;
    }

    public static function signPlayUrl(int $roomId): string
    {
        return 'http://' . config('live.hls_host', '127.0.0.1') . '/hls/' . $roomId . '.m3u8';
    }
}
