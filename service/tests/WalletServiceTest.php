<?php
// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz

use app\common\WalletService;
use PHPUnit\Framework\TestCase;

final class WalletServiceTest extends TestCase
{
    private static bool $schemaReady = false;

    protected function setUp(): void
    {
        if (!self::$schemaReady) {
            $db = new \support\Db();
            $db->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
            $db->setAsGlobal();
            $db->bootEloquent();

            $pdo = \support\Db::connection()->getPdo();
            $pdo->exec('CREATE TABLE wallets (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL UNIQUE,
                coins INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NULL,
                updated_at TEXT NULL
            )');
            $pdo->exec('CREATE TABLE currency_transactions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                type TEXT NOT NULL,
                amount INTEGER NOT NULL,
                balance_after INTEGER NOT NULL,
                ref_type TEXT NULL,
                ref_id TEXT NULL,
                note TEXT NOT NULL DEFAULT \'\',
                created_at TEXT NULL,
                updated_at TEXT NULL,
                UNIQUE (user_id, ref_type, ref_id)
            )');

            self::$schemaReady = true;
        }

        \support\Db::table('currency_transactions')->delete();
        \support\Db::table('wallets')->delete();
    }

    public function testCreditSameRefIsIdempotent(): void
    {
        $first = WalletService::credit(1, 100, 'test', 'ref-1');
        $second = WalletService::credit(1, 100, 'test', 'ref-1');

        $this->assertSame(0, $first['code']);
        $this->assertSame(0, $second['code']);
        $this->assertSame($first['data']['transaction_id'], $second['data']['transaction_id']);
        $this->assertSame(100, WalletService::balance(1));
    }

    public function testCreditRejectsNonPositiveCoins(): void
    {
        $this->assertSame(422, WalletService::credit(1, 0, 'test', 'ref-0')['code']);
        $this->assertSame(422, WalletService::credit(1, -5, 'test', 'ref-neg')['code']);
        $this->assertSame(0, WalletService::balance(1));
    }

    public function testDebitInsufficientReturns422(): void
    {
        $credit = WalletService::credit(1, 50, 'test', 'ref-a');
        $this->assertSame(0, $credit['code']);

        $debit = WalletService::debit(1, 51, 'test', 'ref-b');

        $this->assertSame(422, $debit['code']);
        $this->assertSame('wallet.insufficient', $debit['lang_key']);
        $this->assertSame(50, WalletService::balance(1));
    }

    public function testDebitSameRefIsIdempotent(): void
    {
        WalletService::credit(1, 100, 'test', 'ref-a');

        $first = WalletService::debit(1, 30, 'test', 'ref-b');
        $second = WalletService::debit(1, 30, 'test', 'ref-b');

        $this->assertSame(0, $first['code']);
        $this->assertSame(0, $second['code']);
        $this->assertSame($first['data']['transaction_id'], $second['data']['transaction_id']);
        $this->assertSame(70, WalletService::balance(1));
    }

    public function testDifferentRefsAccumulate(): void
    {
        WalletService::credit(1, 100, 'test', 'ref-a');
        WalletService::credit(1, 50, 'test', 'ref-b');
        WalletService::debit(1, 30, 'test', 'ref-c');

        $this->assertSame(120, WalletService::balance(1));
    }
}
