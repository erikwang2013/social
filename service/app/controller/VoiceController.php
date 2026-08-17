<?php
// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
namespace app\controller;

use app\model\CallRecord;
use app\model\VoiceRoom;
use app\model\VoiceRoomMember;
use app\room\RoomCenter;
use app\ws\WsRedis;
use support\Request;
use Webman\Http\Response;

class VoiceController
{
    private static ?RoomCenter $rc = null;

    private static function roomCenter(): RoomCenter
    {
        return self::$rc ??= new RoomCenter();
    }

    /** 通话历史：GET /api/v1/voice/calls?page= */
    public function calls(Request $request): Response
    {
        $page = max(1, (int) $request->get('page', 1));
        $list = CallRecord::query()
            ->where(function ($q) use ($request) {
                $q->where('caller_id', $request->uid)->orWhere('callee_id', $request->uid);
            })
            ->orderByDesc('id')
            ->forPage($page, 20)
            ->get()
            ->toArray();
        return json(['code' => 0, 'message' => 'ok', 'lang_key' => 'ok', 'data' => ['list' => $list]]);
    }

    /** 创建语聊房：POST /api/v1/voice/rooms {name} */
    public function createRoom(Request $request): Response
    {
        $name = trim((string) $request->post('name', ''));
        if ($name === '' || mb_strlen($name) > 100) {
            return json(['code' => 400, 'message' => '房名需 1-100 字符', 'lang_key' => 'voice.name_invalid'], 400);
        }
        $roomId = self::roomCenter()->create((int) $request->uid, $name);
        return json(['code' => 0, 'message' => 'ok', 'lang_key' => 'ok', 'data' => ['room_id' => $roomId]]);
    }

    /** 开放房间列表：GET /api/v1/voice/rooms?page= */
    public function rooms(Request $request): Response
    {
        $page = max(1, (int) $request->get('page', 1));
        $rooms = VoiceRoom::query()->where('status', 1)->orderByDesc('updated_at')->forPage($page, 20)->get();
        $rc = self::roomCenter();
        $list = [];
        foreach ($rooms as $room) {
            $list[] = [
                'id' => (int) $room->id,
                'owner_id' => (int) $room->owner_id,
                'name' => (string) $room->name,
                'online_count' => (int) (WsRedis::call(fn($r) => $r->scard('im:room:' . $room->id . ':online')) ?? 0),
                'mic_count' => $rc->micCount((int) $room->id),
            ];
        }
        return json(['code' => 0, 'message' => 'ok', 'lang_key' => 'ok', 'data' => ['list' => $list]]);
    }

    /** 房间详情：GET /api/v1/voice/rooms/{id} */
    public function roomDetail(Request $request, string $id): Response
    {
        $room = VoiceRoom::query()->find((int) $id);
        if ($room === null || (int) $room->status !== 1) {
            return json(['code' => 404, 'message' => '房间不存在或已关闭', 'lang_key' => 'voice.room_not_found'], 404);
        }
        $members = VoiceRoomMember::query()->where('room_id', (int) $room->id)->orderByDesc('role')->get(['user_id', 'role'])->toArray();
        return json(['code' => 0, 'message' => 'ok', 'lang_key' => 'ok', 'data' => [
            'id' => (int) $room->id,
            'owner_id' => (int) $room->owner_id,
            'name' => (string) $room->name,
            'status' => (int) $room->status,
            'members' => $members,
        ]]);
    }

    /** 房主关房：POST /api/v1/voice/rooms/{id}/close */
    public function closeRoom(Request $request, string $id): Response
    {
        try {
            self::roomCenter()->close((int) $id, (int) $request->uid);
        } catch (\RuntimeException $e) {
            return json(['code' => 403, 'message' => '仅房主可关房', 'lang_key' => 'voice.room_forbidden'], 403);
        }
        return json(['code' => 0, 'message' => 'ok', 'lang_key' => 'ok', 'data' => ['room_id' => (int) $id]]);
    }
}
