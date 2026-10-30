<?php
// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
namespace app\common;

use GuzzleHttp\Client;
use Throwable;

/**
 * 三端 IAP 凭证校验。密钥全部来自 config('iap.*')（env 驱动），渠道未配置返回 503，不发网络请求。
 * 成功: ['code'=>0,'transaction_id'=>...,'product_id'=>...]；失败: ['code'=>422|502|503, 'lang_key'=>...]。
 */
class IapVerifier
{
    public static function verify(string $platform, string $sku, string $receipt): array
    {
        return match ($platform) {
            'apple' => self::apple($sku, $receipt),
            'google' => self::google($sku, $receipt),
            'huawei' => self::huawei($sku, $receipt),
            default => ['code' => 422, 'message' => '平台不支持', 'lang_key' => 'iap.platform_invalid'],
        };
    }

    private static function client(): Client
    {
        return new Client(['timeout' => (int) config('iap.timeout', 10)]);
    }

    /** Apple verifyReceipt：status 0 通过；21007 = 沙箱凭证投到生产，回退沙箱端点再验 */
    private static function apple(string $sku, string $receipt): array
    {
        $secret = (string) config('iap.apple.shared_secret', '');
        if ($secret === '' || $receipt === '') {
            return self::unconfigured('apple');
        }
        $body = ['receipt-data' => $receipt, 'password' => $secret];
        try {
            $resp = self::postJson('https://buy.itunes.apple.com/verifyReceipt', $body);
            if (($resp['status'] ?? null) === 21007) {
                $resp = self::postJson('https://sandbox.itunes.apple.com/verifyReceipt', $body);
            }
        } catch (Throwable $e) {
            return self::upstreamError($e);
        }
        if (($resp['status'] ?? null) !== 0) {
            return self::receiptInvalid();
        }
        $receiptData = is_array($resp['receipt'] ?? null) ? $resp['receipt'] : [];
        // 配置 bundle_id 时必须核对凭证归属，防止其它 App 的凭证混用
        $bundleId = (string) config('iap.apple.bundle_id', '');
        if ($bundleId !== '' && (string) ($receiptData['bundle_id'] ?? '') !== $bundleId) {
            return self::receiptInvalid();
        }
        // 只认本 SKU 的交易：in_app 可能混有其它商品，不能直接取末尾一条
        $tx = '';
        foreach ((array) ($receiptData['in_app'] ?? []) as $item) {
            if (is_array($item) && (string) ($item['product_id'] ?? '') === $sku) {
                $tx = (string) ($item['transaction_id'] ?? '');
            }
        }
        return $tx !== '' ? ['code' => 0, 'transaction_id' => $tx, 'product_id' => $sku] : self::receiptInvalid();
    }

    /** Google purchases.products：JWT 换 access token 后查询；purchaseState 0=已购买 */
    private static function google(string $sku, string $receipt): array
    {
        $package = (string) config('iap.google.package', '');
        $email = (string) config('iap.google.service_account_email', '');
        $key = (string) config('iap.google.private_key', '');
        if ($package === '' || $email === '' || $key === '' || $receipt === '') {
            return self::unconfigured('google');
        }
        try {
            $url = "https://androidpurchases.googleapis.com/androidpublisher/v3/applications/{$package}/purchases/products/{$sku}/tokens/{$receipt}";
            $resp = self::client()->get($url, ['headers' => ['Authorization' => 'Bearer ' . self::googleAccessToken($email, $key)]]);
            $json = json_decode((string) $resp->getBody(), true) ?: [];
            if (($json['purchaseState'] ?? null) !== 0) {
                return self::receiptInvalid();
            }
            if (isset($json['productId']) && (string) $json['productId'] !== $sku) {
                return self::receiptInvalid();
            }
            return ['code' => 0, 'transaction_id' => (string) ($json['orderId'] ?? $receipt), 'product_id' => $sku];
        } catch (Throwable $e) {
            return self::upstreamError($e);
        }
    }

    /** 华为 IAP 订单验证：应用级 access token 换 Basic(APPAT:token)，校验 purchaseTokenData 中的商品与支付状态 */
    private static function huawei(string $sku, string $receipt): array
    {
        $appId = (string) config('iap.huawei.app_id', '');
        $secret = (string) config('iap.huawei.app_secret', '');
        if ($appId === '' || $secret === '' || $receipt === '') {
            return self::unconfigured('huawei');
        }
        $endpoint = rtrim((string) config('iap.huawei.endpoint', 'https://orders-dre.iap.hicloud.com'), '/');
        try {
            $resp = self::client()->post($endpoint . '/applications/purchases/tokens/verify', [
                'headers' => [
                    'Authorization' => 'Basic ' . base64_encode('APPAT:' . self::huaweiAccessToken($appId, $secret)),
                    'Content-Type' => 'application/json',
                ],
                'json' => ['purchaseToken' => $receipt, 'productId' => $sku],
            ]);
            $json = json_decode((string) $resp->getBody(), true) ?: [];
            if ((string) ($json['responseCode'] ?? '') !== '0') {
                return self::receiptInvalid();
            }
            $rawData = (string) ($json['purchaseTokenData'] ?? '');
            $publicKey = (string) config('iap.huawei.public_key', '');
            if ($publicKey !== '') {
                $signature = base64_decode((string) ($json['dataSignature'] ?? ''), true);
                if ($signature === false || openssl_verify($rawData, $signature, $publicKey, OPENSSL_ALGO_SHA256) !== 1) {
                    return self::receiptInvalid();
                }
            }
            // shortcut: 未配置 IAP_HUAWEI_PUBLIC_KEY 时仍依赖 HTTPS + productId/purchaseState；配置公钥后强制 RSA-SHA256 验签
            $data = json_decode($rawData, true) ?: [];
            if ((string) ($data['productId'] ?? '') !== $sku || (int) ($data['purchaseState'] ?? -1) !== 0) {
                return self::receiptInvalid();
            }
            return ['code' => 0, 'transaction_id' => (string) ($data['orderId'] ?? $receipt), 'product_id' => $sku];
        } catch (Throwable $e) {
            return self::upstreamError($e);
        }
    }

    /** 应用级 access token（client_credentials，静态缓存，exp 前 5 分钟刷新） */
    private static function huaweiAccessToken(string $appId, string $secret): string
    {
        static $cache = null;
        $now = time();
        if ($cache !== null && $cache['exp'] > $now + 300) {
            return $cache['token'];
        }
        $resp = self::client()->post('https://oauth-login.cloud.huawei.com/oauth2/v3/token', [
            'form_params' => ['grant_type' => 'client_credentials', 'client_id' => $appId, 'client_secret' => $secret],
        ]);
        $json = json_decode((string) $resp->getBody(), true) ?: [];
        if ((string) ($json['access_token'] ?? '') === '') {
            throw new \RuntimeException('huawei access token missing'); // 不缓存失败结果，便于下次重试
        }
        $cache = ['token' => (string) $json['access_token'], 'exp' => $now + (int) ($json['expires_in'] ?? 3600)];
        return $cache['token'];
    }

    /** 服务账号 JWT 换 Google OAuth access token（静态缓存，exp 前 5 分钟刷新） */
    private static function googleAccessToken(string $email, string $key): string
    {
        static $cache = null;
        $now = time();
        if ($cache !== null && $cache['exp'] > $now + 300) {
            return $cache['token'];
        }
        $header = self::b64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claims = self::b64url(json_encode([
            'iss' => $email, 'scope' => 'https://www.googleapis.com/auth/androidpublisher',
            'aud' => 'https://oauth2.googleapis.com/token', 'iat' => $now, 'exp' => $now + 3600,
        ]));
        openssl_sign("$header.$claims", $sig, $key, OPENSSL_ALGO_SHA256);
        $assertion = "$header.$claims." . self::b64url($sig);
        $resp = self::client()->post('https://oauth2.googleapis.com/token', [
            'form_params' => ['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $assertion],
        ]);
        $json = json_decode((string) $resp->getBody(), true) ?: [];
        $cache = ['token' => (string) ($json['access_token'] ?? ''), 'exp' => $now + (int) ($json['expires_in'] ?? 3600)];
        return $cache['token'];
    }

    private static function postJson(string $url, array $body): array
    {
        $resp = self::client()->post($url, ['json' => $body]);
        return json_decode((string) $resp->getBody(), true) ?: [];
    }

    private static function b64url(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    private static function unconfigured(string $platform): array
    {
        return ['code' => 503, 'message' => "{$platform} 渠道未配置", 'lang_key' => 'iap.channel_not_configured'];
    }

    private static function receiptInvalid(): array
    {
        return ['code' => 422, 'message' => '凭证无效', 'lang_key' => 'iap.receipt_invalid'];
    }

    private static function upstreamError(Throwable $e): array
    {
        // 商店返回 404（如 Google purchase 不存在）视为凭证无效，其余视为上游异常
        if (method_exists($e, 'getResponse') && $e->getResponse() && $e->getResponse()->getStatusCode() === 404) {
            return self::receiptInvalid();
        }
        return ['code' => 502, 'message' => '凭证校验服务异常', 'lang_key' => 'iap.verify_failed'];
    }
}
