<?php
// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
namespace app\common;

use app\model\Payment;
use support\Db;
use Throwable;

class PaymentService
{
    private const PLATFORMS = ['wechat', 'alipay', 'stripe'];

    /** 建单：client_ref 幂等返回原单；coins 由 amount_cents+currency 走 config('payment.pricing') 服务端定价映射 */
    public static function createOrder(int $uid, string $platform, int $amountCents, string $currency, string $clientRef): array
    {
        if (!in_array($platform, self::PLATFORMS, true)) {
            return self::err(400, '支付渠道不支持', 'payment.platform_invalid');
        }
        if ($amountCents <= 0 || $currency === '' || !preg_match('/^[A-Za-z0-9_-]{8,64}$/', $clientRef)) {
            return self::err(400, '参数缺失或格式错误', 'payment.params_invalid');
        }
        if ($order = Payment::where('user_id', $uid)->where('client_ref', $clientRef)->first()) {
            return self::ok($order, 'payment.order_exists');
        }
        $coins = (int) (config('payment.pricing', [])[strtoupper($currency) . ':' . $amountCents] ?? 0);
        if ($coins <= 0) {
            return self::err(422, '未配置该金额定价', 'payment.pricing_not_found');
        }
        try {
            $order = Payment::create([
                'user_id' => $uid, 'platform' => $platform, 'amount_cents' => $amountCents,
                'currency' => strtoupper($currency), 'coins' => $coins, 'client_ref' => $clientRef, 'status' => 'pending',
            ]);
            return self::ok($order, 'payment.order_created');
        } catch (Throwable) {
            // 并发建单：UNIQUE client_ref 兜底，回读原单
            if ($order = Payment::where('user_id', $uid)->where('client_ref', $clientRef)->first()) {
                return self::ok($order, 'payment.order_exists');
            }
            return self::err(500, '建单失败', 'payment.order_failed');
        }
    }

    /**
     * 渠道回调：验签 → 按 (platform, trade_no) 查单 → pending 单事务置 succeeded + credit 加币。
     * 已 succeeded 重复回调幂等返回原结果；金额不一致置 failed。
     * $verify 可注入（单测用），默认 PaymentVerifier。
     */
    public static function handleCallback(string $platform, array $payload, array $headers = [], string $rawBody = '', string $path = '', ?callable $verify = null): array
    {
        if (!in_array($platform, self::PLATFORMS, true)) {
            return self::err(422, '支付渠道不支持', 'payment.platform_invalid');
        }
        $verify ??= [PaymentVerifier::class, 'verify'];
        $result = $verify($platform, $payload, $headers, $rawBody, $path);
        if (($result['code'] ?? 0) !== 0) {
            return ['code' => (int) $result['code'], 'message' => (string) ($result['message'] ?? ''), 'lang_key' => (string) ($result['lang_key'] ?? 'payment.verify_failed')];
        }
        $tradeNo = (string) ($result['transaction_id'] ?? '');
        if ($tradeNo === '') {
            return self::err(400, '回调缺少交易号', 'payment.callback_missing');
        }
        $outTradeNo = (string) ($result['out_trade_no'] ?? '');

        $outcome = 'not_found';
        $order = null;
        $credit = null;
        try {
            Db::transaction(function () use ($platform, $tradeNo, $outTradeNo, $result, $payload, &$outcome, &$order, &$credit) {
                // 先按 trade_no 查（重放幂等），查不到再按 out_trade_no=client_ref 查（首回调定位 pending 单）
                $order = Payment::where('platform', $platform)->where('trade_no', $tradeNo)->lockForUpdate()->first()
                    ?? ($outTradeNo !== '' ? Payment::where('platform', $platform)->where('client_ref', $outTradeNo)->lockForUpdate()->first() : null);
                if ($order === null) {
                    error_log("[payment] callback not_found platform={$platform} trade_no={$tradeNo} out_trade_no={$outTradeNo}");
                    $outcome = 'not_found';
                    return;
                }
                if ($order->status === 'succeeded') {
                    $outcome = 'succeeded';
                    return;
                }
                if ($order->status !== 'pending') {
                    $outcome = 'failed';
                    return;
                }
                if (strtoupper((string) ($result['currency'] ?? '')) !== strtoupper((string) $order->currency)) {
                    $order->trade_no = $tradeNo;
                    $order->status = 'failed';
                    $order->payload = json_encode($payload, JSON_UNESCAPED_UNICODE);
                    $order->save();
                    $outcome = 'currency_mismatch';
                    return;
                }
                if ((int) $result['amount_cents'] !== (int) $order->amount_cents) {
                    $order->trade_no = $tradeNo;
                    $order->status = 'failed';
                    $order->payload = json_encode($payload, JSON_UNESCAPED_UNICODE);
                    $order->save();
                    $outcome = 'amount_mismatch';
                    return;
                }
                $order->trade_no = $tradeNo;
                $order->status = 'succeeded';
                $order->payload = json_encode($payload, JSON_UNESCAPED_UNICODE);
                $credit = WalletService::credit((int) $order->user_id, (int) $order->coins, 'payment', "{$platform}:{$tradeNo}", "支付充值 {$platform} {$tradeNo}");
                if ($credit['code'] !== 0) {
                    throw new \RuntimeException(); // 回滚，订单不落 succeeded（credit 幂等，重试安全）
                }
                $order->save();
                $outcome = 'credited';
            });
        } catch (Throwable) {
            if ($credit !== null && $credit['code'] !== 0) {
                return ['code' => (int) $credit['code'], 'message' => (string) ($credit['message'] ?? ''), 'lang_key' => (string) ($credit['lang_key'] ?? 'payment.callback_failed')];
            }
            return self::err(500, '回调处理失败', 'payment.callback_failed');
        }

        return match ($outcome) {
            'succeeded' => self::ok($order, 'payment.callback_verified'),
            'failed' => self::err(422, '订单已失败', 'payment.order_failed'),
            'currency_mismatch' => self::err(422, '回调币种与订单不符', 'payment.currency_mismatch'),
            'amount_mismatch' => self::err(422, '回调金额与订单不符', 'payment.amount_mismatch'),
            'credited' => self::ok($order, 'payment.callback_verified'),
            default => self::err(404, '订单不存在', 'payment.order_not_found'),
        };
    }

    private static function ok(Payment $order, string $langKey): array
    {
        return ['code' => 0, 'message' => 'ok', 'lang_key' => $langKey, 'data' => [
            'order_id' => $order->id, 'amount_cents' => (int) $order->amount_cents,
            'currency' => (string) $order->currency, 'coins' => (int) $order->coins,
            'status' => (string) $order->status, 'trade_no' => (string) ($order->trade_no ?? ''),
            'balance' => WalletService::balance((int) $order->user_id),
        ]];
    }

    private static function err(int $code, string $message, string $langKey): array
    {
        return ['code' => $code, 'message' => $message, 'lang_key' => $langKey];
    }
}
