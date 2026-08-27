<?php
// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
namespace tests;

use app\live\LiveCenter;
use app\model\LiveRoom;
use app\ws\Envelope;
use app\ws\WsRedis;
use PHPUnit\Framework\TestCase;
use Illuminate\Database\Capsule\Manager as Capsule;

class LiveCenterTest extends TestCase
{
    private static function redisOk(): bool
    {
        try {
            \app\ws\WsRedis::call(fn($r) => $r->ping());
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private array $sent = [];
    private LiveCenter $lc;

    protected function setUp(): void
    {
        if (!self::redisOk()) {
            $this->markTestSkipped('Redis 不可用');
        }
        \app\ws\WsRedis::call(fn($r) => $r->flushdb());
        Capsule::schema()->dropIfExists('live_rooms');
        Capsule::schema()->create('live_rooms', function ($t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('owner_id');
            $t->string('title', 100);
            $t->tinyInteger('status')->default(1);
            $t->string('push_url', 255)->default('');
            $t->string('play_url', 255)->default('');
            $t->timestamp('started_at')->nullable();
            $t->timestamp('ended_at')->nullable();
            $t->timestamps();
        });
        $this->sent = [];
        $this->lc = new LiveCenter(
            sendFn: function (int $uid, array $frame) { $this->sent[] = ['uid' => $uid, 'frame' => $frame]; },
        );
    }

    public function testCreateSignsUrlsAndJoinsOwner(): void
    {
        $roomId = $this->lc->create(1, '首播');
        $room = LiveRoom::query()->find($roomId);
        $this->assertSame(1, $roomId);
        $this->assertStringContainsString('/live/' . $roomId, (string) $room->push_url);
        $this->assertStringContainsString('/hls/' . $roomId . '.m3u8', (string) $room->play_url);
        $this->assertSame(1, $this->lc->onlineCount($roomId));
        // join 只加在线集合，麦位需显式上麦
        $this->assertSame([], $this->lc->micUsers($roomId));
    }

    public function testJoinLeaveDanmakuFlow(): void
    {
        $roomId = $this->lc->create(1, '首播');
        $this->lc->join($roomId, 2);
        $this->assertSame(2, $this->lc->onlineCount($roomId));
        $this->lc->sendDanmaku($roomId, 2, '大家好');
        $danmaku = $this->lc->recentDanmaku($roomId);
        $this->assertCount(1, $danmaku);
        $this->assertSame('大家好', $danmaku[0]['content']);
        $broadcast = array_filter($this->sent, fn($s) => $s['frame']['type'] === Envelope::T_DANMAKU);
        // 弹幕广播给房内全部在线成员（房主 1 + 观众 2）
        $this->assertCount(2, $broadcast);
        $this->lc->leave($roomId, 2);
        $this->assertSame(1, $this->lc->onlineCount($roomId));
    }

    public function testMicUpDown(): void
    {
        $roomId = $this->lc->create(1, '首播');
        $this->lc->join($roomId, 2);
        $this->lc->micUp($roomId, 2);
        $this->assertSame([2], $this->lc->micUsers($roomId));
        $this->lc->micDown($roomId, 2);
        $this->assertSame([], $this->lc->micUsers($roomId));
    }

    public function testMicLimitThrows(): void
    {
        $roomId = $this->lc->create(1, '首播');
        for ($i = 2; $i <= 9; $i++) {
            $this->lc->join($roomId, $i);
            $this->lc->micUp($roomId, $i);
        }
        $this->expectException(\RuntimeException::class);
        $this->lc->micUp($roomId, 10);
    }

    public function testJoinClosedRoomThrows(): void
    {
        $roomId = $this->lc->create(1, '首播');
        $this->lc->close($roomId, 1);
        $this->expectException(\RuntimeException::class);
        $this->lc->join($roomId, 2);
    }

    public function testCloseOnlyOwner(): void
    {
        $roomId = $this->lc->create(1, '首播');
        $this->expectException(\RuntimeException::class);
        $this->lc->close($roomId, 2);
    }

    public function testCloseCleansRedisAndBroadcasts(): void
    {
        $roomId = $this->lc->create(1, '首播');
        $this->lc->join($roomId, 2);
        $this->lc->sendDanmaku($roomId, 2, 'bye');
        $this->lc->micUp($roomId, 2);
        $this->lc->close($roomId, 1);
        $this->assertSame(0, (int) LiveRoom::query()->find($roomId)->status);
        $this->assertSame([], WsRedis::call(fn($r) => $r->keys('live:room:' . $roomId . ':*')) ?? []);
        $this->assertSame([], WsRedis::call(fn($r) => $r->smembers('live:roomuser:2')) ?? []);
        $closed = array_filter($this->sent, fn($s) => $s['frame']['type'] === Envelope::T_LIVE_CLOSED);
        $closedTo = array_column($closed, 'uid');
        $this->assertContains(2, $closedTo); // 观众 2 也收到 live_closed
    }

    public function testOwnerDisconnectDoesNotCloseRoom(): void
    {
        $roomId = $this->lc->create(1, '首播');
        $this->lc->join($roomId, 2);
        $this->lc->onDisconnect(1);
        // 直播与语聊房不同：房主断线不自动关播，房间保持直播中
        $this->assertSame(1, (int) LiveRoom::query()->find($roomId)->status);
        $this->assertSame([2], array_map('intval', WsRedis::call(fn($r) => $r->smembers('live:room:' . $roomId . ':online')) ?? []));
    }

    public function testDisconnectLeavesRoom(): void
    {
        $roomId = $this->lc->create(1, '首播');
        $this->lc->join($roomId, 2);
        $this->lc->micUp($roomId, 2);
        $this->lc->onDisconnect(2);
        $this->assertSame([1], array_map('intval', WsRedis::call(fn($r) => $r->smembers('live:room:' . $roomId . ':online')) ?? []));
        $this->assertSame([], $this->lc->micUsers($roomId));
        $this->assertSame([], WsRedis::call(fn($r) => $r->smembers('live:roomuser:2')) ?? []);
    }
}
