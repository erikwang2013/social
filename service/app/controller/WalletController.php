<?php
// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
namespace app\controller;

use app\common\WalletService;
use app\common\WithdrawalService;
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

    /** 申请提现：POST /api/v1/wallet/withdraw {platform, coins, currency, account, client_ref} */
    public function withdraw(Request $request): Response
    {
        $res = WithdrawalService::apply(
            (int) $request->uid,
            trim((string) $request->post('platform', '')),
            (int) $request->post('coins', 0),
            trim((string) $request->post('currency', 'CNY')),
            trim((string) $request->post('account', '')),
            trim((string) $request->post('client_ref', '')),
        );
        return json($res, $res['code'] === 0 ? 200 : $res['code']);
    }

    /** 取消提现：POST /api/v1/wallet/withdraw/{id}/cancel（仅本人 pending 单） */
    public function cancelWithdrawal(Request $request, string $id): Response
    {
        $res = WithdrawalService::cancel((int) $request->uid, (int) $id);
        return json($res, $res['code'] === 0 ? 200 : $res['code']);
    }

    /** 提现列表：GET /api/v1/wallet/withdrawals?page= */
    public function withdrawals(Request $request): Response
    {
        $page = max(1, (int) $request->get('page', 1));
        $data = WithdrawalService::list((int) $request->uid, $page);
        return json(['code' => 0, 'message' => 'ok', 'lang_key' => 'ok', 'data' => $data]);
    }
}
