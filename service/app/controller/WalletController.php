<?php
// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
namespace app\controller;

use app\common\WalletService;
use support\Request;
use Webman\Http\Response;

class WalletController
{
    /** 余额：GET /api/v1/wallet/balance */
    public function balance(Request $request): Response
    {
        return json(['code' => 0, 'message' => 'ok', 'lang_key' => 'ok', 'data' => ['coins' => WalletService::balance((int) $request->uid)]]);
    }

    /** 流水：GET /api/v1/wallet/transactions?page= */
    public function transactions(Request $request): Response
    {
        $page = max(1, (int) $request->get('page', 1));
        $data = WalletService::transactions((int) $request->uid, $page);
        return json(['code' => 0, 'message' => 'ok', 'lang_key' => 'ok', 'data' => $data]);
    }
}
