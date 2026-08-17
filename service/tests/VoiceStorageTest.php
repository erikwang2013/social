<?php
// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
namespace tests;

use app\storage\VoiceStorage;
use PHPUnit\Framework\TestCase;

class VoiceStorageTest extends TestCase
{
    private string $tmp;
    private VoiceStorage $vs;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/vs-' . uniqid();
        mkdir($this->tmp, 0777, true);
        $this->vs = new VoiceStorage($this->tmp);
    }

    protected function tearDown(): void
    {
        @exec('rm -rf ' . escapeshellarg($this->tmp));
    }

    /** 用 ffmpeg 生成 1s 正弦波 m4a 样本 */
    private function sample(float $sec = 1.0): string
    {
        $src = $this->tmp . '/sample.m4a';
        exec('ffmpeg -y -f lavfi -i "sine=frequency=440:duration=' . $sec . '" -c:a aac -b:a 32k ' . escapeshellarg($src) . ' 2>/dev/null');
        return $src;
    }

    public function testIngestTranscodesToM4a(): void
    {
        $out = $this->vs->ingest($this->sample());
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}\.m4a$/', $out['name']);
        $this->assertFileExists($this->tmp . '/' . $out['name']);
        $this->assertSame(1, $out['duration']);
        $this->assertStringStartsWith('/voice/', $out['url']);
    }

    public function testRejectOversize(): void
    {
        $big = $this->tmp . '/big.m4a';
        file_put_contents($big, str_repeat('x', 2 * 1024 * 1024 + 1));
        $this->expectException(\RuntimeException::class);
        $this->vs->ingest($big);
    }

    public function testRejectLongDuration(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->vs->ingest($this->sample(61));
    }
}
