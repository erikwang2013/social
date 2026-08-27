<?php
// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
use app\live\LiveStreamService;
use PHPUnit\Framework\TestCase;

class LiveStreamServiceTest extends TestCase
{
    public function testPushUrlFormat(): void
    {
        $host = config('live.rtmp_host', '127.0.0.1');
        $this->assertSame('rtmp://' . $host . '/live/42', LiveStreamService::signPushUrl(42));
    }

    public function testPlayUrlFormat(): void
    {
        $host = config('live.hls_host', '127.0.0.1');
        $this->assertSame('http://' . $host . '/hls/42.m3u8', LiveStreamService::signPlayUrl(42));
    }
}
