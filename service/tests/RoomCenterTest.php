<?php
// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
namespace tests;

use app\model\VoiceRoomMember;
use app\room\RoomCenter;
use app\ws\Envelope;
use app\ws\WsRedis;
use PHPUnit\Framework\TestCase;
use Illuminate\Database\Capsule\Manager as Capsule;

class RoomCenterTest extends TestCase
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
    private RoomCenter $rc;

    protected function setUp(): void
    {
        if (!self::redisOk()) {
            $this->markTestSkipped('Redis 不可用');
        }
        \app\ws\WsRedis::call(fn($r) => $r->flushdb());
        Capsule::schema()->dropIfExists('voice_room_members');
        Capsule::schema()->dropIfExists('voice_rooms');
        Capsule::schema()->create('voice_rooms', function ($t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('owner_id');
            $t->string('name', 100);
            $t->tinyInteger('status')->default(1);
            $t->timestamps();
        });
        Capsule::schema()->create('voice_room_members', function ($t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('room_id');
            $t->unsignedBigInteger('user_id');
            $t->tinyInteger('role')->default(0);
            $t->timestamps();
        });
        $this->sent = [];
        $this->rc = new RoomCenter(
            sendFn: function (int $uid, array $frame) { $this->sent[] = ['uid' => $uid, 'frame' => $frame]; },
        );
    }

    public function testJoinAndUpMic(): void
    {
        $roomId = $this->rc->create(1, '测试房');
        $this->assertSame(1, $roomId);
        $this->rc->join($roomId, 2);
        $this->assertSame(1, $this->rc->micCount($roomId));
        $this->rc->upMic($roomId, 2);
        $this->assertSame(2, $this->rc->micCount($roomId));
        $this->rc->downMic($roomId, 2);
        $this->assertSame(1, $this->rc->micCount($roomId));
    }

    public function testOwnerLeaveClosesRoom(): void
    {
        $roomId = $this->rc->create(1, '测试房');
        $this->rc->join($roomId, 2);
        $this->rc->leave($roomId, 1);
        $this->assertSame(0, $this->rc->status($roomId));
        $last = end($this->sent);
        $this->assertSame(Envelope::T_ROOM_CLOSED, $last['frame']['type']);
        // 全员（含还在房内的 uid 2）收到 room_closed
        $closedTo = array_column(array_filter($this->sent, fn($s) => $s['frame']['type'] === Envelope::T_ROOM_CLOSED), 'uid');
        $this->assertContains(2, $closedTo);
    }

    public function testMicLimitThrows(): void
    {
        $roomId = $this->rc->create(1, '测试房');
        for ($i = 2; $i <= 8; $i++) { $this->rc->join($roomId, $i); $this->rc->upMic($roomId, $i); }
        $this->rc->join($roomId, 9); // 先入房（非成员会被 member 守卫提前拦截）
        $this->expectException(\RuntimeException::class);
        $this->rc->upMic($roomId, 9);
    }

    public function testDisconnectLeavesRoom(): void
    {
        $roomId = $this->rc->create(1, '测试房');
        $this->rc->join($roomId, 2);
        $this->rc->onDisconnect(2);
        $online = WsRedis::call(fn($r) => $r->smembers('im:room:' . $roomId . ':online')) ?? [];
        $this->assertSame([1], array_map('intval', $online));
        $this->assertSame(1, VoiceRoomMember::query()->where('room_id', $roomId)->count());
        $this->assertSame([], WsRedis::call(fn($r) => $r->smembers('im:roomuser:2')) ?? []);
    }

    public function testOwnerDisconnectClosesRoom(): void
    {
        $roomId = $this->rc->create(1, '测试房');
        $this->rc->join($roomId, 2);
        $this->rc->onDisconnect(1);
        $this->assertSame(0, $this->rc->status($roomId));
        $this->assertSame([], WsRedis::call(fn($r) => $r->smembers('im:room:' . $roomId . ':online')) ?? []);
        $this->assertSame(0, VoiceRoomMember::query()->where('room_id', $roomId)->count());
    }

    public function testJoinClosedRoomThrows(): void
    {
        $roomId = $this->rc->create(1, '测试房');
        $this->rc->close($roomId, 1);
        $threw = false;
        try {
            $this->rc->join($roomId, 2);
        } catch (\RuntimeException $e) {
            $threw = $e->getMessage() === 'room_not_found';
        }
        $this->assertTrue($threw);
        // 不得留下成员行或在线集合（防 TOCTOU 复活孤儿数据）
        $this->assertSame(0, VoiceRoomMember::query()->where('room_id', $roomId)->where('user_id', 2)->count());
        $this->assertSame([], WsRedis::call(fn($r) => $r->smembers('im:room:' . $roomId . ':online')) ?? []);
    }

    public function testNonMemberCantSpoofFrames(): void
    {
        $roomId = $this->rc->create(1, '测试房');
        $this->rc->join($roomId, 2);
        $before = count($this->sent);
        $this->rc->upMic($roomId, 3);
        $this->rc->leave($roomId, 3);
        $this->assertCount($before, $this->sent); // 不广播任何帧
        $this->assertSame(0, VoiceRoomMember::query()->where('room_id', $roomId)->where('user_id', 3)->count());
    }
}
