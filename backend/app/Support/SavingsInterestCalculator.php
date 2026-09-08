<?php

namespace App\Support;

use App\Models\SavingsAccount;
use App\Models\SavingsTransaction;
use Illuminate\Support\Carbon;

/**
 * Works out the interest a savings account has earned over a posting period,
 * per the account's snapshot interest_calc_method. Simple interest, 365-day
 * year, computed off the running_balance snapshots on savings_transactions —
 * no cash-flow guesswork.
 *
 *   min_monthly_balance : rate/12 on the lowest balance the account held in
 *                         each calendar month of the period (the classic
 *                         Sri Lankan pass-book basis)
 *   daily_balance       : rate * days/365 on the average of each day's
 *                         closing balance
 *   average_balance     : rate * days/365 on (opening + closing) / 2
 *
 * Deliberately kept free of match/enum/nullsafe so a scheduled command could
 * reuse it under the CI PHP 7.4 parse (see the CI constraint memo).
 */
class SavingsInterestCalculator
{
    /**
     * @return array{interest: string, basis_balance: string, method: string, days: int}
     */
    public static function forAccount(SavingsAccount $account, string $from, string $to): array
    {
        $start = Carbon::parse($from)->startOfDay();
        $end = Carbon::parse($to)->startOfDay();
        if ($end->lt($start)) {
            return ['interest' => '0.00', 'basis_balance' => '0.00', 'method' => $account->interest_calc_method, 'days' => 0];
        }

        $changes = self::balanceChangePoints($account, $to);
        $rate = (string) $account->interest_rate_percent;
        $method = $account->interest_calc_method;

        if ($method === 'min_monthly_balance') {
            $interest = self::minMonthlyInterest($changes, $start, $end, $rate);

            return [
                'interest' => $interest,
                'basis_balance' => self::minBalanceInInterval($changes, $start, $end),
                'method' => $method,
                'days' => $start->diffInDays($end) + 1,
            ];
        }

        $days = $start->diffInDays($end) + 1;
        if ($method === 'average_balance') {
            $opening = self::balanceOn($changes, $start);
            $closing = self::balanceOn($changes, $end);
            $avg = bcdiv(bcadd($opening, $closing, 2), '2', 2);
        } else { // daily_balance
            $avg = self::averageDailyBalance($changes, $start, $end, $days);
        }

        $interest = bcdiv(bcmul(bcmul($avg, $rate, 6), (string) $days, 6), bcmul('100', '365', 6), 2);

        return ['interest' => $interest, 'basis_balance' => $avg, 'method' => $method, 'days' => $days];
    }

    /**
     * Ascending [date => balance-after] change points: the account's opening
     * balance is captured as of the day before the first relevant transaction,
     * then every Posted transaction on or before $to.
     *
     * @return array<int, array{date: Carbon, balance: string}>
     */
    private static function balanceChangePoints(SavingsAccount $account, string $to): array
    {
        $rows = SavingsTransaction::where('savings_account_id', $account->id)
            ->where('status', 'Posted')
            ->whereDate('txn_date', '<=', $to)
            ->orderBy('txn_date')->orderBy('id')
            ->get(['txn_date', 'running_balance']);

        $points = [];
        foreach ($rows as $row) {
            $date = Carbon::parse($row->txn_date)->startOfDay();
            // Keep only the last balance for a given day.
            $points[$date->toDateString()] = ['date' => $date, 'balance' => (string) $row->running_balance];
        }

        return array_values($points);
    }

    /** Balance in force on $day (the most recent change point on or before it; 0 before the first). */
    private static function balanceOn(array $changes, Carbon $day): string
    {
        $balance = '0.00';
        foreach ($changes as $point) {
            if ($point['date']->lte($day)) {
                $balance = $point['balance'];
            } else {
                break;
            }
        }

        return $balance;
    }

    private static function minBalanceInInterval(array $changes, Carbon $intervalStart, Carbon $intervalEnd): string
    {
        $min = self::balanceOn($changes, $intervalStart);
        foreach ($changes as $point) {
            if ($point['date']->gte($intervalStart) && $point['date']->lte($intervalEnd)) {
                if (bccomp($point['balance'], $min, 2) < 0) {
                    $min = $point['balance'];
                }
            }
        }

        return $min;
    }

    private static function minMonthlyInterest(array $changes, Carbon $start, Carbon $end, string $rate): string
    {
        $total = '0.00';
        $cursor = $start->copy()->startOfMonth();
        while ($cursor->lte($end)) {
            $monthStart = $cursor->copy()->lt($start) ? $start->copy() : $cursor->copy();
            $monthEnd = $cursor->copy()->endOfMonth();
            if ($monthEnd->gt($end)) {
                $monthEnd = $end->copy();
            }
            $minBal = self::minBalanceInInterval($changes, $monthStart, $monthEnd);
            // rate / 12 on the month's minimum balance.
            $monthInterest = bcdiv(bcmul($minBal, $rate, 6), bcmul('100', '12', 6), 2);
            $total = bcadd($total, $monthInterest, 2);
            $cursor->addMonthNoOverflow();
        }

        return $total;
    }

    private static function averageDailyBalance(array $changes, Carbon $start, Carbon $end, int $days): string
    {
        $sum = '0.00';
        $day = $start->copy();
        while ($day->lte($end)) {
            $sum = bcadd($sum, self::balanceOn($changes, $day), 2);
            $day->addDay();
        }

        return $days > 0 ? bcdiv($sum, (string) $days, 2) : '0.00';
    }
}
