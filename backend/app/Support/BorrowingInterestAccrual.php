<?php

namespace App\Support;

use App\Models\Borrowing;
use Illuminate\Support\Carbon;

/**
 * Books interest on a company borrowing as it is earned by the lender, instead
 * of only when it is paid: Dr 5110 Interest Expense - Borrowings, Cr 2220
 * Accrued Interest Payable. An InterestPayment later clears 2220 first (see
 * BorrowingLedgerPoster::postTransaction()), so the expense is never counted
 * twice.
 *
 * Simple interest on the current outstanding principal, actual/365 — the same
 * convention as BranchFundingService::accrue(). It is called before anything
 * that changes the balance or the rate (a draw-down, repayment, settlement,
 * interest payment, renewal), so the outstanding amount used for each span is
 * the one that was really owed during it. Overdraft facilities are not accrued
 * here: the bank charges that interest and it is recorded when paid.
 */
class BorrowingInterestAccrual
{
    public static function applies(Borrowing $b): bool
    {
        return $b->type !== 'Overdraft' && $b->status === 'Active' && $b->interest_accrued_through !== null;
    }

    /** Interest earned since the last booking up to $through but not yet posted — read-only, for display. */
    public static function pending(Borrowing $b, string $through): string
    {
        if (!self::applies($b)) {
            return '0.00';
        }
        $days = (int) Carbon::parse($b->interest_accrued_through)->startOfDay()->diffInDays(Carbon::parse($through)->startOfDay(), false);
        if ($days <= 0) {
            return '0.00';
        }

        return number_format((float) $b->outstanding_amount * ((float) $b->interest_rate_percent / 100) * $days / 365, 2, '.', '');
    }

    /** Accrue up to $through. Returns the amount booked ('0.00' when nothing was due). */
    public static function accrue(Borrowing $b, string $through, ?int $userId = null): string
    {
        if (!self::applies($b)) {
            return '0.00';
        }
        $from = Carbon::parse($b->interest_accrued_through)->startOfDay();
        $to = Carbon::parse($through)->startOfDay();
        $days = (int) $from->diffInDays($to, false);
        if ($days <= 0) {
            return '0.00';
        }

        $interest = number_format(
            (float) $b->outstanding_amount * ((float) $b->interest_rate_percent / 100) * $days / 365,
            2, '.', ''
        );
        $b->interest_accrued_through = $to->toDateString();
        if (bccomp($interest, '0', 2) > 0) {
            BorrowingLedgerPoster::postAccrual($b, $interest, $to->toDateString(), "{$from->toDateString()} to {$to->toDateString()}", $userId);
            $b->accrued_interest_unpaid = bcadd((string) $b->accrued_interest_unpaid, $interest, 2);
        }
        $b->save();

        return $interest;
    }
}
