<?php
// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
namespace app\controller;

use app\common\IapService;
use support\Request;
use Webman\Http\Response;

class RechargeController
{
    /** 充值：POST /api/v1/iap/recharge {platform, sku, receipt} */
    public function recharge(Request $request): Response
    {
        $res = IapService::recharge(
            (int) $request->uid,
            trim((string) $request->post('platform', '')),
            trim((string) $request->post('sku', '')),
            trim((string) $request->post('receipt', '')),
        );
        return json($res, $res['code'] === 0 ? 200 : $res['code']);
    }
}
