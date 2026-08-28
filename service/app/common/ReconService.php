<?php
// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
namespace app\common;

use app\model\CurrencyTransaction;
use app\model\Payment;
use app\model\Wallet;
use app\model\Withdrawal;

/**
 * 内部一致性对账（只读）：支付成功 ↔ payment 入账、取消提现 ↔ withdraw_refund 入账、钱包余额 = Σ流水。
 * 报告实时重算不落库；主记录按 updated_at 过滤（状态翻转时刻，与 credit 同事务同秒，两表日期对齐）。
 */
class ReconService
{
    // ponytail: 明细每类截断上限硬编码，报告量级大时改配置
    private const DETAIL_LIMIT = 100;

    public static function run(string $date): array
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)
            || !checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4))) {
            return ['code' => 422, 'message' => '日期格式错误', 'lang_key' => 'recon.date_invalid'];
        }
        $summary = [
            'payments_succeeded' => 0, 'payments_ok' => 0,
            'payment_credit_missing' => 0, 'payment_credit_orphan' => 0, 'payment_amount_mismatch' => 0,
            'withdrawals_cancelled' => 0, 'withdrawals_ok' => 0,
            'refund_missing' => 0, 'refund_orphan' => 0, 'refund_amount_mismatch' => 0,
            'wallets_checked' => 0, 'wallet_mismatch' => 0, 'wallet_missing' => 0,
            'mismatch_total' => 0,
        ];
        $details = [
            'payment_credit_missing' => [], 'payment_credit_orphan' => [], 'payment_amount_mismatch' => [],
            'refund_missing' => [], 'refund_orphan' => [], 'refund_amount_mismatch' => [],
            'wallet_mismatch' => [], 'wallet_missing' => [],
        ];
        $truncated = false;
        self::checkPayments($date, $summary, $details, $truncated);
        self::checkWithdrawalRefunds($date, $summary, $details, $truncated);
        self::checkWalletBalances($summary, $details, $truncated);
        $summary['mismatch_total'] = $summary['payment_credit_missing'] + $summary['payment_credit_orphan'] + $summary['payment_amount_mismatch']
            + $summary['refund_missing'] + $summary['refund_orphan'] + $summary['refund_amount_mismatch']
            + $summary['wallet_mismatch'] + $summary['wallet_missing'];
        return ['code' => 0, 'message' => 'ok', 'lang_key' => 'ok', 'data' => [
            'date' => $date,
            'generated_at' => date('Y-m-d H:i:s'),
            'ok' => $summary['mismatch_total'] === 0,
            'summary' => $summary,
            'details' => $details,
            'truncated' => $truncated,
        ]];
    }

    /** succeeded payments（trade_no 非空）须恰有 1 条 credit，ref_id="platform:trade_no"，amount == coins */
    private static function checkPayments(string $date, array &$summary, array &$details, bool &$truncated): void
    {
        $payments = Payment::where('status', 'succeeded')->where('trade_no', '<>', '')
            ->whereDate('updated_at', $date)->get();
        $summary['payments_succeeded'] = $payments->count();
        $succeededRefs = [];
        foreach ($payments as $p) {
            $succeededRefs["{$p->user_id}:{$p->platform}:{$p->trade_no}"] = true;
        }
        // UNIQUE(ref_type, ref_id) 为全局键，credit 可能落错用户 → 复合键 (user_id, ref_id) 匹配，错户归入 missing+orphan 两桶
        $credits = CurrencyTransaction::where('ref_type', 'payment')->whereNotNull('ref_id')
            ->whereDate('created_at', $date)->get()->keyBy(fn($c) => "{$c->user_id}:{$c->ref_id}");
        foreach ($payments as $p) {
            $credit = $credits->get("{$p->user_id}:{$p->platform}:{$p->trade_no}");
            if ($credit === null) {
                self::push($details['payment_credit_missing'], [
                    'payment_id' => (int) $p->id, 'user_id' => (int) $p->user_id,
                    'platform' => (string) $p->platform, 'trade_no' => (string) $p->trade_no, 'coins' => (int) $p->coins,
                ], $summary['payment_credit_missing'], $truncated);
            } elseif ((int) $credit->amount !== (int) $p->coins) {
                self::push($details['payment_amount_mismatch'], [
                    'payment_id' => (int) $p->id, 'user_id' => (int) $p->user_id,
                    'payment_coins' => (int) $p->coins, 'credit_amount' => (int) $credit->amount,
                ], $summary['payment_amount_mismatch'], $truncated);
            } else {
                $summary['payments_ok']++;
            }
        }
        foreach ($credits as $key => $credit) {
            if (!isset($succeededRefs[$key])) {
                self::push($details['payment_credit_orphan'], [
                    'transaction_id' => (int) $credit->id, 'user_id' => (int) $credit->user_id,
                    'amount' => (int) $credit->amount, 'ref_id' => (string) $credit->ref_id,
                ], $summary['payment_credit_orphan'], $truncated);
            }
        }
    }

    /** cancelled withdrawals 须恰有 1 条 credit，ref_id="withdraw:{id}"，amount == coins */
    private static function checkWithdrawalRefunds(string $date, array &$summary, array &$details, bool &$truncated): void
    {
        $withdrawals = Withdrawal::where('status', 'cancelled')->whereDate('updated_at', $date)->get();
        $summary['withdrawals_cancelled'] = $withdrawals->count();
        $cancelledRefs = [];
        foreach ($withdrawals as $w) {
            $cancelledRefs["{$w->user_id}:withdraw:{$w->id}"] = true;
        }
        $credits = CurrencyTransaction::where('ref_type', 'withdraw_refund')->whereNotNull('ref_id')
            ->whereDate('created_at', $date)->get()->keyBy(fn($c) => "{$c->user_id}:{$c->ref_id}");
        foreach ($withdrawals as $w) {
            $credit = $credits->get("{$w->user_id}:withdraw:{$w->id}");
            if ($credit === null) {
                self::push($details['refund_missing'], [
                    'withdrawal_id' => (int) $w->id, 'user_id' => (int) $w->user_id, 'coins' => (int) $w->coins,
                ], $summary['refund_missing'], $truncated);
            } elseif ((int) $credit->amount !== (int) $w->coins) {
                self::push($details['refund_amount_mismatch'], [
                    'withdrawal_id' => (int) $w->id, 'user_id' => (int) $w->user_id,
                    'withdrawal_coins' => (int) $w->coins, 'credit_amount' => (int) $credit->amount,
                ], $summary['refund_amount_mismatch'], $truncated);
            } else {
                $summary['withdrawals_ok']++;
            }
        }
        foreach ($credits as $key => $credit) {
            if (!isset($cancelledRefs[$key])) {
                self::push($details['refund_orphan'], [
                    'transaction_id' => (int) $credit->id, 'user_id' => (int) $credit->user_id,
                    'amount' => (int) $credit->amount, 'ref_id' => (string) $credit->ref_id,
                ], $summary['refund_orphan'], $truncated);
            }
        }
    }

    /** 余额核对为累计值，不按日期过滤 */
    private static function checkWalletBalances(array &$summary, array &$details, bool &$truncated): void
    {
        // ponytail: 全量拉取 wallets + 流水聚合到内存（余额为累计值无法按日过滤），百万用户级改 SQL 分片/游标
        $sums = CurrencyTransaction::query()->selectRaw('user_id, SUM(amount) AS ledger_sum')
            ->groupBy('user_id')->get()->keyBy('user_id');
        $wallets = Wallet::all()->keyBy('user_id');
        $summary['wallets_checked'] = $wallets->count();
        foreach ($wallets as $wallet) {
            $ledgerSum = (int) ($sums->get((int) $wallet->user_id)?->ledger_sum ?? 0);
            if ((int) $wallet->coins !== $ledgerSum) {
                self::push($details['wallet_mismatch'], [
                    'user_id' => (int) $wallet->user_id, 'wallet_coins' => (int) $wallet->coins, 'ledger_sum' => $ledgerSum,
                ], $summary['wallet_mismatch'], $truncated);
            }
        }
        foreach ($sums as $userId => $row) {
            if (!$wallets->has($userId)) {
                self::push($details['wallet_missing'], [
                    'user_id' => (int) $userId, 'ledger_sum' => (int) $row->ledger_sum,
                ], $summary['wallet_missing'], $truncated);
            }
        }
    }

    private static function push(array &$list, array $item, int &$total, bool &$truncated): void
    {
        $total++;
        if (count($list) < self::DETAIL_LIMIT) {
            $list[] = $item;
        } else {
            $truncated = true;
        }
    }
}
