<?php
// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
require __DIR__ . '/bootstrap.php';

use app\process\Monitor;
use PHPUnit\Framework\TestCase;

class TestableMonitor extends Monitor
{
    public function pubGetMemoryLimit($limit): int
    {
        return $this->getMemoryLimit($limit);
    }
}

class MonitorTest extends TestCase
{
    protected function setUp(): void
    {
        Monitor::resume();
    }

    protected function tearDown(): void
    {
        Monitor::resume();
    }

    public function testMemoryLimitParsing(): void
    {
        $m = new TestableMonitor(__DIR__, ['php']);
        $this->assertSame(0, $m->pubGetMemoryLimit(0));
        $this->assertSame(0, $m->pubGetMemoryLimit(-1));
        $this->assertSame(1024, $m->pubGetMemoryLimit('1G'));
        $this->assertSame(64, $m->pubGetMemoryLimit('64M'));
        $this->assertSame(50, $m->pubGetMemoryLimit('10M')); // 下限 50MB
        // ponytail: webman 实现 (int)'0.5' = 0 → 0.5G 按 0 处理落到下限 50（小数单位缺陷，记录不改）
        $this->assertSame(50, $m->pubGetMemoryLimit('0.5G'));
    }

    public function testPauseResume(): void
    {
        $this->assertFalse(Monitor::isPaused());
        Monitor::pause();
        $this->assertTrue(Monitor::isPaused());
        Monitor::resume();
        $this->assertFalse(Monitor::isPaused());
    }

    public function testCheckAllFilesChangePausedReturnsFalse(): void
    {
        $m = new Monitor(__DIR__, ['php']);
        Monitor::pause();
        $this->assertFalse($m->checkAllFilesChange());
    }
}
