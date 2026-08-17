<?php
// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
namespace app\controller;

use app\model\CallRecord;
use support\Request;
use Webman\Http\Response;

class VoiceController
{
    /** 通话历史：GET /api/v1/voice/calls?page= */
    public function calls(Request $request): Response
    {
        $page = max(1, (int) $request->get('page', 1));
        $list = CallRecord::query()
            ->where(function ($q) use ($request) {
                $q->where('caller_id', $request->uid)->orWhere('callee_id', $request->uid);
            })
            ->orderByDesc('id')
            ->forPage($page, 20)
            ->get()
            ->toArray();
        return json(['code' => 0, 'message' => 'ok', 'lang_key' => 'ok', 'data' => ['list' => $list]]);
    }
}
