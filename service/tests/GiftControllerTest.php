<?php
// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
require __DIR__ . '/bootstrap.php';

use app\common\WalletService;
use app\controller\GiftController;
use app\model\CurrencyTransaction;
use app\model\GiftCatalog;
use app\model\GiftGiven;
use app\model\LiveRoom;
use app\model\Wallet;
use PHPUnit\Framework\TestCase;
use support\Request;

class GiftControllerTest extends TestCase
{
    private const UID = 99013;
    private const ROOM_TITLE = '控制器测试房间';

    private int $giftId;
    private int $offGiftId;
    private int $roomId;

    protected function setUp(): void
    {
        GiftGiven::where('from_uid', self::UID)->delete();
        CurrencyTransaction::where('user_id', self::UID)->delete();
        Wallet::where('user_id', self::UID)->delete();
        GiftCatalog::whereIn('name', ['鲜花', '下架礼物'])->delete();
        LiveRoom::where('title', self::ROOM_TITLE)->delete();
        $this->giftId = GiftCatalog::create([
            'name' => '鲜花', 'coins_price' => 50,
            'effect_key' => 'flower', 'status' => 1, 'sort' => 1,
        ])->id;
        $this->offGiftId = GiftCatalog::create([
            'name' => '下架礼物', 'coins_price' => 10,
            'effect_key' => 'off', 'status' => 0, 'sort' => 2,
        ])->id;
        $this->roomId = LiveRoom::create([
            'owner_id' => 99014, 'title' => self::ROOM_TITLE, 'status' => 1,
        ])->id;
        WalletService::credit(self::UID, 500, 'test', 'gc1');
    }

    private function request(string $method, string $path, string $body = ''): Request
    {
        $req = new Request("$method $path HTTP/1.1\r\nHost: localhost\r\nContent-Type: application/x-www-form-urlencoded\r\nContent-Length: " . strlen($body) . "\r\n\r\n$body");
        $req->uid = self::UID;
        return $req;
    }

    private function body(\Webman\Http\Response $res): array
    {
        return json_decode($res->rawBody(), true);
    }

    public function testCatalogOnShelfOnly(): void
    {
        $res = (new GiftController())->catalog($this->request('GET', '/api/v1/gifts'));
        $data = $this->body($res);
        $this->assertSame(0, $data['code']);
        $ids = array_column($data['data']['list'], 'id');
        $this->assertContains($this->giftId, $ids);
        $this->assertNotContains($this->offGiftId, $ids);
    }

    public function testSendSuccess(): void
    {
        $body = http_build_query(['gift_id' => $this->giftId, 'quantity' => 2, 'client_ref' => 'client-ref-gc-0001']);
        $res = (new GiftController())->send($this->request('POST', '/api/v1/live/rooms/' . $this->roomId . '/gift', $body), (string) $this->roomId);
        $data = $this->body($res);
        $this->assertSame(0, $data['code']);
        $this->assertSame(400, $data['data']['balance']); // 500 - 50*2
        $this->assertSame(1, GiftGiven::where('client_ref', 'client-ref-gc-0001')->count());
    }

    public function testSendRoomNotFound(): void
    {
        $res = (new GiftController())->send($this->request('POST', '/api/v1/live/rooms/999999/gift'), '999999');
        $this->assertSame(404, $this->body($res)['code']);
    }
}
