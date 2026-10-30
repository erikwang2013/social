<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

declare(strict_types=1);

namespace app\admin\controller;

use app\model\SocialUser;
use app\model\Withdrawal;
use support\Db;
use support\Request;
use Throwable;

class WithdrawalController
{
    public function list(Request $request)
    {
        $page = max(1, (int) $request->get('page', 1));
        $pageSize = min(100, max(1, (int) $request->get('page_size', 20)));
        $query = Withdrawal::orderByDesc('id');
        if (($platform = trim((string) $request->get('platform', ''))) !== '') {
            $query->where('platform', $platform);
        }
        if (($status = trim((string) $request->get('status', ''))) !== '') {
            $query->where('status', $status);
        }
        if (($userId = (int) $request->get('user_id', 0)) > 0) {
            $query->where('user_id', $userId);
        }
        $paginator = $query->paginate($pageSize, ['*'], 'page', $page);
        $userIds = array_unique(array_map(fn ($w) => (int) $w->user_id, $paginator->items()));
        $emails = $userIds === [] ? [] : SocialUser::whereIn('id', $userIds)->pluck('email', 'id')->all();
        foreach ($paginator->items() as $w) {
            $w->setAttribute('user_email', (string) ($emails[$w->user_id] ?? ''));
            $w->setAttribute('account', self::maskAccount((string) $w->account)); // 列表脱敏，detail 保留完整
        }
        return json(['code' => 0, 'message' => 'ok', 'data' => [
            'list' => $paginator->items(),
            'total' => $paginator->total(),
        ]]);
    }

    /** 账户 JSON 值脱敏：仅保留每字段尾 4 位 */
    private static function maskAccount(string $account): string
    {
        $data = json_decode($account, true);
        if (!is_array($data)) {
            return '***';
        }
        foreach ($data as $k => $v) {
            $data[$k] = strlen((string) $v) > 4 ? '***' . substr((string) $v, -4) : '***';
        }
        return json_encode($data, JSON_UNESCAPED_UNICODE);
    }

    public function detail(Request $request, string $id)
    {
        $withdrawal = Withdrawal::find((int) $id);
        if (!$withdrawal) {
            return json(['code' => 404, 'message' => '提现单不存在'], 404);
        }
        return json(['code' => 0, 'message' => 'ok', 'data' => $withdrawal]);
    }

    public function status(Request $request, string $id)
    {
        $id = (int) $id;
        if (!Withdrawal::where('id', $id)->exists()) {
            return json(['code' => 404, 'message' => '提现单不存在'], 404);
        }
        $status = trim((string) $request->post('status'));
        if (!in_array($status, ['succeeded', 'failed'], true)) {
            return json(['code' => 400, 'message' => 'status 取值 succeeded/failed'], 400);
        }
        $reason = trim((string) $request->post('reason', ''));
        if ($status === 'failed' && $reason === '') {
            return json(['code' => 400, 'message' => 'failed 必填 reason'], 400);
        }
        // 同一事务内先锁 pending 提现单（与 service 端取消的 lockForUpdate 互斥），
        // 失败单退币与状态更新一起提交，杜绝退币+打款双付
        $db = Db::connection('social');
        try {
            $affected = $db->transaction(function () use ($db, $id, $status, $reason) {
                $withdrawal = $db->table('social_withdrawals')
                    ->where('id', $id)->where('status', 'pending')->lockForUpdate()->first();
                if ($withdrawal === null) {
                    return 0;
                }
                if ($status === 'failed') {
                    $uid = (int) $withdrawal->user_id;
                    $coins = (int) $withdrawal->coins;
                    $refId = "withdraw:{$id}";
                    $refunded = $db->table('social_currency_transactions')
                        ->where('user_id', $uid)->where('ref_type', 'withdraw_refund')->where('ref_id', $refId)->exists();
                    if (!$refunded && $coins > 0) {
                        $now = date('Y-m-d H:i:s');
                        $db->table('social_wallets')->insertOrIgnore([
                            'user_id' => $uid, 'coins' => 0, 'created_at' => $now, 'updated_at' => $now,
                        ]);
                        $wallet = $db->table('social_wallets')->where('user_id', $uid)->lockForUpdate()->first();
                        $balance = (int) $wallet->coins + $coins;
                        $db->table('social_wallets')->where('user_id', $uid)->update([
                            'coins' => $balance, 'updated_at' => $now,
                        ]);
                        $db->table('social_currency_transactions')->insert([
                            'user_id' => $uid, 'type' => 'withdraw_refund', 'amount' => $coins,
                            'balance_after' => $balance, 'ref_type' => 'withdraw_refund',
                            'ref_id' => $refId, 'note' => $reason, 'created_at' => $now, 'updated_at' => $now,
                        ]);
                    }
                }
                return $db->table('social_withdrawals')->where('id', $id)->where('status', 'pending')->update([
                    'status' => $status, 'reason' => $reason, 'updated_at' => date('Y-m-d H:i:s'),
                ]);
            });
        } catch (Throwable) {
            return json(['code' => 500, 'message' => '状态更新失败'], 500);
        }
        if ($affected === 0) {
            return json(['code' => 422, 'message' => '仅 pending 状态可变更'], 422);
        }
        return json(['code' => 0, 'message' => 'ok', 'data' => Withdrawal::find($id)]);
    }
}
