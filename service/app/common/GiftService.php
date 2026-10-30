<?php
// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
namespace app\common;

use app\model\GiftCatalog;
use app\model\GiftGiven;
use app\model\LiveRoom;
use app\model\StreamerEarning;
use app\model\Wallet;
use app\ws\Envelope;
use app\ws\WsRedis;
use support\Db;
use Throwable;

class GiftService
{
    /** 送礼：单事务扣款+记录+分成；client_ref 客户端生成，重试幂等 */
    public static function send(int $fromUid, int $roomId, int $giftId, int $quantity, string $clientRef): array
    {
        $room = LiveRoom::where('id', $roomId)->where('status', 1)->first();
        if ($room === null) {
            return self::err(404, '直播间不存在', 'live.room_not_found');
        }
        $gift = GiftCatalog::where('id', $giftId)->where('status', 1)->first();
        if ($gift === null) {
            return self::err(404, '礼物不存在', 'gift.not_found');
        }
        if ($quantity < 1 || $quantity > 100) {
            return self::err(422, '数量需 1-100', 'gift.quantity_invalid');
        }
        if (strlen($clientRef) < 8 || strlen($clientRef) > 64) {
            return self::err(422, 'client_ref 需 8-64 字符', 'gift.client_ref_invalid');
        }

        $total = $gift->coins_price * $quantity;
        $ratio = max(0, min(100, (int) config('live.split_streamer_percent', 70)));
        $streamerCoins = intdiv($total * $ratio, 100);

        $given = null;
        $created = false;
        try {
            Db::transaction(function () use ($fromUid, $room, $gift, $quantity, $clientRef, $total, $ratio, $streamerCoins, &$given, &$created) {
                // 按 user_id 升序锁发送者/主播钱包，避免 A↔B 互送礼时与 debit/credit 的加锁顺序形成死锁
                $walletUids = array_values(array_unique([$fromUid, (int) $room->owner_id]));
                sort($walletUids);
                foreach ($walletUids as $walletUid) {
                    Db::table('wallets')->insertOrIgnore(['user_id' => $walletUid]);
                }
                Wallet::whereIn('user_id', $walletUids)->orderBy('user_id')->lockForUpdate()->get();

                // client_ref 只对同一发送者幂等，UNIQUE (from_uid, client_ref) 兜底并发
                $existing = GiftGiven::where('from_uid', $fromUid)->where('client_ref', $clientRef)->first();
                if ($existing !== null) {
                    // 重试幂等：首提已扣款+入账，直接返回原记录
                    $given = $existing;
                    return;
                }
                $debit = WalletService::debit($fromUid, $total, 'gift', $clientRef, "礼物 {$gift->name} x{$quantity}");
                if ($debit['code'] !== 0) {
                    throw new \RuntimeException(json_encode($debit));
                }
                $given = GiftGiven::create([
                    'from_uid' => $fromUid, 'to_uid' => $room->owner_id, 'room_id' => $room->id,
                    'room_type' => 1, 'gift_id' => $gift->id, 'quantity' => $quantity, 'coins_total' => $total,
                    'client_ref' => $clientRef,
                ]);
                $created = true;
                if ($streamerCoins > 0) {
                    StreamerEarning::create([
                        'streamer_uid' => $room->owner_id, 'gift_given_id' => $given->id,
                        'ratio' => $ratio, 'coins_amount' => $streamerCoins,
                    ]);
                    $credit = WalletService::credit($room->owner_id, $streamerCoins, 'gift_earning', 'gg:' . $given->id, "直播礼物分成 #{$given->id}", 'gift_received');
                    if ($credit['code'] !== 0) {
                        // 分成未入账则整单回滚，避免礼物已送出而主播永久漏账
                        throw new \RuntimeException(json_encode($credit));
                    }
                }
            });
        } catch (Throwable $e) {
            if (str_starts_with($e->getMessage(), '{')) {
                return json_decode($e->getMessage(), true);
            }
            // 并发首提：唯一键兜底后回读原记录，按幂等成功返回（不重新广播）
            if (!$created && ($existing = GiftGiven::where('from_uid', $fromUid)->where('client_ref', $clientRef)->first()) !== null) {
                $given = $existing;
            } else {
                return self::err(500, '送礼失败', 'gift.send_failed');
            }
        }

        // 仅新礼物广播特效；幂等重试不重复推送。HTTP 进程走跨进程队列（ws worker 消费直推，与 Rust 广播同通道）
        if ($created) {
            try {
                WsRedis::call(fn($r) => $r->rpush('social:live:broadcast', Envelope::encode(Envelope::T_LIVE_GIFT, [
                    'room_id' => $room->id, 'from_uid' => $fromUid, 'to_uid' => $room->owner_id,
                    'gift_id' => $gift->id, 'quantity' => $quantity, 'effect_key' => $gift->effect_key,
                ])));
            } catch (Throwable) {
                // ponytail: 广播失败不影响账务，礼物已送出
            }
        }

        return ['code' => 0, 'message' => 'ok', 'lang_key' => 'ok', 'data' => ['gift_given_id' => $given->id, 'balance' => WalletService::balance($fromUid)]];
    }

    private static function err(int $code, string $message, string $langKey): array
    {
        return ['code' => $code, 'message' => $message, 'lang_key' => $langKey];
    }
}
