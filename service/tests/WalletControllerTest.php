<?php
// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
require __DIR__ . '/bootstrap.php';

use app\common\WalletService;
use app\controller\WalletController;
use app\model\CurrencyTransaction;
use app\model\Wallet;
use PHPUnit\Framework\TestCase;
use support\Request;

class WalletControllerTest extends TestCase
{
    private const UID = 99002;

    protected function setUp(): void
    {
        CurrencyTransaction::where('user_id', self::UID)->delete();
        Wallet::where('user_id', self::UID)->delete();
    }

    private function request(string $method, string $path): Request
    {
        $req = new Request("$method $path HTTP/1.1\r\nHost: localhost\r\n\r\n");
        $req->uid = self::UID;
        return $req;
    }

    private function body(\Webman\Http\Response $res): array
    {
        return json_decode($res->rawBody(), true);
    }

    public function testBalanceZero(): void
    {
        $res = (new WalletController())->balance($this->request('GET', '/api/v1/wallet/balance'));
        $this->assertSame(0, $this->body($res)['code']);
        $this->assertSame(0, $this->body($res)['data']['coins']);
    }

    public function testBalanceAfterCredit(): void
    {
        WalletService::credit(self::UID, 50, 'test', 'c1');
        $res = (new WalletController())->balance($this->request('GET', '/api/v1/wallet/balance'));
        $this->assertSame(50, $this->body($res)['data']['coins']);
    }

    public function testTransactionsPaged(): void
    {
        WalletService::credit(self::UID, 50, 'test', 'c2');
        WalletService::credit(self::UID, 30, 'test', 'c3');
        $res = (new WalletController())->transactions($this->request('GET', '/api/v1/wallet/transactions?page=1'));
        $data = $this->body($res);
        $this->assertSame(0, $data['code']);
        $this->assertSame(2, $data['data']['total']);
        $this->assertSame(30, $data['data']['list'][0]['amount']);
    }
}
