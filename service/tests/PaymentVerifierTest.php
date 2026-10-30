<?php
// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz

use app\common\PaymentVerifier;
use PHPUnit\Framework\TestCase;

final class PaymentVerifierTest extends TestCase
{
    protected function setUp(): void
    {
        Webman\Config::clear();
    }

    public function testAlipayValidSignatureReturnsSuccess(): void
    {
        [$privateKey, $publicKey] = $this->rsaKeyPair();
        set_payment_config(['alipay' => ['app_id' => '2026000000000001', 'public_key' => $publicKey]]);

        $result = PaymentVerifier::verify('alipay', $this->signedAlipayPayload($privateKey));

        $this->assertSame(0, $result['code']);
        $this->assertSame('2026103022001234567890', $result['transaction_id']);
        $this->assertSame('OUT-ALI-1', $result['out_trade_no']);
        $this->assertSame(999, $result['amount_cents']);
        $this->assertSame('CNY', $result['currency']);
    }

    public function testAlipayTamperedAmountFails(): void
    {
        [$privateKey, $publicKey] = $this->rsaKeyPair();
        set_payment_config(['alipay' => ['app_id' => '2026000000000001', 'public_key' => $publicKey]]);

        $payload = $this->signedAlipayPayload($privateKey);
        $payload['total_amount'] = '0.01';

        $this->assertSame(403, PaymentVerifier::verify('alipay', $payload)['code']);
    }

    public function testAlipayWrongAppIdFails(): void
    {
        [$privateKey, $publicKey] = $this->rsaKeyPair();
        set_payment_config(['alipay' => ['app_id' => '2026000000000001', 'public_key' => $publicKey]]);

        $payload = $this->signedAlipayPayload($privateKey);
        $payload['app_id'] = '2026999999999999';

        $this->assertSame(403, PaymentVerifier::verify('alipay', $payload)['code']);
    }

    public function testAlipayNonSuccessTradeStatusReturnsNotPaid(): void
    {
        [$privateKey, $publicKey] = $this->rsaKeyPair();
        set_payment_config(['alipay' => ['app_id' => '2026000000000001', 'public_key' => $publicKey]]);

        $payload = $this->signedAlipayPayload($privateKey, ['trade_status' => 'WAIT_BUYER_PAY']);
        $result = PaymentVerifier::verify('alipay', $payload);

        $this->assertSame(422, $result['code']);
        $this->assertSame('payment.not_paid', $result['lang_key']);
    }

    public function testStripeValidSignatureReturnsSuccess(): void
    {
        $secret = 'whsec_test_secret';
        set_payment_config(['stripe' => ['webhook_secret' => $secret]]);

        $payload = [
            'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'id' => 'cs_test_123',
                'amount_total' => 2500,
                'currency' => 'usd',
                'payment_status' => 'paid',
                'metadata' => ['out_trade_no' => 'OUT-STRIPE-1'],
            ]],
        ];
        $rawBody = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $ts = (string) time();
        $headers = ['stripe-signature' => "t={$ts},v1=" . hash_hmac('sha256', "{$ts}.{$rawBody}", $secret)];

        $result = PaymentVerifier::verify('stripe', $payload, $headers, $rawBody);

        $this->assertSame(0, $result['code']);
        $this->assertSame('cs_test_123', $result['transaction_id']);
        $this->assertSame('OUT-STRIPE-1', $result['out_trade_no']);
        $this->assertSame(2500, $result['amount_cents']);
        $this->assertSame('usd', $result['currency']);
    }

    public function testStripeUnpaidCheckoutReturnsNotPaid(): void
    {
        $secret = 'whsec_test_secret';
        set_payment_config(['stripe' => ['webhook_secret' => $secret]]);

        $payload = [
            'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'id' => 'cs_test_unpaid',
                'amount_total' => 2500,
                'currency' => 'usd',
                'payment_status' => 'unpaid',
                'metadata' => ['out_trade_no' => 'OUT-STRIPE-2'],
            ]],
        ];
        $rawBody = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $ts = (string) time();
        $headers = ['stripe-signature' => "t={$ts},v1=" . hash_hmac('sha256', "{$ts}.{$rawBody}", $secret)];

        $result = PaymentVerifier::verify('stripe', $payload, $headers, $rawBody);

        $this->assertSame(422, $result['code']);
        $this->assertSame('payment.not_paid', $result['lang_key']);
    }

    public function testStripePaymentIntentSucceededReturnsSuccess(): void
    {
        $secret = 'whsec_test_secret';
        set_payment_config(['stripe' => ['webhook_secret' => $secret]]);

        $payload = [
            'type' => 'payment_intent.succeeded',
            'data' => ['object' => [
                'id' => 'pi_test_123',
                'amount' => 2500,
                'currency' => 'usd',
                'status' => 'succeeded',
                'metadata' => ['out_trade_no' => 'OUT-STRIPE-3'],
            ]],
        ];
        $rawBody = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $ts = (string) time();
        $headers = ['stripe-signature' => "t={$ts},v1=" . hash_hmac('sha256', "{$ts}.{$rawBody}", $secret)];

        $result = PaymentVerifier::verify('stripe', $payload, $headers, $rawBody);

        $this->assertSame(0, $result['code']);
        $this->assertSame('pi_test_123', $result['transaction_id']);
        $this->assertSame('OUT-STRIPE-3', $result['out_trade_no']);
        $this->assertSame(2500, $result['amount_cents']);
    }

    public function testStripeTamperedBodyFails(): void
    {
        $secret = 'whsec_test_secret';
        set_payment_config(['stripe' => ['webhook_secret' => $secret]]);

        $payload = ['type' => 'checkout.session.completed', 'data' => ['object' => ['id' => 'cs_test_123', 'amount_total' => 2500, 'payment_status' => 'paid']]];
        $rawBody = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $ts = (string) time();
        $headers = ['stripe-signature' => "t={$ts},v1=" . hash_hmac('sha256', "{$ts}.{$rawBody}", $secret)];

        $tamperedBody = str_replace('2500', '1', $rawBody);

        $this->assertSame(403, PaymentVerifier::verify('stripe', $payload, $headers, $tamperedBody)['code']);
    }

    public function testStripeTamperedTimestampFails(): void
    {
        $secret = 'whsec_test_secret';
        set_payment_config(['stripe' => ['webhook_secret' => $secret]]);

        $payload = ['type' => 'checkout.session.completed', 'data' => ['object' => ['id' => 'cs_test_123', 'amount_total' => 2500, 'payment_status' => 'paid']]];
        $rawBody = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $ts = time();
        $v1 = hash_hmac('sha256', "{$ts}.{$rawBody}", $secret);
        $headers = ['stripe-signature' => 't=' . ($ts + 1) . ",v1={$v1}"];

        $this->assertSame(403, PaymentVerifier::verify('stripe', $payload, $headers, $rawBody)['code']);
    }

    public function testWechatValidSignatureReturnsSuccess(): void
    {
        [$certPem, $privateKey, $serial] = $this->selfSignedCert();
        $apiKey = str_repeat('k', 32);
        set_payment_config(['wechat' => ['platform_cert' => $certPem, 'api_v3_key' => $apiKey, 'mchid' => '1900000109', 'appid' => 'wx_test_appid']]);

        $payload = ['resource' => $this->wechatResource([
            'trade_state' => 'SUCCESS',
            'transaction_id' => '4200001234202610301234567890',
            'out_trade_no' => 'OUT-WX-1',
            'mchid' => '1900000109',
            'appid' => 'wx_test_appid',
            'amount' => ['total' => 1234, 'currency' => 'CNY'],
        ], $apiKey)];
        $rawBody = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $path = '/api/pay/wechat/notify';
        $headers = $this->wechatHeaders($rawBody, $path, $privateKey, $serial);

        $result = PaymentVerifier::verify('wechat', $payload, $headers, $rawBody, $path);

        $this->assertSame(0, $result['code']);
        $this->assertSame('4200001234202610301234567890', $result['transaction_id']);
        $this->assertSame('OUT-WX-1', $result['out_trade_no']);
        $this->assertSame(1234, $result['amount_cents']);
        $this->assertSame('CNY', $result['currency']);
    }

    public function testWechatTamperedSignatureFails(): void
    {
        [$certPem, $privateKey, $serial] = $this->selfSignedCert();
        $apiKey = str_repeat('k', 32);
        set_payment_config(['wechat' => ['platform_cert' => $certPem, 'api_v3_key' => $apiKey, 'mchid' => '1900000109', 'appid' => 'wx_test_appid']]);

        $payload = ['resource' => $this->wechatResource([
            'trade_state' => 'SUCCESS',
            'transaction_id' => 'wx-tx-1',
            'mchid' => '1900000109',
            'appid' => 'wx_test_appid',
            'amount' => ['total' => 1, 'currency' => 'CNY'],
        ], $apiKey)];
        $rawBody = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $path = '/api/pay/wechat/notify';
        $headers = $this->wechatHeaders($rawBody, $path, $privateKey, $serial);

        $signature = base64_decode($headers['wechatpay-signature'], true);
        $signature[0] = chr(ord($signature[0]) ^ 0xff);
        $headers['wechatpay-signature'] = base64_encode($signature);

        $this->assertSame(403, PaymentVerifier::verify('wechat', $payload, $headers, $rawBody, $path)['code']);
    }

    public function testWechatWrongMchidFails(): void
    {
        [$certPem, $privateKey, $serial] = $this->selfSignedCert();
        $apiKey = str_repeat('k', 32);
        set_payment_config(['wechat' => ['platform_cert' => $certPem, 'api_v3_key' => $apiKey, 'mchid' => '1900000109', 'appid' => 'wx_test_appid']]);

        $payload = ['resource' => $this->wechatResource([
            'trade_state' => 'SUCCESS',
            'transaction_id' => 'wx-tx-mchid',
            'mchid' => '1900000999',
            'appid' => 'wx_test_appid',
            'amount' => ['total' => 1, 'currency' => 'CNY'],
        ], $apiKey)];
        $rawBody = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $path = '/api/pay/wechat/notify';
        $headers = $this->wechatHeaders($rawBody, $path, $privateKey, $serial);

        $this->assertSame(403, PaymentVerifier::verify('wechat', $payload, $headers, $rawBody, $path)['code']);
    }

    public function testWechatMissingMchidConfigReturns503(): void
    {
        [$certPem, $privateKey, $serial] = $this->selfSignedCert();
        $apiKey = str_repeat('k', 32);
        set_payment_config(['wechat' => ['platform_cert' => $certPem, 'api_v3_key' => $apiKey, 'appid' => 'wx_test_appid']]);

        $payload = ['resource' => $this->wechatResource([
            'trade_state' => 'SUCCESS',
            'transaction_id' => 'wx-tx-missing-mchid',
            'mchid' => '1900000109',
            'appid' => 'wx_test_appid',
            'amount' => ['total' => 1, 'currency' => 'CNY'],
        ], $apiKey)];
        $rawBody = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $path = '/api/pay/wechat/notify';
        $headers = $this->wechatHeaders($rawBody, $path, $privateKey, $serial);

        $this->assertSame(503, PaymentVerifier::verify('wechat', $payload, $headers, $rawBody, $path)['code']);
    }

    public function testUnconfiguredChannelReturns503(): void
    {
        $this->assertSame(503, PaymentVerifier::verify('stripe', [], [], '')['code']);
        $this->assertSame(503, PaymentVerifier::verify('alipay', [])['code']);
        $this->assertSame(503, PaymentVerifier::verify('wechat', [], [], '', '')['code']);
    }

    /** @return array{0: OpenSSLAsymmetricKey, 1: string} */
    private function rsaKeyPair(): array
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $privatePem);
        return [$key, (string) openssl_pkey_get_details($key)['key']];
    }

    private function signedAlipayPayload(OpenSSLAsymmetricKey $privateKey, array $overrides = []): array
    {
        $payload = array_replace([
            'app_id' => '2026000000000001',
            'out_trade_no' => 'OUT-ALI-1',
            'trade_no' => '2026103022001234567890',
            'trade_status' => 'TRADE_SUCCESS',
            'total_amount' => '9.99',
            'currency' => 'CNY',
        ], $overrides);

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
        openssl_sign(rtrim($str, '&'), $signature, $privateKey, OPENSSL_ALGO_SHA256);
        $payload['sign'] = base64_encode($signature);
        $payload['sign_type'] = 'RSA2';

        return $payload;
    }

    /** @return array{0: string, 1: OpenSSLAsymmetricKey, 2: string} */
    private function selfSignedCert(): array
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['commonName' => 'wechatpay-test'], $key, ['digest_alg' => 'sha256']);
        $cert = openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256']);
        openssl_x509_export($cert, $certPem);
        $info = openssl_x509_parse($certPem);
        return [$certPem, $key, strtolower((string) $info['serialNumberHex'])];
    }

    private function wechatResource(array $data, string $apiKey): array
    {
        $nonce = 'testnonce123';
        $aad = 'transaction';
        $tag = '';
        $cipher = openssl_encrypt(json_encode($data), 'aes-256-gcm', $apiKey, OPENSSL_RAW_DATA, $nonce, $tag, $aad);
        return [
            'ciphertext' => base64_encode($cipher . $tag),
            'nonce' => $nonce,
            'associated_data' => $aad,
        ];
    }

    private function wechatHeaders(string $rawBody, string $path, OpenSSLAsymmetricKey $privateKey, string $serial): array
    {
        $ts = (string) time();
        $nonce = 'header-nonce-1';
        openssl_sign("POST\n{$path}\n{$ts}\n{$nonce}\n{$rawBody}\n", $signature, $privateKey, OPENSSL_ALGO_SHA256);
        return [
            'wechatpay-timestamp' => $ts,
            'wechatpay-nonce' => $nonce,
            'wechatpay-signature' => base64_encode($signature),
            'wechatpay-serial' => $serial,
        ];
    }
}
