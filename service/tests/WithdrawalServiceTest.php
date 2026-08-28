<?php
// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
require __DIR__ . '/bootstrap.php';

use app\common\WalletService;
use app\common\WithdrawalService;
use app\model\CurrencyTransaction;
use app\model\Wallet;
use app\model\Withdrawal;
use PHPUnit\Framework\TestCase;

class WithdrawalServiceTest extends TestCase
{
    private const UID = 99032;
    private const REF = 'test-wd-ref-0001';
    private const ACCOUNT = '{"alipay":"u@example.com"}';

    protected function setUp(): void
    {
        Withdrawal::where('user_id', self::UID)->delete();
        CurrencyTransaction::where('user_id', self::UID)->delete();
        Wallet::where('user_id', self::UID)->delete();
        WalletService::credit(self::UID, 100, 'test_seed', 'wd-test-seed-0001'); // 种子 100 币
    }

    private function apply(int $coins = 100, string $ref = self::REF): array
    {
        return WithdrawalService::apply(self::UID, 'alipay', $coins, 'CNY', self::ACCOUNT, $ref);
    }

    public function testApplyPendingAndIdempotent(): void
    {
        $first = $this->apply();
        $this->assertSame(0, $first['code']);
        $this->assertSame('withdraw.created', $first['lang_key']);
        $this->assertSame('pending', $first['data']['status']);
        $this->assertSame(100, $first['data']['coins']);
        $this->assertSame(0, $first['data']['balance']); // 扣款后余额 0

        $second = $this->apply();
        $this->assertSame(0, $second['code']);
        $this->assertSame($first['data']['id'], $second['data']['id']);
        $this->assertSame(0, $second['data']['balance']); // 未重复扣款
        $this->assertSame(1, Withdrawal::where('user_id', self::UID)->count());
        $this->assertSame(1, CurrencyTransaction::where('user_id', self::UID)->where('ref_type', 'withdraw')->count());
    }

    public function testApplyBelowMinRejected(): void
    {
        $res = $this->apply(50);
        $this->assertSame(422, $res['code']);
        $this->assertSame('withdraw.below_min', $res['lang_key']);
        $this->assertSame(0, Withdrawal::where('user_id', self::UID)->count());
        $this->assertSame(100, WalletService::balance(self::UID));
    }

    public function testApplyInsufficientRollsBack(): void
    {
        $res = $this->apply(150); // 余额 100 < 150
        $this->assertSame(422, $res['code']);
        $this->assertSame('withdraw.insufficient', $res['lang_key']);
        $this->assertSame(0, Withdrawal::where('user_id', self::UID)->count()); // 无残留单
        $this->assertSame(100, WalletService::balance(self::UID));
    }

    public function testApplyPlatformInvalid(): void
    {
        $res = WithdrawalService::apply(self::UID, 'paypal', 100, 'CNY', self::ACCOUNT, self::REF);
        $this->assertSame(400, $res['code']);
        $this->assertSame('withdraw.platform_invalid', $res['lang_key']);
    }

    public function testCancelRefundsCoins(): void
    {
        $created = $this->apply();
        $res = WithdrawalService::cancel(self::UID, (int) $created['data']['id']);
        $this->assertSame(0, $res['code']);
        $this->assertSame('cancelled', $res['data']['status']);
        $this->assertSame(100, $res['data']['balance']); // 退回后余额复原
        $this->assertSame(100, WalletService::balance(self::UID));

        $wd = Withdrawal::where('client_ref', self::REF)->first();
        $this->assertSame('cancelled', $wd->status);
        $this->assertSame('用户取消', $wd->reason);
        $this->assertSame(1, CurrencyTransaction::where('user_id', self::UID)->where('ref_type', 'withdraw_refund')->count());
    }

    public function testCancelNotOwner(): void
    {
        $created = $this->apply();
        $res = WithdrawalService::cancel(self::UID + 1, (int) $created['data']['id']);
        $this->assertSame(404, $res['code']);
        $this->assertSame('withdraw.not_found', $res['lang_key']);
        $this->assertSame('pending', Withdrawal::where('client_ref', self::REF)->first()->status);
    }

    public function testCancelAlreadyProcessedNoDoubleRefund(): void
    {
        $created = $this->apply();
        $id = (int) $created['data']['id'];
        $this->assertSame(0, WithdrawalService::cancel(self::UID, $id)['code']);

        $res = WithdrawalService::cancel(self::UID, $id); // 重复取消
        $this->assertSame(422, $res['code']);
        $this->assertSame('withdraw.already_processed', $res['lang_key']);
        $this->assertSame(100, WalletService::balance(self::UID)); // 未重复退
        $this->assertSame(1, CurrencyTransaction::where('user_id', self::UID)->where('ref_type', 'withdraw_refund')->count());
    }

    public function testListPagination(): void
    {
        WalletService::credit(self::UID, 200, 'test_seed', 'wd-test-seed-0002'); // 补足 3 次申请
        $this->apply(100, 'test-wd-ref-0002');
        $this->apply(100, 'test-wd-ref-0003');
        $data = WithdrawalService::list(self::UID);
        $this->assertSame(2, $data['total']);
        $this->assertCount(2, $data['list']);
        $this->assertSame('test-wd-ref-0003', $data['list'][0]->client_ref); // 倒序
    }
}
