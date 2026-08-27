<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

declare(strict_types=1);

namespace app\admin\controller;

use app\model\Product;
use support\Request;

class IapProductController
{
    private const PLATFORMS = ['apple', 'google', 'huawei'];

    public function list(Request $request)
    {
        $page = max(1, (int) $request->get('page', 1));
        $pageSize = min(100, max(1, (int) $request->get('page_size', 20)));
        $query = Product::orderBy('platform')->orderBy('sku');
        if (($platform = trim((string) $request->get('platform', ''))) !== '') {
            $query->where('platform', $platform);
        }
        $paginator = $query->paginate($pageSize, ['*'], 'page', $page);
        return json(['code' => 0, 'message' => 'ok', 'data' => [
            'list' => $paginator->items(),
            'total' => $paginator->total(),
        ]]);
    }

    public function create(Request $request)
    {
        $platform = trim((string) $request->post('platform'));
        $sku = trim((string) $request->post('sku'));
        $coins = (int) $request->post('coins');
        if (!in_array($platform, self::PLATFORMS, true) || $sku === '' || $coins < 1) {
            return json(['code' => 400, 'message' => 'platform(apple/google/huawei)、sku 必填且 coins 为正'], 400);
        }
        if (Product::where('platform', $platform)->where('sku', $sku)->exists()) {
            return json(['code' => 400, 'message' => '同平台 SKU 已存在'], 400);
        }
        $product = Product::create([
            'platform' => $platform,
            'sku' => $sku,
            'coins' => $coins,
            'status' => (int) $request->post('status', 1),
        ]);
        return json(['code' => 0, 'message' => 'ok', 'data' => $product]);
    }

    public function update(Request $request, string $id)
    {
        $product = Product::find((int) $id);
        if (!$product) {
            return json(['code' => 404, 'message' => '商品不存在'], 404);
        }
        if ($request->post('sku') !== null) {
            $sku = trim((string) $request->post('sku'));
            if ($sku === '' || Product::where('platform', $product->platform)->where('sku', $sku)->where('id', '!=', $product->id)->exists()) {
                return json(['code' => 400, 'message' => 'sku 非法或已存在'], 400);
            }
            $product->sku = $sku;
        }
        if ($request->post('coins') !== null) {
            $coins = (int) $request->post('coins');
            if ($coins < 1) {
                return json(['code' => 400, 'message' => 'coins 需为正数'], 400);
            }
            $product->coins = $coins;
        }
        $product->save();
        return json(['code' => 0, 'message' => 'ok', 'data' => $product]);
    }

    public function status(Request $request, string $id)
    {
        $product = Product::find((int) $id);
        if (!$product) {
            return json(['code' => 404, 'message' => '商品不存在'], 404);
        }
        $status = (int) $request->post('status');
        if (!in_array($status, [0, 1], true)) {
            return json(['code' => 400, 'message' => 'status 取值 0/1'], 400);
        }
        $product->status = $status;
        $product->save();
        return json(['code' => 0, 'message' => 'ok', 'data' => $product]);
    }
}
