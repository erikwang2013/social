<?php
// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
namespace app\common;

use app\model\CurrencyTransaction;
use app\model\Wallet;
use support\Db;
use Throwable;

class WalletService
{
    /** 幂等入账：ref_type+ref_id 已入账则返回既有流水，不重复加币 */
    public static function credit(int $uid, int $coins, string $refType, string $refId, string $note = ''): array
    {
        if ($coins <= 0) {
            return ['code' => 422, 'message' => '币值需为正数', 'lang_key' => 'wallet.coins_invalid'];
        }
        try {
            $tx = Db::transaction(function () use ($uid, $coins, $refType, $refId, $note) {
                if ($exist = self::findByRef($uid, $refType, $refId)) {
                    return $exist;
                }
                $wallet = Wallet::firstOrCreate(['user_id' => $uid]);
                $wallet->coins += $coins;
                $wallet->save();
                return CurrencyTransaction::create([
                    'user_id' => $uid, 'type' => 'recharge', 'amount' => $coins,
                    'balance_after' => $wallet->coins, 'ref_type' => $refType, 'ref_id' => $refId, 'note' => $note,
                ]);
            });
            return self::ok($tx);
        } catch (Throwable) {
            // 并发重复入账：唯一索引兜底，回读既有流水
            if ($exist = self::findByRef($uid, $refType, $refId)) {
                return self::ok($exist);
            }
            return ['code' => 500, 'message' => '入账失败', 'lang_key' => 'wallet.credit_failed'];
        }
    }

    /** 扣款：余额不足 422；ref 已存在则幂等返回成功 */
    public static function debit(int $uid, int $coins, string $refType, string $refId, string $note = '', string $type = 'gift_sent'): array
    {
        if ($coins <= 0) {
            return ['code' => 422, 'message' => '币值需为正数', 'lang_key' => 'wallet.coins_invalid'];
        }
        try {
            $tx = Db::transaction(function () use ($uid, $coins, $refType, $refId, $note, $type) {
                if ($exist = self::findByRef($uid, $refType, $refId)) {
                    return $exist;
                }
                $wallet = Wallet::where('user_id', $uid)->first();
                if ($wallet === null || $wallet->coins < $coins) {
                    throw new \RuntimeException('insufficient');
                }
                $wallet->coins -= $coins;
                $wallet->save();
                return CurrencyTransaction::create([
                    'user_id' => $uid, 'type' => $type, 'amount' => -$coins,
                    'balance_after' => $wallet->coins, 'ref_type' => $refType, 'ref_id' => $refId, 'note' => $note,
                ]);
            });
            return self::ok($tx);
        } catch (Throwable $e) {
            if ($e->getMessage() === 'insufficient') {
                return ['code' => 422, 'message' => '余额不足', 'lang_key' => 'wallet.insufficient'];
            }
            if ($exist = self::findByRef($uid, $refType, $refId)) {
                return self::ok($exist);
            }
            return ['code' => 500, 'message' => '扣款失败', 'lang_key' => 'wallet.debit_failed'];
        }
    }

    public static function balance(int $uid): int
    {
        $wallet = Wallet::where('user_id', $uid)->first();
        return $wallet?->coins ?? 0;
    }

    /** 流水分页：20/页，倒序 */
    public static function transactions(int $uid, int $page = 1, int $limit = 20): array
    {
        $query = CurrencyTransaction::where('user_id', $uid);
        return [
            'list' => (clone $query)->orderByDesc('id')->forPage($page, $limit)->get(),
            'total' => $query->count(),
        ];
    }

    private static function findByRef(int $uid, string $refType, string $refId): ?CurrencyTransaction
    {
        return CurrencyTransaction::where('user_id', $uid)
            ->where('ref_type', $refType)->where('ref_id', $refId)->first();
    }

    private static function ok(CurrencyTransaction $tx): array
    {
        return ['code' => 0, 'message' => 'ok', 'lang_key' => 'ok', 'data' => ['transaction_id' => $tx->id, 'balance' => $tx->balance_after]];
    }
}
