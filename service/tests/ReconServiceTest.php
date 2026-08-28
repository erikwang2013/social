<?php
// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
require __DIR__ . '/bootstrap.php';

use app\common\ReconService;
use app\common\WalletService;
use app\model\CurrencyTransaction;
use app\model\Payment;
use app\model\Wallet;
use app\model\Withdrawal;
use PHPUnit\Framework\TestCase;

class ReconServiceTest extends TestCase
{
    // ponytail: 钱包组为全局扫描，全量套件（单进程共享 :memory:）下会看到其它套件的残留 wallet；
    // 各套件造数均一致（credit+wallet 同事务），残留不产生 mismatch；断言均为计数/单元素，与扫描顺序无关。
    // 若出现跨套件污染，改按 user_id 断言或套件隔离。
    private const UID = 99033;
    private const DATE = '2026-08-27';

    protected function setUp(): void
    {
        foreach ([self::UID, self::UID + 1] as $uid) {
            Payment::where('user_id', $uid)->delete();
            Withdrawal::where('user_id', $uid)->delete();
            CurrencyTransaction::where('user_id', $uid)->delete();
            Wallet::where('user_id', $uid)->delete();
        }
    }

    private function recon(string $date = self::DATE): array
    {
        return ReconService::run($date);
    }

    private function seedPayment(int $coins = 10, string $tradeNo = 'TX-001', string $date = self::DATE): Payment
    {
        $payment = Payment::create([
            'user_id' => self::UID, 'platform' => 'wechat', 'trade_no' => $tradeNo,
            'client_ref' => 'recon-' . uniqid(), 'amount_cents' => 100, 'currency' => 'CNY',
            'coins' => $coins, 'status' => 'succeeded',
        ]);
        $payment->created_at = $date . ' 09:00:00'; // 建单时间
        $payment->updated_at = $date . ' 09:30:00'; // 状态翻转时间（对账锚点）
        $payment->save();
        return $payment;
    }

    private function paymentCredit(int $coins, string $refId, string $date = self::DATE): void
    {
        WalletService::credit(self::UID, $coins, 'payment', $refId);
        CurrencyTransaction::where('user_id', self::UID)->where('ref_type', 'payment')->where('ref_id', $refId)
            ->update(['created_at' => $date . ' 10:00:00']);
    }

    private function seedWithdrawal(string $status, int $coins = 10, string $date = self::DATE): Withdrawal
    {
        $withdrawal = Withdrawal::create([
            'user_id' => self::UID, 'platform' => 'alipay', 'account' => '{"alipay":"u@example.com"}',
            'coins' => $coins, 'currency' => 'CNY', 'status' => $status, 'client_ref' => 'recon-wd-' . uniqid(),
        ]);
        $withdrawal->created_at = $date . ' 08:00:00';
        $withdrawal->updated_at = $date . ' 08:30:00'; // 状态翻转时间（对账锚点）
        $withdrawal->save();
        return $withdrawal;
    }

    private function refundCredit(int $coins, int $withdrawalId, string $date = self::DATE): void
    {
        WalletService::credit(self::UID, $coins, 'withdraw_refund', "withdraw:{$withdrawalId}");
        CurrencyTransaction::where('user_id', self::UID)->where('ref_type', 'withdraw_refund')->where('ref_id', "withdraw:{$withdrawalId}")
            ->update(['created_at' => $date . ' 10:00:00']);
    }

    public function testEmptyDayOk(): void
    {
        $data = $this->recon()['data'];
        $this->assertTrue($data['ok']);
        $this->assertSame(0, $data['summary']['mismatch_total']);
        foreach ($data['details'] as $list) {
            $this->assertSame([], $list);
        }
        $this->assertFalse($data['truncated']);
    }

    public function testPaymentCreditOk(): void
    {
        $this->seedPayment(10, 'TX-OK-001');
        $this->paymentCredit(10, 'wechat:TX-OK-001');
        $data = $this->recon()['data'];
        $this->assertTrue($data['ok']);
        $this->assertSame(1, $data['summary']['payments_succeeded']);
        $this->assertSame(1, $data['summary']['payments_ok']);
        $this->assertSame(0, $data['summary']['payment_credit_missing']);
    }

    public function testPaymentCreditMissing(): void
    {
        $this->seedPayment(10, 'TX-MISS-001');
        $data = $this->recon()['data'];
        $this->assertFalse($data['ok']);
        $this->assertSame(1, $data['summary']['payment_credit_missing']);
        $this->assertSame(1, $data['summary']['mismatch_total']);
        $this->assertSame('TX-MISS-001', $data['details']['payment_credit_missing'][0]['trade_no']);
    }

    public function testPaymentCreditOrphan(): void
    {
        Wallet::create(['user_id' => self::UID, 'coins' => 10]);
        CurrencyTransaction::create([
            'user_id' => self::UID, 'type' => 'recharge', 'amount' => 10, 'balance_after' => 10,
            'ref_type' => 'payment', 'ref_id' => 'wechat:TX-ORPHAN-001',
        ]);
        CurrencyTransaction::where('user_id', self::UID)->where('ref_type', 'payment')->where('ref_id', 'wechat:TX-ORPHAN-001')
            ->update(['created_at' => self::DATE . ' 10:00:00']);
        $data = $this->recon()['data'];
        $this->assertFalse($data['ok']);
        $this->assertSame(1, $data['summary']['payment_credit_orphan']);
        $this->assertSame('wechat:TX-ORPHAN-001', $data['details']['payment_credit_orphan'][0]['ref_id']);
    }

    public function testPaymentAmountMismatch(): void
    {
        $this->seedPayment(10, 'TX-AMT-001');
        $this->paymentCredit(8, 'wechat:TX-AMT-001');
        $data = $this->recon()['data'];
        $this->assertSame(1, $data['summary']['payment_amount_mismatch']);
        $this->assertSame(10, $data['details']['payment_amount_mismatch'][0]['payment_coins']);
        $this->assertSame(8, $data['details']['payment_amount_mismatch'][0]['credit_amount']);
        $this->assertSame(0, $data['summary']['payments_ok']);
    }

    public function testRefundOk(): void
    {
        $wd = $this->seedWithdrawal('cancelled');
        $this->refundCredit(10, (int) $wd->id);
        $data = $this->recon()['data'];
        $this->assertTrue($data['ok']);
        $this->assertSame(1, $data['summary']['withdrawals_cancelled']);
        $this->assertSame(1, $data['summary']['withdrawals_ok']);
    }

    public function testRefundMissing(): void
    {
        $this->seedWithdrawal('cancelled');
        $data = $this->recon()['data'];
        $this->assertSame(1, $data['summary']['refund_missing']);
        $this->assertSame(1, $data['summary']['mismatch_total']);
    }

    public function testRefundOrphan(): void
    {
        $wd = $this->seedWithdrawal('pending'); // 非 cancelled → 退回 credit 为孤儿
        $this->refundCredit(10, (int) $wd->id);
        $data = $this->recon()['data'];
        $this->assertSame(1, $data['summary']['refund_orphan']);
        $this->assertSame("withdraw:{$wd->id}", $data['details']['refund_orphan'][0]['ref_id']);
    }

    public function testWalletMismatch(): void
    {
        $this->paymentCredit(10, 'wechat:TX-W-001');
        Wallet::where('user_id', self::UID)->update(['coins' => 9]);
        $data = $this->recon()['data'];
        $this->assertSame(1, $data['summary']['wallet_mismatch']);
        $this->assertSame(9, $data['details']['wallet_mismatch'][0]['wallet_coins']);
        $this->assertSame(10, $data['details']['wallet_mismatch'][0]['ledger_sum']);
    }

    public function testWalletMissing(): void
    {
        $this->paymentCredit(10, 'wechat:TX-W-002');
        Wallet::where('user_id', self::UID)->delete();
        $data = $this->recon()['data'];
        $this->assertSame(1, $data['summary']['wallet_missing']);
        $this->assertSame(self::UID, $data['details']['wallet_missing'][0]['user_id']);
        $this->assertSame(10, $data['details']['wallet_missing'][0]['ledger_sum']);
    }

    public function testDateFiltering(): void
    {
        $this->seedPayment(10, 'TX-OLD-001', '2026-07-01');
        $this->paymentCredit(10, 'wechat:TX-OLD-001', '2026-07-01');
        $data = $this->recon()['data'];
        $this->assertTrue($data['ok']);
        $this->assertSame(0, $data['summary']['payments_succeeded']);
    }

    public function testCrossDaySucceededPayment(): void
    {
        // 8/26 建单 pending，8/27 回调置 succeeded：对账 8/27 按 updated_at 与 credit 对齐，不误报
        $payment = $this->seedPayment(10, 'TX-XDAY-001', '2026-08-26');
        $payment->updated_at = '2026-08-27 09:30:00';
        $payment->save();
        $this->paymentCredit(10, 'wechat:TX-XDAY-001', '2026-08-27');
        $data = $this->recon('2026-08-27')['data'];
        $this->assertTrue($data['ok']);
        $this->assertSame(1, $data['summary']['payments_succeeded']);
        $this->assertSame(1, $data['summary']['payments_ok']);
        $this->assertSame(0, $data['summary']['payment_credit_missing']);
        $this->assertSame(0, $data['summary']['payment_credit_orphan']);
        // 8/26 侧：payment 未计入（updated_at 不在当日），不报 missing
        $prev = $this->recon('2026-08-26')['data'];
        $this->assertTrue($prev['ok']);
        $this->assertSame(0, $prev['summary']['payments_succeeded']);
    }

    public function testInvalidDate(): void
    {
        $res = $this->recon('2026-13-01');
        $this->assertSame(422, $res['code']);
        $this->assertSame('recon.date_invalid', $res['lang_key']);
        $this->assertSame(422, $this->recon('abc')['code']);
    }

    public function testDetailsTruncatedAt100(): void
    {
        for ($i = 1; $i <= 101; $i++) {
            CurrencyTransaction::create([
                'user_id' => self::UID, 'type' => 'recharge', 'amount' => 1, 'balance_after' => $i,
                'ref_type' => 'payment', 'ref_id' => "wechat:TX-ORPHAN-{$i}",
            ]);
        }
        CurrencyTransaction::where('user_id', self::UID)->update(['created_at' => self::DATE . ' 10:00:00']);
        Wallet::create(['user_id' => self::UID, 'coins' => 101]);
        $data = $this->recon()['data'];
        $this->assertSame(101, $data['summary']['payment_credit_orphan']);
        $this->assertCount(100, $data['details']['payment_credit_orphan']);
        $this->assertTrue($data['truncated']);
    }

    public function testReportStructure(): void
    {
        $res = $this->recon();
        $this->assertSame(0, $res['code']);
        $this->assertSame('ok', $res['lang_key']);
        $data = $res['data'];
        foreach (['date', 'generated_at', 'ok', 'summary', 'details', 'truncated'] as $key) {
            $this->assertArrayHasKey($key, $data);
        }
        $this->assertSame(self::DATE, $data['date']);
        foreach (['payments_succeeded', 'payments_ok', 'payment_credit_missing', 'payment_credit_orphan',
            'payment_amount_mismatch', 'withdrawals_cancelled', 'withdrawals_ok', 'refund_missing',
            'refund_orphan', 'refund_amount_mismatch', 'wallets_checked', 'wallet_mismatch',
            'wallet_missing', 'mismatch_total'] as $key) {
            $this->assertArrayHasKey($key, $data['summary']);
        }
        foreach (['payment_credit_missing', 'payment_credit_orphan', 'payment_amount_mismatch',
            'refund_missing', 'refund_orphan', 'refund_amount_mismatch', 'wallet_mismatch', 'wallet_missing'] as $key) {
            $this->assertArrayHasKey($key, $data['details']);
        }
    }

    public function testMixedMismatchSummaryAddsUp(): void
    {
        $this->seedPayment(10, 'TX-MIX-001');                       // 支付缺入账
        $this->seedWithdrawal('cancelled');                         // 提现缺退回
        $this->seedPayment(10, 'TX-MIX-002');
        $this->paymentCredit(10, 'wechat:TX-MIX-002');
        Wallet::where('user_id', self::UID)->update(['coins' => 9]); // 余额不符
        $data = $this->recon()['data'];
        $this->assertSame(1, $data['summary']['payment_credit_missing']);
        $this->assertSame(1, $data['summary']['refund_missing']);
        $this->assertSame(1, $data['summary']['wallet_mismatch']);
        $this->assertSame(3, $data['summary']['mismatch_total']);
        $this->assertFalse($data['ok']);
    }

    public function testNonReconStatusesIgnored(): void
    {
        $this->seedPayment(10, 'TX-PEND-001');
        Payment::where('trade_no', 'TX-PEND-001')->update(['status' => 'pending']);
        $this->seedWithdrawal('succeeded');
        $this->seedWithdrawal('failed');
        $data = $this->recon()['data'];
        $this->assertSame(0, $data['summary']['payments_succeeded']);
        $this->assertSame(0, $data['summary']['withdrawals_cancelled']);
        $this->assertTrue($data['ok']);
    }

    public function testLedgerWithNegativeAmounts(): void
    {
        $this->seedPayment(10, 'TX-NEG-001');
        $this->paymentCredit(10, 'wechat:TX-NEG-001');
        CurrencyTransaction::create([
            'user_id' => self::UID, 'type' => 'withdraw', 'amount' => -3, 'balance_after' => 7,
            'ref_type' => 'withdraw', 'ref_id' => 'wd-debit-001',
        ]);
        Wallet::where('user_id', self::UID)->update(['coins' => 7]); // 10 - 3
        $data = $this->recon()['data'];
        $this->assertSame(0, $data['summary']['wallet_mismatch']);
        $this->assertSame(0, $data['summary']['wallet_missing']);
        $this->assertTrue($data['ok']);
    }

    public function testCrossUserCreditDetected(): void
    {
        // UNIQUE(ref_type, ref_id) 为全局键，credit 可落错用户 → 必须检出：A 缺入账 + B 孤儿
        $other = self::UID + 1;
        $this->seedPayment(10, 'TX-CROSS-001');
        WalletService::credit($other, 10, 'payment', 'wechat:TX-CROSS-001');
        CurrencyTransaction::where('user_id', $other)->where('ref_type', 'payment')->where('ref_id', 'wechat:TX-CROSS-001')
            ->update(['created_at' => self::DATE . ' 10:00:00']);
        $data = $this->recon()['data'];
        $this->assertFalse($data['ok']);
        $this->assertSame(1, $data['summary']['payment_credit_missing']);
        $this->assertSame(1, $data['summary']['payment_credit_orphan']);
        $this->assertSame(2, $data['summary']['mismatch_total']);
    }
}
