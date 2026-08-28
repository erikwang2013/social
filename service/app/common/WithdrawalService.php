<?php
// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
namespace app\common;

use app\model\Withdrawal;
use support\Db;
use Throwable;

class WithdrawalService
{
    private const PLATFORMS = ['wechat', 'alipay', 'stripe'];

    /** 申请提现：client_ref 幂等返回原单；单事务建单 + debit 扣币，余额不足整体回滚 */
    public static function apply(int $uid, string $platform, int $coins, string $currency, string $account, string $clientRef): array
    {
        if (!in_array($platform, self::PLATFORMS, true)) {
            return self::err(400, '提现渠道不支持', 'withdraw.platform_invalid');
        }
        if ($coins <= 0 || $account === '' || !preg_match('/^[A-Za-z0-9_-]{8,64}$/', $clientRef)) {
            return self::err(400, '参数缺失或格式错误', 'withdraw.params_invalid');
        }
        $min = (int) config('payment.withdraw_min_coins', 100);
        if ($coins < $min) {
            return self::err(422, '低于最低提现额', 'withdraw.below_min');
        }
        if ($withdrawal = Withdrawal::where('user_id', $uid)->where('client_ref', $clientRef)->first()) {
            return self::ok($withdrawal);
        }
        try {
            $withdrawal = Db::transaction(function () use ($uid, $platform, $coins, $currency, $account, $clientRef) {
                $debit = WalletService::debit($uid, $coins, 'withdraw', "{$platform}:{$clientRef}", "提现 {$platform} {$coins}币", 'withdraw');
                if ($debit['code'] !== 0) {
                    throw new \RuntimeException((string) $debit['lang_key']);
                }
                return Withdrawal::create([
                    'user_id' => $uid, 'platform' => $platform, 'account' => $account,
                    'coins' => $coins, 'currency' => strtoupper($currency), 'client_ref' => $clientRef, 'status' => 'pending',
                ]);
            });
            return self::ok($withdrawal);
        } catch (Throwable $e) {
            if ($e->getMessage() === 'wallet.insufficient') {
                return self::err(422, '余额不足', 'withdraw.insufficient');
            }
            // 并发建单：UNIQUE client_ref 兜底，回读原单
            if ($withdrawal = Withdrawal::where('user_id', $uid)->where('client_ref', $clientRef)->first()) {
                return self::ok($withdrawal);
            }
            return self::err(500, '提现申请失败', 'withdraw.apply_failed');
        }
    }

    /** 取消提现：仅本人 pending 单；单事务置 cancelled + credit 退回（幂等） */
    public static function cancel(int $uid, int $id): array
    {
        $outcome = 'not_found';
        $withdrawal = null;
        try {
            Db::transaction(function () use ($uid, $id, &$outcome, &$withdrawal) {
                $withdrawal = Withdrawal::where('id', $id)->lockForUpdate()->first();
                if ($withdrawal === null || (int) $withdrawal->user_id !== $uid) {
                    $outcome = 'not_found';
                    return;
                }
                if ($withdrawal->status !== 'pending') {
                    $outcome = 'already_processed';
                    return;
                }
                $credit = WalletService::credit($uid, (int) $withdrawal->coins, 'withdraw_refund', "withdraw:{$id}", '提现取消退回');
                if ($credit['code'] !== 0) {
                    throw new \RuntimeException(); // 回滚，保持 pending
                }
                $withdrawal->status = 'cancelled';
                $withdrawal->reason = '用户取消';
                $withdrawal->save();
                $outcome = 'cancelled';
            });
        } catch (Throwable) {
            return self::err(500, '取消失败', 'withdraw.cancel_failed');
        }
        return match ($outcome) {
            'already_processed' => self::err(422, '提现单已处理', 'withdraw.already_processed'),
            'cancelled' => self::ok($withdrawal),
            default => self::err(404, '提现单不存在', 'withdraw.not_found'),
        };
    }

    /** 本人提现列表：20/页倒序 */
    public static function list(int $uid, int $page = 1, int $limit = 20): array
    {
        $query = Withdrawal::where('user_id', $uid);
        return [
            'list' => (clone $query)->orderByDesc('id')->forPage($page, $limit)->get(),
            'total' => $query->count(),
        ];
    }

    private static function ok(Withdrawal $withdrawal): array
    {
        return ['code' => 0, 'message' => 'ok', 'lang_key' => 'withdraw.created', 'data' => [
            'id' => $withdrawal->id, 'coins' => (int) $withdrawal->coins,
            'currency' => (string) $withdrawal->currency, 'status' => (string) $withdrawal->status,
            'reason' => (string) ($withdrawal->reason ?? ''),
            'balance' => WalletService::balance((int) $withdrawal->user_id),
        ]];
    }

    private static function err(int $code, string $message, string $langKey): array
    {
        return ['code' => $code, 'message' => $message, 'lang_key' => $langKey];
    }
}
