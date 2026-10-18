<?php

namespace App\Support;

use App\Models\SavingsAccount;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The cost-side mirror of AccrualInterestCalculator: "how much interest expense
 * on customer funds (Fixed Deposits + Daily Savings) a period was *supposed* to
 * incur" — the Accrual Basis view of Interest Cost on the Profit & Loss report
 * (AccountingReportController::plWaterfall() / plFigures()).
 *
 * Nothing is stored or posted; a pure read recomputed on every request, so it
 * can never drift from the schedules it reads. This is a P&L-view figure only:
 * ledger accounts 5100 / 5120 keep their cash / run-posted balances and the
 * Balance Sheet is unaffected — the same treatment accrual interest income
 * already gets.
 *
 *   FD, monthly method  : each fd_interest_payments row's amount, day-count
 *                         apportioned across the calendar months of its accrual
 *                         window [previous due_date | start_date, its due_date)
 *                         via InterestAccrualSchedule::monthlySlices().
 *   FD, maturity method : total_interest_amount apportioned the same way across
 *                         [start_date, maturity_date) — no schedule rows exist.
 *   Savings             : SavingsInterestCalculator::forAccount() per account,
 *                         summed — the figure a Savings Interest Run would
 *                         compute for the period whether or not one was posted.
 *
 * A Closed / Renewed FD stops accruing at its closed_at (mirrors the income
 * side capping a settled installment at its paid_at).
 */
class AccrualInterestCostCalculator
{
    /** @return array{fd: string, savings: string, total: string} */
    public static function forPeriod(?string $from, ?string $to): array
    {
        $fd = self::fdInterestForPeriod($from, $to);
        $savings = self::savingsInterestForPeriod($from, $to);

        return ['fd' => $fd, 'savings' => $savings, 'total' => bcadd($fd, $savings, 2)];
    }

    public static function fdInterestForPeriod(?string $from, ?string $to): string
    {
        $fds = DB::table('fixed_deposits')
            ->get(['id', 'interest_payment_method', 'start_date', 'maturity_date', 'total_interest_amount', 'status', 'closed_at']);
        if ($fds->isEmpty()) {
            return '0.00';
        }

        $scheduleByFd = DB::table('fd_interest_payments')
            ->whereIn('fixed_deposit_id', $fds->pluck('id'))
            ->orderBy('installment_no')
            ->get(['fixed_deposit_id', 'due_date', 'amount'])
            ->groupBy('fixed_deposit_id');

        // Open-ended report ("All Dates", no To): accrue only through today, not to maturity.
        $asOf = $to ? null : Carbon::today();

        $total = '0.00';
        foreach ($fds as $fd) {
            $windowEnd = in_array($fd->status, ['Closed', 'Renewed'], true) && $fd->closed_at
                ? Carbon::parse($fd->closed_at)
                : null;

            $slices = [];
            if ($fd->interest_payment_method === 'monthly') {
                $cursor = Carbon::parse($fd->start_date);
                foreach ($scheduleByFd->get($fd->id, collect()) as $row) {
                    $due = Carbon::parse($row->due_date);
                    $end = $windowEnd && $windowEnd->lessThan($due) ? $windowEnd->copy() : $due;
                    $amount = (float) $row->amount;
                    if ($asOf && $amount != 0.0 && $end->greaterThan($cursor)) {
                        $amount = InterestAccrualSchedule::accruedThrough($cursor, $end, $amount, $asOf);
                        if ($asOf->lessThan($end)) {
                            $end = $asOf->copy();
                        }
                    }
                    if ($end->greaterThan($cursor) && $amount != 0.0) {
                        array_push($slices, ...InterestAccrualSchedule::monthlySlices($cursor->copy(), $end, $amount));
                    }
                    $cursor = $due;
                }
            } else { // maturity — interest accrues silently over the whole term
                $start = Carbon::parse($fd->start_date);
                $end = Carbon::parse($fd->maturity_date);
                if ($windowEnd && $windowEnd->lessThan($end)) {
                    $end = $windowEnd->copy();
                }
                $amount = (float) $fd->total_interest_amount;
                if ($asOf && $amount != 0.0 && $end->greaterThan($start)) {
                    $amount = InterestAccrualSchedule::accruedThrough($start, $end, $amount, $asOf);
                    if ($asOf->lessThan($end)) {
                        $end = $asOf->copy();
                    }
                }
                if ($end->greaterThan($start) && $amount != 0.0) {
                    $slices = InterestAccrualSchedule::monthlySlices($start, $end, $amount);
                }
            }

            foreach ($slices as $slice) {
                $sliceDate = $slice['date']->toDateString();
                if ($from && $sliceDate < $from) {
                    continue;
                }
                if ($to && $sliceDate > $to) {
                    continue;
                }
                $total = bcadd($total, (string) round($slice['amount'], 2), 2);
            }
        }

        return $total;
    }

    public static function savingsInterestForPeriod(?string $from, ?string $to): string
    {
        $accounts = SavingsAccount::whereIn('status', ['Active', 'Dormant', 'Closed'])->get();
        if ($accounts->isEmpty()) {
            return '0.00';
        }

        // SavingsInterestCalculator needs a bounded window; an open-ended report
        // range clamps per account to its own open_date .. closed_on / today.
        $rangeStart = $from ?: '1970-01-01';
        $rangeEnd = $to ?: Carbon::today()->toDateString();

        $total = '0.00';
        foreach ($accounts as $account) {
            $openDate = $account->open_date ? $account->open_date->toDateString() : $rangeStart;
            $start = $openDate > $rangeStart ? $openDate : $rangeStart;

            $end = $rangeEnd;
            if ($account->closed_on && $account->closed_on->toDateString() < $end) {
                $end = $account->closed_on->toDateString();
            }
            if ($start > $end) {
                continue;
            }

            $calc = SavingsInterestCalculator::forAccount($account, $start, $end);
            if (bccomp($calc['interest'], '0.00', 2) > 0) {
                $total = bcadd($total, $calc['interest'], 2);
            }
        }

        return $total;
    }
}
