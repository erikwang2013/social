<?php
// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
require __DIR__ . '/bootstrap.php';

use app\common\GiftService;
use app\common\WalletService;
use app\model\CurrencyTransaction;
use app\model\GiftCatalog;
use app\model\GiftGiven;
use app\model\LiveRoom;
use app\model\StreamerEarning;
use app\model\Wallet;
use PHPUnit\Framework\TestCase;

class GiftServiceTest extends TestCase
{
    private const UID = 99011;
    private const STREAMER = 99012;
    private const COINS_PRICE = 100;

    private int $giftId;
    private int $roomId;

    protected function setUp(): void
    {
        GiftGiven::where('from_uid', self::UID)->delete();
        StreamerEarning::where('streamer_uid', self::STREAMER)->delete();
        CurrencyTransaction::whereIn('user_id', [self::UID, self::STREAMER])->delete();
        Wallet::whereIn('user_id', [self::UID, self::STREAMER])->delete();
        GiftCatalog::where('name', '火箭')->delete();
        LiveRoom::where('title', '测试直播间')->delete();
        $this->seed();
    }

    private function seed(): void
    {
        $this->giftId = GiftCatalog::create([
            'name' => '火箭', 'coins_price' => self::COINS_PRICE,
            'effect_key' => 'rocket', 'status' => 1, 'sort' => 1,
        ])->id;
        $this->roomId = LiveRoom::create([
            'owner_id' => self::STREAMER, 'title' => '测试直播间', 'status' => 1,
        ])->id;
        WalletService::credit(self::UID, 1000, 'test', 'gs1');
    }

    public function testSendDeductsAndSplits(): void
    {
        $res = GiftService::send(self::UID, $this->roomId, $this->giftId, 2, 'client-ref-gs-0001');
        $this->assertSame(0, $res['code']);
        $this->assertSame(800, $res['data']['balance']); // 1000 - 100*2
        $this->assertSame(800, WalletService::balance(self::UID));

        $given = GiftGiven::where('client_ref', 'client-ref-gs-0001')->first();
        $this->assertNotNull($given);
        $this->assertSame(200, $given->coins_total);
        $this->assertSame(2, $given->quantity);
        $this->assertSame(self::STREAMER, $given->to_uid);

        // 70% 分成: 200*70/100 = 140
        $this->assertSame(140, WalletService::balance(self::STREAMER));
        $earning = StreamerEarning::where('gift_given_id', $given->id)->first();
        $this->assertSame(70, $earning->ratio);
        $this->assertSame(140, $earning->coins_amount);
    }

    public function testSendRetryIdempotent(): void
    {
        $first = GiftService::send(self::UID, $this->roomId, $this->giftId, 2, 'client-ref-gs-0002');
        $second = GiftService::send(self::UID, $this->roomId, $this->giftId, 2, 'client-ref-gs-0002');
        $this->assertSame(0, $second['code']);
        $this->assertSame($first['data']['gift_given_id'], $second['data']['gift_given_id']);
        $this->assertSame(1, GiftGiven::where('client_ref', 'client-ref-gs-0002')->count());
        $this->assertSame(800, WalletService::balance(self::UID)); // 只扣一次
        $this->assertSame(140, WalletService::balance(self::STREAMER)); // 只入账一次
    }

    public function testSendInsufficient(): void
    {
        WalletService::debit(self::UID, 900, 'test', 'gs2'); // 剩 100
        $res = GiftService::send(self::UID, $this->roomId, $this->giftId, 2, 'client-ref-gs-0003'); // 需 200
        $this->assertSame(422, $res['code']);
        $this->assertSame('wallet.insufficient', $res['lang_key']);
        $this->assertSame(100, WalletService::balance(self::UID));
        $this->assertSame(0, WalletService::balance(self::STREAMER));
    }

    public function testSendRoomNotFound(): void
    {
        $res = GiftService::send(self::UID, 999999, $this->giftId, 1, 'client-ref-gs-0004');
        $this->assertSame(404, $res['code']);
        $this->assertSame('live.room_not_found', $res['lang_key']);
    }

    public function testSendGiftNotFound(): void
    {
        $res = GiftService::send(self::UID, $this->roomId, 999999, 1, 'client-ref-gs-0005');
        $this->assertSame(404, $res['code']);
        $this->assertSame('gift.not_found', $res['lang_key']);
    }

    public function testSendQuantityInvalid(): void
    {
        $this->assertSame(422, GiftService::send(self::UID, $this->roomId, $this->giftId, 0, 'client-ref-gs-0006')['code']);
        $this->assertSame(422, GiftService::send(self::UID, $this->roomId, $this->giftId, 101, 'client-ref-gs-0007')['code']);
    }

    public function testSendClientRefInvalid(): void
    {
        $this->assertSame(422, GiftService::send(self::UID, $this->roomId, $this->giftId, 1, 'short')['code']);
    }
}
