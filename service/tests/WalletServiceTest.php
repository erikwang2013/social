<?php
// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
require __DIR__ . '/bootstrap.php';

use app\common\WalletService;
use app\model\CurrencyTransaction;
use app\model\Wallet;
use PHPUnit\Framework\TestCase;

class WalletServiceTest extends TestCase
{
    private const UID = 99001;

    protected function setUp(): void
    {
        CurrencyTransaction::where('user_id', self::UID)->delete();
        Wallet::where('user_id', self::UID)->delete();
    }

    public function testCreditCreatesWalletAndLedger(): void
    {
        $res = WalletService::credit(self::UID, 100, 'test', 't1');
        $this->assertSame(0, $res['code']);
        $this->assertSame(100, $res['data']['balance']);
        $this->assertSame(100, WalletService::balance(self::UID));
    }

    public function testCreditIdempotentByRef(): void
    {
        WalletService::credit(self::UID, 100, 'test', 't2');
        $res = WalletService::credit(self::UID, 100, 'test', 't2');
        $this->assertSame(0, $res['code']);
        $this->assertSame(100, $res['data']['balance']);
        $this->assertSame(100, WalletService::balance(self::UID));
    }

    public function testCreditRejectsNonPositive(): void
    {
        $this->assertSame(422, WalletService::credit(self::UID, 0, 'test', 't3')['code']);
    }

    public function testDebitInsufficient(): void
    {
        WalletService::credit(self::UID, 10, 'test', 't4');
        $res = WalletService::debit(self::UID, 20, 'test', 'd1');
        $this->assertSame(422, $res['code']);
        $this->assertSame(10, WalletService::balance(self::UID));
    }

    public function testDebitSuccess(): void
    {
        WalletService::credit(self::UID, 100, 'test', 't5');
        $res = WalletService::debit(self::UID, 30, 'test', 'd2');
        $this->assertSame(0, $res['code']);
        $this->assertSame(70, $res['data']['balance']);
        $this->assertSame(70, WalletService::balance(self::UID));
    }

    public function testDebitIdempotent(): void
    {
        WalletService::credit(self::UID, 100, 'test', 't6');
        WalletService::debit(self::UID, 30, 'test', 'd3');
        $res = WalletService::debit(self::UID, 30, 'test', 'd3');
        $this->assertSame(0, $res['code']);
        $this->assertSame(70, WalletService::balance(self::UID));
    }

    public function testTransactionsPage(): void
    {
        WalletService::credit(self::UID, 50, 'test', 't7');
        WalletService::credit(self::UID, 30, 'test', 't8');
        $data = WalletService::transactions(self::UID, 1);
        $this->assertSame(2, $data['total']);
        $this->assertSame(30, $data['list'][0]->amount);
        $this->assertSame(50, $data['list'][1]->amount);
    }
}
