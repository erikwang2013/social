<?php
// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
require __DIR__ . '/bootstrap.php';

use app\common\SearchSync;
use PHPUnit\Framework\TestCase;

/** 无 gRPC 服务（127.0.0.1:50051）时应静默降级，不抛异常 */
class SearchSyncTest extends TestCase
{
    public function testIndexPostSilentlyDegrades(): void
    {
        SearchSync::indexPost(1, 'hello world');
        $this->assertTrue(true); // 到达此处即未抛异常
    }

    public function testSearchPostIdsReturnsEmptyWhenUnavailable(): void
    {
        $ids = SearchSync::searchPostIds('hello', 0, 20);
        $this->assertIsArray($ids);
        $this->assertSame([], $ids);
    }

    public function testRepeatedCallsNoThrow(): void
    {
        SearchSync::indexPost(2, 'a');
        SearchSync::indexPost(3, 'b');
        $this->assertSame([], SearchSync::searchPostIds('a', 0, 10));
    }
}
