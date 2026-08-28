<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

declare(strict_types=1);

namespace app\admin\controller;

use app\model\Payment;
use app\model\SocialUser;
use support\Request;

class PaymentOrderController
{
    public function list(Request $request)
    {
        $page = max(1, (int) $request->get('page', 1));
        $pageSize = min(100, max(1, (int) $request->get('page_size', 20)));
        $query = Payment::orderByDesc('id');
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
        $userIds = array_unique(array_map(fn ($p) => (int) $p->user_id, $paginator->items()));
        $emails = $userIds === [] ? [] : SocialUser::whereIn('id', $userIds)->pluck('email', 'id')->all();
        foreach ($paginator->items() as $p) {
            $p->setAttribute('user_email', (string) ($emails[$p->user_id] ?? ''));
            $p->makeHidden('payload'); // 列表不回传回调原文，detail 保留（对账用）
        }
        return json(['code' => 0, 'message' => 'ok', 'data' => [
            'list' => $paginator->items(),
            'total' => $paginator->total(),
        ]]);
    }

    public function detail(Request $request, string $id)
    {
        $order = Payment::find((int) $id);
        if (!$order) {
            return json(['code' => 404, 'message' => '订单不存在'], 404);
        }
        return json(['code' => 0, 'message' => 'ok', 'data' => $order]);
    }
}
