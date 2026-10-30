<?php
// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
namespace app\common;

use app\model\CurrencyTransaction;
use app\model\Product;
use support\Db;
use Throwable;

class IapService
{
    private const PLATFORMS = ['apple', 'google', 'huawei'];

    /**
     * 充值：校验凭证 → products 映射币值 → 收据闸门 → WalletService::credit 幂等入账。
     * ref_id = platform:交易号（与收据闸门 receipt_key 一致），同一交易号重试返回原流水，不重复加币。
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
        // 防御性校验：校验器已按商品过滤，这里再兜底一次
        $verifiedSku = (string) ($result['product_id'] ?? '');
        if ($verifiedSku !== '' && $verifiedSku !== $sku) {
            return ['code' => 422, 'message' => '凭证无效', 'lang_key' => 'iap.receipt_invalid'];
        }

        // 全局收据闸门：键不含 sku，同一笔商店交易在任意账号下只能入账一次
        $receiptKey = "{$platform}:{$txId}";
        try {
            return Db::transaction(function () use ($uid, $platform, $sku, $txId, $receiptKey, $product) {
                $now = date('Y-m-d H:i:s');
                $inserted = Db::table('iap_receipts')->insertOrIgnore([
                    'receipt_key' => $receiptKey, 'user_id' => $uid, 'platform' => $platform,
                    'sku' => $sku, 'transaction_id' => $txId, 'created_at' => $now, 'updated_at' => $now,
                ]);
                if ($inserted === 0) {
                    $owner = Db::table('iap_receipts')->where('receipt_key', $receiptKey)->first();
                    if ($owner === null || (int) $owner->user_id !== $uid || (string) $owner->sku !== $sku) {
                        return ['code' => 409, 'message' => '凭证已被使用', 'lang_key' => 'iap.receipt_used'];
                    }
                    // 同账号同商品重试：按新/旧引用找回原流水并原样返回，不再经过 credit 入账
                    $existing = CurrencyTransaction::where('user_id', $uid)->where('ref_type', 'iap')
                        ->whereIn('ref_id', [$receiptKey, "{$platform}:{$sku}:{$txId}"])->first();
                    if ($existing === null) {
                        // 闸门行存在但流水缺失，数据不一致；按已使用拒绝，避免重复加币
                        return ['code' => 409, 'message' => '凭证已被使用', 'lang_key' => 'iap.receipt_used'];
                    }
                    return ['code' => 0, 'message' => 'ok', 'lang_key' => 'ok', 'data' => [
                        'transaction_id' => $existing->id, 'balance' => $existing->balance_after,
                    ]];
                }
                $credit = WalletService::credit($uid, (int) $product->coins, 'iap', $receiptKey, "IAP 充值 {$platform} {$sku}");
                if ($credit['code'] !== 0) {
                    throw new \RuntimeException(json_encode($credit)); // 入账失败则回滚收据闸门，允许原凭证重试
                }
                return $credit;
            });
        } catch (Throwable $e) {
            if (str_starts_with($e->getMessage(), '{')) {
                return json_decode($e->getMessage(), true);
            }
            return ['code' => 500, 'message' => '充值失败', 'lang_key' => 'iap.recharge_failed'];
        }
    }
}
