<?php
// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
namespace app\controller;

use app\common\PaymentService;
use support\Request;
use Webman\Http\Response;

class PaymentController
{
    /** 建单：POST /api/v1/payment/order {platform, amount_cents, currency, client_ref}（需认证） */
    public function order(Request $request): Response
    {
        $res = PaymentService::createOrder(
            (int) $request->uid,
            trim((string) $request->post('platform', '')),
            (int) $request->post('amount_cents', 0),
            trim((string) $request->post('currency', 'CNY')),
            trim((string) $request->post('client_ref', '')),
        );
        return json($res, $res['code'] === 0 ? 200 : $res['code']);
    }

    /** 渠道回调：POST /api/v1/payment/callback/{platform}（无需认证，验签即鉴权；验签失败非 2xx 触发渠道重试） */
    public function callback(Request $request, string $platform): Response
    {
        $payload = $request->post() ?: (json_decode((string) $request->rawBody(), true) ?: []);
        $headers = [];
        foreach ((array) $request->header() as $name => $value) {
            $headers[strtolower($name)] = is_array($value) ? ($value[0] ?? '') : (string) $value;
        }
        $res = PaymentService::handleCallback(
            $platform, $payload, $headers, (string) $request->rawBody(), (string) $request->path()
        );
        return $res['code'] === 0 ? response('success') : json($res, $res['code']);
    }
}
