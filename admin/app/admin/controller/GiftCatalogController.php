<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

declare(strict_types=1);

namespace app\admin\controller;

use app\model\GiftCatalog;
use support\Request;

class GiftCatalogController
{
    public function list(Request $request)
    {
        $page = max(1, (int) $request->get('page', 1));
        $pageSize = min(100, max(1, (int) $request->get('page_size', 20)));
        $paginator = GiftCatalog::orderBy('sort')->orderBy('id')->paginate($pageSize, ['*'], 'page', $page);
        return json(['code' => 0, 'message' => 'ok', 'data' => [
            'list' => $paginator->items(),
            'total' => $paginator->total(),
        ]]);
    }

    public function create(Request $request)
    {
        $name = trim((string) $request->post('name'));
        $coinsPrice = (int) $request->post('coins_price');
        if ($name === '' || $coinsPrice < 1) {
            return json(['code' => 400, 'message' => 'name 与 coins_price 必填且 coins_price 为正'], 400);
        }
        $gift = GiftCatalog::create([
            'name' => $name,
            'coins_price' => $coinsPrice,
            'effect_key' => trim((string) $request->post('effect_key', '')),
            'status' => (int) $request->post('status', 1),
            'sort' => (int) $request->post('sort', 0),
        ]);
        return json(['code' => 0, 'message' => 'ok', 'data' => $gift]);
    }

    public function update(Request $request, string $id)
    {
        $gift = GiftCatalog::find((int) $id);
        if (!$gift) {
            return json(['code' => 404, 'message' => '礼物不存在'], 404);
        }
        foreach (['name', 'effect_key'] as $f) {
            if ($request->post($f) !== null) {
                $gift->{$f} = trim((string) $request->post($f));
            }
        }
        foreach (['coins_price', 'status', 'sort'] as $f) {
            if ($request->post($f) !== null) {
                $gift->{$f} = (int) $request->post($f);
            }
        }
        $gift->save();
        return json(['code' => 0, 'message' => 'ok', 'data' => $gift]);
    }

    public function status(Request $request, string $id)
    {
        $gift = GiftCatalog::find((int) $id);
        if (!$gift) {
            return json(['code' => 404, 'message' => '礼物不存在'], 404);
        }
        $status = (int) $request->post('status');
        if (!in_array($status, [0, 1], true)) {
            return json(['code' => 400, 'message' => 'status 取值 0/1'], 400);
        }
        $gift->status = $status;
        $gift->save();
        return json(['code' => 0, 'message' => 'ok', 'data' => $gift]);
    }
}
