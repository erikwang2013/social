<?php
// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
namespace app\controller;

use app\common\GiftService;
use app\model\GiftCatalog;
use support\Request;
use Webman\Http\Response;

class GiftController
{
    /** 礼物目录：GET /api/v1/gifts */
    public function catalog(Request $request): Response
    {
        $list = GiftCatalog::where('status', 1)->orderBy('sort')->orderBy('id')->get();
        return json(['code' => 0, 'message' => 'ok', 'lang_key' => 'ok', 'data' => ['list' => $list]]);
    }

    /** 送礼：POST /api/v1/live/rooms/{id}/gift {gift_id, quantity, client_ref} */
    public function send(Request $request, string $id): Response
    {
        $giftId = (int) $request->post('gift_id', 0);
        $quantity = (int) $request->post('quantity', 1);
        $clientRef = trim((string) $request->post('client_ref', ''));
        $res = GiftService::send((int) $request->uid, (int) $id, $giftId, $quantity, $clientRef);
        return json($res, $res['code'] === 0 ? 200 : $res['code']);
    }
}
