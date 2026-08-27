<?php
// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
require __DIR__ . '/bootstrap.php';

use app\common\IapService;
use app\common\WalletService;
use app\model\CurrencyTransaction;
use app\model\Product;
use app\model\Wallet;
use PHPUnit\Framework\TestCase;

class IapServiceTest extends TestCase
{
    private const UID = 99021;
    private const SKU = 'test-iap-sku-1';

    protected function setUp(): void
    {
        CurrencyTransaction::where('user_id', self::UID)->delete();
        Wallet::where('user_id', self::UID)->delete();
        Product::where('sku', 'like', 'test-iap-%')->delete();
        Product::create(['platform' => 'apple', 'sku' => self::SKU, 'coins' => 100, 'status' => 1]);
    }

    /** 注入假校验器，避免网络依赖 */
    private function fakeVerifier(string $txId = 'TX-1'): callable
    {
        return fn(string $platform, string $sku, string $receipt) => ['code' => 0, 'transaction_id' => $txId];
    }

    public function testSkuMapsCoins(): void
    {
        $res = IapService::recharge(self::UID, 'apple', self::SKU, 'fake-receipt', $this->fakeVerifier());
        $this->assertSame(0, $res['code']);
        $this->assertSame(100, $res['data']['balance']);
        $this->assertSame(100, WalletService::balance(self::UID));

        $tx = CurrencyTransaction::where('user_id', self::UID)->first();
        $this->assertNotNull($tx);
        $this->assertSame('iap', $tx->ref_type);
        $this->assertSame('apple:test-iap-sku-1:TX-1', $tx->ref_id);
    }

    public function testReplayIdempotent(): void
    {
        IapService::recharge(self::UID, 'apple', self::SKU, 'fake-receipt', $this->fakeVerifier());
        $res = IapService::recharge(self::UID, 'apple', self::SKU, 'fake-receipt', $this->fakeVerifier());
        $this->assertSame(0, $res['code']);
        $this->assertSame(100, $res['data']['balance']); // 原结果，未重复加币
        $this->assertSame(1, CurrencyTransaction::where('user_id', self::UID)->count());
        $this->assertSame(100, WalletService::balance(self::UID));
    }

    public function testChannelNotConfigured(): void
    {
        // 默认真实校验器；config('iap.*') 未配置时直接 503，不发网络请求
        $res = IapService::recharge(self::UID, 'google', self::SKU, 'fake-receipt');
        $this->assertSame(503, $res['code']);
        $this->assertSame('iap.channel_not_configured', $res['lang_key']);
        $this->assertSame(0, WalletService::balance(self::UID));
    }

    public function testInvalidSku(): void
    {
        $res = IapService::recharge(self::UID, 'apple', 'no-such-sku', 'fake-receipt', $this->fakeVerifier());
        $this->assertSame(404, $res['code']);
        $this->assertSame('iap.product_not_found', $res['lang_key']);
    }

    public function testOffShelfRejected(): void
    {
        Product::where('sku', self::SKU)->update(['status' => 0]);
        $this->assertSame(404, IapService::recharge(self::UID, 'apple', self::SKU, 'fake-receipt', $this->fakeVerifier())['code']);
    }

    public function testInvalidPlatform(): void
    {
        $this->assertSame(422, IapService::recharge(self::UID, 'xbox', self::SKU, 'fake-receipt', $this->fakeVerifier())['code']);
    }

    public function testVerifierRejectionPropagates(): void
    {
        $verifier = fn() => ['code' => 422, 'message' => '凭证无效', 'lang_key' => 'iap.receipt_invalid'];
        $res = IapService::recharge(self::UID, 'apple', self::SKU, 'bad', $verifier);
        $this->assertSame(422, $res['code']);
        $this->assertSame('iap.receipt_invalid', $res['lang_key']);
        $this->assertSame(0, WalletService::balance(self::UID));
    }
}
