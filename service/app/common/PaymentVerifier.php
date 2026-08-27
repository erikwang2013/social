<?php
// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
namespace app\common;

use Throwable;

/**
 * 支付渠道回调验签（全部本地计算，不发网络请求）。
 * 密钥来自 config('payment.*')（env 驱动），渠道未配置返回 503 channel_not_configured。
 * 成功: ['code'=>0,'transaction_id'=>...,'amount_cents'=>分,'currency'=>...]；失败: ['code'=>403|503,'lang_key'=>...]。
 */
class PaymentVerifier
{
    public static function verify(string $platform, array $payload, array $headers = [], string $rawBody = '', string $path = ''): array
    {
        return match ($platform) {
            'wechat' => self::wechat($payload, $headers, $rawBody, $path),
            'alipay' => self::alipay($payload),
            'stripe' => self::stripe($payload, $headers, $rawBody),
            default => ['code' => 422, 'message' => '支付渠道不支持', 'lang_key' => 'payment.platform_invalid'],
        };
    }

    /** 微信支付 V3：平台证书 RSA-SHA256 验签（证书序列号 + 时间戳防重放）+ APIv3 密钥 AES-256-GCM 解密 resource */
    private static function wechat(array $payload, array $headers, string $rawBody, string $path): array
    {
        $cert = (string) config('payment.wechat.platform_cert', '');
        $key = (string) config('payment.wechat.api_v3_key', '');
        if ($cert === '' || $key === '') {
            return self::unconfigured('wechat');
        }
        $sig = (string) ($headers['wechatpay-signature'] ?? '');
        $serial = strtolower((string) ($headers['wechatpay-serial'] ?? ''));
        $ts = (int) ($headers['wechatpay-timestamp'] ?? 0);
        $nonce = (string) ($headers['wechatpay-nonce'] ?? '');
        if ($sig === '' || $nonce === '' || abs(time() - $ts) > 300) {
            return self::verifyFailed();
        }
        try {
            $info = openssl_x509_parse($cert);
            $pub = openssl_pkey_get_public($cert);
        } catch (Throwable) {
            return self::verifyFailed();
        }
        if (!$pub || !is_array($info) || strtolower((string) ($info['serialNumberHex'] ?? '')) !== $serial) {
            return self::verifyFailed();
        }
        $message = "POST\n{$path}\n{$ts}\n{$nonce}\n{$rawBody}\n";
        if (openssl_verify($message, base64_decode($sig), $pub, OPENSSL_ALGO_SHA256) !== 1) {
            return self::verifyFailed();
        }
        $resource = $payload['resource'] ?? [];
        $data = json_decode(self::aes256GcmDecrypt(
            (string) ($resource['ciphertext'] ?? ''),
            $key,
            (string) ($resource['nonce'] ?? ''),
            (string) ($resource['associated_data'] ?? ''),
        ), true) ?: [];
        if (($data['trade_state'] ?? '') !== 'SUCCESS') {
            return ['code' => 422, 'message' => '支付未成功', 'lang_key' => 'payment.not_paid'];
        }
        $tradeNo = (string) ($data['transaction_id'] ?? '');
        return $tradeNo !== ''
            ? ['code' => 0, 'transaction_id' => $tradeNo, 'out_trade_no' => (string) ($data['out_trade_no'] ?? ''),
                'amount_cents' => (int) ($data['amount']['total'] ?? 0),
                'currency' => (string) ($data['amount']['currency'] ?? 'CNY')]
            : self::verifyFailed();
    }

    /** 支付宝异步通知：RSA2 验签（app_id 核对 + ksort 拼接 + 应用公钥），trade_status 须为成功态 */
    private static function alipay(array $payload): array
    {
        $pubKey = (string) config('payment.alipay.public_key', '');
        if ($pubKey === '') {
            return self::unconfigured('alipay');
        }
        $appId = (string) config('payment.alipay.app_id', '');
        if ($appId === '') {
            return self::unconfigured('alipay');
        }
        if ((string) ($payload['app_id'] ?? '') !== $appId) {
            return self::verifyFailed();
        }
        $params = $payload;
        unset($params['sign'], $params['sign_type']);
        ksort($params);
        $str = '';
        foreach ($params as $k => $v) {
            if ($v === '' || $v === null) {
                continue;
            }
            $str .= "{$k}={$v}&";
        }
        if (openssl_verify(rtrim($str, '&'), base64_decode((string) ($payload['sign'] ?? '')), $pubKey, OPENSSL_ALGO_SHA256) !== 1) {
            return self::verifyFailed();
        }
        if (!in_array((string) ($payload['trade_status'] ?? ''), ['TRADE_SUCCESS', 'TRADE_FINISHED'], true)) {
            return ['code' => 422, 'message' => '支付未成功', 'lang_key' => 'payment.not_paid'];
        }
        return ['code' => 0, 'transaction_id' => (string) ($payload['trade_no'] ?? ''),
            'out_trade_no' => (string) ($payload['out_trade_no'] ?? ''),
            'amount_cents' => (int) round((float) ($payload['total_amount'] ?? 0) * 100),
            'currency' => (string) ($payload['currency'] ?? 'CNY')];
    }

    /** Stripe webhook：Stripe-Signature 的 HMAC-SHA256 常量时间比较，事件须为支付成功态 */
    private static function stripe(array $payload, array $headers, string $rawBody): array
    {
        $secret = (string) config('payment.stripe.webhook_secret', '');
        if ($secret === '') {
            return self::unconfigured('stripe');
        }
        $ts = '';
        $v1 = '';
        foreach (explode(',', (string) ($headers['stripe-signature'] ?? '')) as $part) {
            [$k, $v] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($k === 't') {
                $ts = $v;
            }
            if ($k === 'v1') {
                $v1 = $v;
            }
        }
        if ($ts === '' || $v1 === '' || abs(time() - (int) $ts) > 300 || !hash_equals(hash_hmac('sha256', "{$ts}.{$rawBody}", $secret), $v1)) {
            return self::verifyFailed();
        }
        $type = (string) ($payload['type'] ?? '');
        if (!in_array($type, ['checkout.session.completed', 'payment_intent.succeeded'], true)) {
            return ['code' => 422, 'message' => '事件类型不支持', 'lang_key' => 'payment.not_paid'];
        }
        $object = $payload['data']['object'] ?? [];
        $tradeNo = (string) ($object['id'] ?? '');
        return $tradeNo !== ''
            ? ['code' => 0, 'transaction_id' => $tradeNo, 'out_trade_no' => (string) ($object['metadata']['out_trade_no'] ?? $object['client_reference_id'] ?? ''),
                'amount_cents' => (int) ($object['amount_total'] ?? $object['amount'] ?? 0),
                'currency' => (string) ($object['currency'] ?? 'usd')]
            : self::verifyFailed();
    }

    private static function aes256GcmDecrypt(string $ciphertext, string $key, string $nonce, string $aad): string
    {
        $tag = substr($ciphertext, -16);
        $plain = openssl_decrypt(substr($ciphertext, 0, -16), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, $aad);
        return $plain === false ? '' : $plain;
    }

    private static function unconfigured(string $platform): array
    {
        return ['code' => 503, 'message' => "{$platform} 渠道未配置", 'lang_key' => 'payment.channel_not_configured'];
    }

    private static function verifyFailed(): array
    {
        return ['code' => 403, 'message' => '验签失败', 'lang_key' => 'payment.verify_failed'];
    }
}
