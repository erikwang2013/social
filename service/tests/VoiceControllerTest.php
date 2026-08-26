<?php
// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
require __DIR__ . '/bootstrap.php';

use app\controller\VoiceController;
use app\model\CallRecord;
use app\model\VoiceRoom;
use app\model\VoiceRoomMember;
use app\ws\WsRedis;
use PHPUnit\Framework\TestCase;
use support\Request;

class VoiceControllerTest extends TestCase
{
    private function request(string $method, string $path, array $post = []): Request
    {
        // get()/post() 依赖可解析的 HTTP 请求行（rawHead），需完整 buffer
        $req = new Request("$method $path HTTP/1.1\r\nHost: localhost\r\n\r\n");
        if ($post !== []) {
            $req->setPost($post);
        }
        $req->uid = 1;
        return $req;
    }

    private function body(\Webman\Http\Response $res): array
    {
        return json_decode($res->rawBody(), true);
    }

    protected function setUp(): void
    {
        try {
            WsRedis::call(fn($r) => $r->flushdb());
        } catch (\Throwable) {
        }
        // bootstrap.php 的 sqlite :memory: 全进程共享：清掉语音三表，避免
        // 其他测试文件（ModelRelationTest/RoomCenterTest）遗留数据干扰本文件的
        // "应为空/仅自己" 断言；它们对本文件的清理无感（均只断言自己创建的行）
        CallRecord::query()->delete();
        VoiceRoom::query()->delete();
        VoiceRoomMember::query()->delete();
    }

    public function testCallsEmpty(): void
    {
        $res = (new VoiceController())->calls($this->request('GET', '/api/v1/voice/calls'));
        $data = json_decode($res->rawBody(), true);
        $this->assertSame(0, $data['code']);
        $this->assertSame([], $data['data']['list']);
    }

    public function testCallsOnlyMine(): void
    {
        CallRecord::create(['caller_id' => 2, 'callee_id' => 3, 'status' => 2]);
        CallRecord::create(['caller_id' => 1, 'callee_id' => 2, 'status' => 2]);
        $res = (new VoiceController())->calls($this->request('GET', '/api/v1/voice/calls'));
        $data = json_decode($res->rawBody(), true);
        $this->assertCount(1, $data['data']['list']);
        $this->assertSame(1, (int) $data['data']['list'][0]['caller_id']);
    }

    public function testCreateRoomEmptyNameRejected(): void
    {
        $res = (new VoiceController())->createRoom($this->request('POST', '/api/v1/voice/rooms', ['name' => ' ']));
        $this->assertSame(400, $this->body($res)['code']);
    }

    public function testCreateRoomTooLongRejected(): void
    {
        $res = (new VoiceController())->createRoom($this->request('POST', '/api/v1/voice/rooms', ['name' => str_repeat('名', 101)]));
        $this->assertSame(400, $this->body($res)['code']);
    }

    public function testCreateRoomSuccess(): void
    {
        $res = (new VoiceController())->createRoom($this->request('POST', '/api/v1/voice/rooms', ['name' => '测试房']));
        $data = json_decode($res->rawBody(), true);
        $this->assertSame(0, $data['code']);
        $room = VoiceRoom::find($data['data']['room_id']);
        $this->assertNotNull($room);
        $this->assertSame(1, (int) $room->status);
        // 房主自动入房且上麦
        $this->assertSame(1, (int) VoiceRoomMember::where('room_id', $room->id)->where('user_id', 1)->value('role'));
    }

    public function testRoomsListsOpenRooms(): void
    {
        $room = VoiceRoom::create(['owner_id' => 1, 'name' => '开放房', 'status' => 1]);
        VoiceRoom::create(['owner_id' => 1, 'name' => '已关房', 'status' => 0]);
        $res = (new VoiceController())->rooms($this->request('GET', '/api/v1/voice/rooms'));
        $data = json_decode($res->rawBody(), true);
        // 同进程共享 sqlite，只断言本用例的房间是否被正确过滤；
        // 列表按 updated_at 倒序，sqlite 秒级精度下同时刻平局 → 不断言首条
        $names = array_column($data['data']['list'], 'name');
        $this->assertContains('开放房', $names);
        $this->assertNotContains('已关房', $names);
    }

    public function testRoomDetailMissing(): void
    {
        $res = (new VoiceController())->roomDetail($this->request('GET', '/api/v1/voice/rooms/999'), '999');
        $this->assertSame(404, $this->body($res)['code']);
    }

    public function testRoomDetailSuccess(): void
    {
        $room = VoiceRoom::create(['owner_id' => 1, 'name' => '详情房', 'status' => 1]);
        VoiceRoomMember::create(['room_id' => $room->id, 'user_id' => 1, 'role' => 1]);
        VoiceRoomMember::create(['room_id' => $room->id, 'user_id' => 2, 'role' => 0]);
        $res = (new VoiceController())->roomDetail($this->request('GET', "/api/v1/voice/rooms/{$room->id}"), (string) $room->id);
        $data = json_decode($res->rawBody(), true);
        $this->assertSame(0, $data['code']);
        $this->assertSame((int) $room->id, $data['data']['id']);
        // 麦位在前（role desc）
        $this->assertSame([1, 2], array_column($data['data']['members'], 'user_id'));
    }

    public function testCloseRoomForbidden(): void
    {
        $room = VoiceRoom::create(['owner_id' => 9, 'name' => '别人的房', 'status' => 1]);
        $res = (new VoiceController())->closeRoom($this->request('POST', "/api/v1/voice/rooms/{$room->id}/close"), (string) $room->id);
        $this->assertSame(403, $this->body($res)['code']);
        $this->assertSame(1, (int) VoiceRoom::find($room->id)->status);
    }

    public function testCloseRoomSuccess(): void
    {
        $room = VoiceRoom::create(['owner_id' => 1, 'name' => '我的房', 'status' => 1]);
        $res = (new VoiceController())->closeRoom($this->request('POST', "/api/v1/voice/rooms/{$room->id}/close"), (string) $room->id);
        $data = json_decode($res->rawBody(), true);
        $this->assertSame(0, $data['code']);
        $this->assertSame(0, (int) VoiceRoom::find($room->id)->status);
        $this->assertSame(0, VoiceRoomMember::where('room_id', $room->id)->count());
    }
}
