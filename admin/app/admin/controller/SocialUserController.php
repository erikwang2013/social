<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

declare(strict_types=1);

namespace app\admin\controller;

use support\Request;
use app\model\SocialUser;

class SocialUserController
{
    public function list(Request $request)
    {
        $query = SocialUser::with('profile');
        if ($keyword = trim((string) $request->get('keyword'))) {
            $query->where(function ($q) use ($keyword) {
                $q->where('email', 'like', "%{$keyword}%")
                  ->orWhereHas('profile', function ($p) use ($keyword) {
                      $p->where('nickname', 'like', "%{$keyword}%");
                  });
            });
        }
        $page = max(1, (int) $request->get('page', 1));
        $pageSize = min(100, max(1, (int) $request->get('page_size', 20)));
        $paginator = $query->orderByDesc('id')->paginate($pageSize, ['*'], 'page', $page);
        return json(['code' => 0, 'message' => 'ok', 'data' => [
            'list' => $paginator->items(),
            'total' => $paginator->total(),
        ]]);
    }

    public function detail(Request $request, string $id)
    {
        $user = SocialUser::with('profile')->find((int) $id);
        if (!$user) {
            return json(['code' => 404, 'message' => '用户不存在'], 404);
        }
        return json(['code' => 0, 'message' => 'ok', 'data' => $user]);
    }

    public function status(Request $request, string $id)
    {
        $user = SocialUser::find((int) $id);
        if (!$user) {
            return json(['code' => 404, 'message' => '用户不存在'], 404);
        }
        $status = (int) $request->post('status');
        if (!in_array($status, [0, 1], true)) {
            return json(['code' => 400, 'message' => 'status 取值 0/1'], 400);
        }
        $user->status = $status;
        $user->save();
        return json(['code' => 0, 'message' => 'ok', 'data' => $user]);
    }
}
