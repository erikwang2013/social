<?php
// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
require __DIR__ . '/bootstrap.php';

use app\ws\ConnectionRegistry;
use PHPUnit\Framework\TestCase;

// ponytail: 不 mock Redis —— WsRedis 失败静默，本测试仅覆盖内存 Map 路径（Redis 双写在 attach 的 setex）
class WsServerTest extends TestCase
{
    protected function setUp(): void
    {
        ConnectionRegistry::reset();
    }

    public function testAttachSameUidKicksOldFd(): void
    {
        $this->assertNull(ConnectionRegistry::attach(1, 100));
        $this->assertSame(1, ConnectionRegistry::attach(2, 100)); // 返回旧 fd
        $this->assertSame(2, ConnectionRegistry::localFd(100));
        $this->assertNull(ConnectionRegistry::uidFor(1)); // 旧 fd 已被顶掉
    }

    public function testDetachClearsMapping(): void
    {
        ConnectionRegistry::attach(7, 200);
        ConnectionRegistry::detach(7);
        $this->assertNull(ConnectionRegistry::localFd(200));
        $this->assertNull(ConnectionRegistry::uidFor(7));
    }

    public function testHeartbeatKeepsMapping(): void
    {
        ConnectionRegistry::attach(3, 300);
        ConnectionRegistry::heartbeat(3, 300);
        $this->assertSame(3, ConnectionRegistry::localFd(300));
    }

    public function testNodeIdDefaultAndOverride(): void
    {
        ConnectionRegistry::setNodeId('');
        $this->assertSame(gethostname(), ConnectionRegistry::nodeId());
        ConnectionRegistry::setNodeId('node-1');
        $this->assertSame('node-1', ConnectionRegistry::nodeId());
    }
}
