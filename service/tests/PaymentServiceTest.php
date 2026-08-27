<?php
// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
require __DIR__ . '/bootstrap.php';

use app\common\PaymentService;
use app\common\WalletService;
use app\model\CurrencyTransaction;
use app\model\Payment;
use app\model\Wallet;
use PHPUnit\Framework\TestCase;

class PaymentServiceTest extends TestCase
{
    private const UID = 99031;
    private const REF = 'test-pay-ref-0001';

    protected function setUp(): void
    {
        Payment::where('user_id', self::UID)->delete();
        CurrencyTransaction::where('user_id', self::UID)->delete();
        Wallet::where('user_id', self::UID)->delete();
    }

    private function createOrder(int $amountCents = 100, string $currency = 'CNY'): array
    {
        return PaymentService::createOrder(self::UID, 'wechat', $amountCents, $currency, self::REF);
    }

    /** 注入假验签器，避免依赖真实密钥；默认模拟微信回调验签通过 */
    private function fakeVerifier(array $overrides = []): callable
    {
        return fn(string $platform, array $payload, array $headers = [], string $rawBody = '', string $path = '') => array_merge([
            'code' => 0,
            'transaction_id' => 'CH-TX-001',
            'out_trade_no' => self::REF,
            'amount_cents' => 100,
            'currency' => 'CNY',
        ], $overrides);
    }

    private function sendCallback(array $payload = [], ?callable $verify = null): array
    {
        $payload += ['event' => 'test'];
        return PaymentService::handleCallback('wechat', $payload, [], json_encode($payload), '/api/v1/payment/callback/wechat', $verify ?? $this->fakeVerifier());
    }

    public function testCreateOrderPendingAndIdempotent(): void
    {
        $first = $this->createOrder();
        $this->assertSame(0, $first['code']);
        $this->assertSame('payment.order_created', $first['lang_key']);
        $this->assertSame('pending', $first['data']['status']);
        $this->assertSame(100, $first['data']['amount_cents']);
        $this->assertSame(10, $first['data']['coins']); // 定价 CNY:100 → 10

        $second = $this->createOrder();
        $this->assertSame(0, $second['code']);
        $this->assertSame('payment.order_exists', $second['lang_key']);
        $this->assertSame($first['data']['order_id'], $second['data']['order_id']);
        $this->assertSame(1, Payment::where('user_id', self::UID)->count());
    }

    public function testFieldsPersisted(): void
    {
        $this->createOrder(150);
        $order = Payment::where('client_ref', self::REF)->first();
        $this->assertNotNull($order);
        $this->assertSame(150, (int) $order->amount_cents);
        $this->assertSame('CNY', $order->currency);
        $this->assertSame(15, (int) $order->coins); // 定价 CNY:150 → 15
        $this->assertSame('wechat', $order->platform);
        $this->assertSame('pending', $order->status);
        $this->assertNull($order->trade_no);
    }

    public function testPricingMissingRejected(): void
    {
        $res = $this->createOrder(999);
        $this->assertSame(422, $res['code']);
        $this->assertSame('payment.pricing_not_found', $res['lang_key']);
        $this->assertSame(0, Payment::where('user_id', self::UID)->count());
    }

    public function testCallbackSuccessCreditsCoins(): void
    {
        $this->createOrder();
        $res = $this->sendCallback();
        $this->assertSame(0, $res['code']);
        $this->assertSame('payment.callback_verified', $res['lang_key']);
        $this->assertSame(10, $res['data']['balance']);
        $this->assertSame(10, WalletService::balance(self::UID));

        $order = Payment::where('client_ref', self::REF)->first();
        $this->assertSame('succeeded', $order->status);
        $this->assertSame('CH-TX-001', $order->trade_no);
        $this->assertNotNull($order->payload);

        $tx = CurrencyTransaction::where('user_id', self::UID)->first();
        $this->assertNotNull($tx);
        $this->assertSame('payment', $tx->ref_type);
        $this->assertSame('wechat:CH-TX-001', $tx->ref_id);
    }

    public function testDuplicateCallbackNoDoubleCredit(): void
    {
        $this->createOrder();
        $this->sendCallback();
        $res = $this->sendCallback();
        $this->assertSame(0, $res['code']);
        $this->assertSame(10, $res['data']['balance']); // 原结果，未重复加币
        $this->assertSame(1, CurrencyTransaction::where('user_id', self::UID)->count());
        $this->assertSame(10, WalletService::balance(self::UID));
    }

    public function testVerifyFailed403(): void
    {
        $this->createOrder();
        $verifier = fn() => ['code' => 403, 'message' => '验签失败', 'lang_key' => 'payment.verify_failed'];
        $res = $this->sendCallback([], $verifier);
        $this->assertSame(403, $res['code']);
        $this->assertSame('payment.verify_failed', $res['lang_key']);
        $this->assertSame(0, WalletService::balance(self::UID));
        $this->assertSame('pending', Payment::where('client_ref', self::REF)->first()->status);
    }

    public function testChannelNotConfigured(): void
    {
        // 默认真实验签器；config('payment.*') 未配置时直接 503
        $this->createOrder();
        $res = PaymentService::handleCallback('wechat', ['event' => 'test'], [], '{}', '/api/v1/payment/callback/wechat');
        $this->assertSame(503, $res['code']);
        $this->assertSame('payment.channel_not_configured', $res['lang_key']);
        $this->assertSame(0, WalletService::balance(self::UID));
    }

    public function testAmountMismatchSetsFailed(): void
    {
        $this->createOrder(100);
        $res = $this->sendCallback([], $this->fakeVerifier(['amount_cents' => 200]));
        $this->assertSame(422, $res['code']);
        $this->assertSame('payment.amount_mismatch', $res['lang_key']);
        $this->assertSame('failed', Payment::where('client_ref', self::REF)->first()->status);
        $this->assertSame(0, WalletService::balance(self::UID));
    }

    public function testCurrencyMismatchSetsFailed(): void
    {
        $this->createOrder(100, 'CNY');
        $res = $this->sendCallback([], $this->fakeVerifier(['currency' => 'USD']));
        $this->assertSame(422, $res['code']);
        $this->assertSame('payment.currency_mismatch', $res['lang_key']);
        $this->assertSame('failed', Payment::where('client_ref', self::REF)->first()->status);
        $this->assertSame(0, WalletService::balance(self::UID));
    }

    public function testUnknownTradeNo404(): void
    {
        $this->createOrder();
        $res = $this->sendCallback([], $this->fakeVerifier(['transaction_id' => 'NO-SUCH-TX-1', 'out_trade_no' => 'no-such-ref-9999']));
        $this->assertSame(404, $res['code']);
        $this->assertSame('payment.order_not_found', $res['lang_key']);
        $this->assertSame('pending', Payment::where('client_ref', self::REF)->first()->status);
    }
}
