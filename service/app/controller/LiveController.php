<?php
// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
namespace app\controller;

use app\common\LiveSync;
use Live\V1\CreateRoomRequest;
use Live\V1\IdRequest;
use Live\V1\ListRoomsRequest;
use support\Request;
use Webman\Http\Response;

class LiveController
{
    /** 开播：POST /api/v1/live/rooms {title} → {room_id, push_url, play_url} */
    public function create(Request $request): Response
    {
        $title = trim((string) $request->post('title', ''));
        if ($title === '' || mb_strlen($title) > 100) {
            return json(['code' => 400, 'message' => '标题需 1-100 字符', 'lang_key' => 'live.title_invalid'], 400);
        }
        $req = new CreateRoomRequest();
        $req->setUid((int) $request->uid);
        $req->setTitle($title);
        return LiveSync::respond(LiveSync::liveRpc(fn($c) => $c->CreateRoom($req)));
    }

    /** 直播中列表：GET /api/v1/live/rooms?page= */
    public function rooms(Request $request): Response
    {
        $page = max(1, (int) $request->get('page', 1));
        $req = new ListRoomsRequest();
        $req->setUid((int) $request->uid);
        $req->setLimit(20);
        $req->setOffset(($page - 1) * 20);
        return LiveSync::respond(LiveSync::liveRpc(fn($c) => $c->ListRooms($req)));
    }

    /** 详情：GET /api/v1/live/rooms/{id} */
    public function detail(Request $request, string $id): Response
    {
        $req = new IdRequest();
        $req->setUid((int) $request->uid);
        $req->setId((int) $id);
        return LiveSync::respond(LiveSync::liveRpc(fn($c) => $c->RoomDetail($req)));
    }

    /** 关播（仅房主）：POST /api/v1/live/rooms/{id}/close */
    public function close(Request $request, string $id): Response
    {
        $req = new IdRequest();
        $req->setUid((int) $request->uid);
        $req->setId((int) $id);
        return LiveSync::respond(LiveSync::liveRpc(fn($c) => $c->CloseRoom($req)));
    }

    /** 上麦：POST /api/v1/live/rooms/{id}/mic */
    public function micUp(Request $request, string $id): Response
    {
        $req = new IdRequest();
        $req->setUid((int) $request->uid);
        $req->setId((int) $id);
        return LiveSync::respond(LiveSync::liveRpc(fn($c) => $c->MicUp($req)));
    }

    /** 下麦：DELETE /api/v1/live/rooms/{id}/mic */
    public function micDown(Request $request, string $id): Response
    {
        $req = new IdRequest();
        $req->setUid((int) $request->uid);
        $req->setId((int) $id);
        return LiveSync::respond(LiveSync::liveRpc(fn($c) => $c->MicDown($req)));
    }
}
