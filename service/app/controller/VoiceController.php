<?php
// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
namespace app\controller;

use app\common\LiveSync;
use Live\V1\CreateVoiceRoomRequest;
use Live\V1\IdRequest;
use Live\V1\ListRoomsRequest;
use support\Request;
use Webman\Http\Response;

class VoiceController
{
    /** 通话历史：GET /api/v1/voice/calls?page= */
    public function calls(Request $request): Response
    {
        $page = max(1, (int) $request->get('page', 1));
        $req = new ListRoomsRequest();
        $req->setUid((int) $request->uid);
        $req->setLimit(20);
        $req->setOffset(($page - 1) * 20);
        return LiveSync::respond(LiveSync::voiceRpc(fn($c) => $c->ListCalls($req)));
    }

    /** 创建语聊房：POST /api/v1/voice/rooms {name} */
    public function createRoom(Request $request): Response
    {
        $name = trim((string) $request->post('name', ''));
        if ($name === '' || mb_strlen($name) > 100) {
            return json(['code' => 400, 'message' => '房名需 1-100 字符', 'lang_key' => 'voice.name_invalid'], 400);
        }
        $req = new CreateVoiceRoomRequest();
        $req->setUid((int) $request->uid);
        $req->setName($name);
        return LiveSync::respond(LiveSync::voiceRpc(fn($c) => $c->CreateRoom($req)));
    }

    /** 开放房间列表：GET /api/v1/voice/rooms?page= */
    public function rooms(Request $request): Response
    {
        $page = max(1, (int) $request->get('page', 1));
        $req = new ListRoomsRequest();
        $req->setUid((int) $request->uid);
        $req->setLimit(20);
        $req->setOffset(($page - 1) * 20);
        return LiveSync::respond(LiveSync::voiceRpc(fn($c) => $c->ListRooms($req)));
    }

    /** 房间详情：GET /api/v1/voice/rooms/{id} */
    public function roomDetail(Request $request, string $id): Response
    {
        $req = new IdRequest();
        $req->setUid((int) $request->uid);
        $req->setId((int) $id);
        return LiveSync::respond(LiveSync::voiceRpc(fn($c) => $c->RoomDetail($req)));
    }

    /** 房主关房：POST /api/v1/voice/rooms/{id}/close */
    public function closeRoom(Request $request, string $id): Response
    {
        $req = new IdRequest();
        $req->setUid((int) $request->uid);
        $req->setId((int) $id);
        return LiveSync::respond(LiveSync::voiceRpc(fn($c) => $c->CloseRoom($req)));
    }
}
