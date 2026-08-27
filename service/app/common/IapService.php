<?php
// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
namespace app\common;

use app\model\Product;

class IapService
{
    private const PLATFORMS = ['apple', 'google', 'huawei'];

    /**
     * 充值：校验凭证 → products 映射币值 → WalletService::credit 幂等入账。
     * ref_id = platform:sku:交易号，同一交易号重试返回原流水，不重复加币。
     * $verify 可注入（单测用），默认走 IapVerifier。
     */
    public static function recharge(int $uid, string $platform, string $sku, string $receipt, ?callable $verify = null): array
    {
        if (!in_array($platform, self::PLATFORMS, true)) {
            return ['code' => 422, 'message' => '平台不支持', 'lang_key' => 'iap.platform_invalid'];
        }
        if ($sku === '' || $receipt === '') {
            return ['code' => 422, 'message' => 'sku 与 receipt 必填', 'lang_key' => 'iap.receipt_required'];
        }
        $product = Product::where('platform', $platform)->where('sku', $sku)->where('status', 1)->first();
        if ($product === null) {
            return ['code' => 404, 'message' => '商品不存在', 'lang_key' => 'iap.product_not_found'];
        }

        $verify ??= [IapVerifier::class, 'verify'];
        $result = $verify($platform, $sku, $receipt);
        if (($result['code'] ?? 0) !== 0) {
            return ['code' => (int) $result['code'], 'message' => (string) ($result['message'] ?? ''), 'lang_key' => (string) ($result['lang_key'] ?? 'iap.verify_failed')];
        }

        $txId = (string) ($result['transaction_id'] ?? '');
        if ($txId === '') {
            return ['code' => 422, 'message' => '凭证无效', 'lang_key' => 'iap.receipt_invalid'];
        }
        return WalletService::credit($uid, (int) $product->coins, 'iap', "{$platform}:{$sku}:{$txId}", "IAP 充值 {$platform} {$sku}");
    }
}
