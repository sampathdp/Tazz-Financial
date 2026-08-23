<?php

namespace App\Support;

use App\Models\CashAccount;
use App\Models\ChartOfAccount;
use App\Models\FdDeposit;
use App\Models\FdInterestPayment;
use App\Models\FdPartPayment;
use App\Models\FdWithdrawal;
use App\Models\JournalEntry;

/**
 * Bridges Fixed Deposit cash movements into the general ledger — the FD
 * counterpart of LoanLedgerPoster/SpeedDraftLedgerPoster, reusing the same
 * LedgerPoster/ChartOfAccount primitives rather than reinventing posting.
 *
 * Scope, deliberately: only Cash-method movements are wired here, same
 * reasoning as every other poster in this codebase — Bank/Cheque have no
 * unambiguous account to move without a captured bank_account_id.
 *
 * FdRenewal's principal rollover is NOT posted — no cash moves when an old
 * FD's principal carries forward into a new one (see FixedDepositController::
 * renew()'s docblock); only a renewal's capital-enhancement portion is a real
 * cash event, and that's posted as an ordinary deposit through
 * postDepositReceived() like any other FdDeposit.
 */
class FdLedgerPoster
{
    /** Cash received for a deposit — Dr the branch till, Cr 2010 (money now owed back to the depositor). */
    public static function postDepositReceived(FdDeposit $deposit): void
    {
        if ($deposit->payment_method !== 'Cash') {
            return;
        }
        $till = CashAccount::branchPrimary((int) $deposit->branch_id);
        if (!$till) {
            return;
        }
        $tillAccount = ChartOfAccount::forCashTill($till);
        $payable = ChartOfAccount::system('2010');

        LedgerPoster::post([
            ['account_id' => $tillAccount->id, 'debit' => $deposit->amount, 'credit' => 0],
            ['account_id' => $payable->id, 'debit' => 0, 'credit' => $deposit->amount],
        ], $deposit->deposit_date, [
            'source_type' => 'FdDeposit',
            'source_id' => $deposit->id,
            'description' => "FD deposit received - {$deposit->deposit_number}",
            'branch_id' => $deposit->branch_id,
            'posted_by' => $deposit->created_by,
        ]);
    }

    /** Reverses a deposit's posting — called when a still-Available (never-allocated) deposit is deleted. No-op if nothing was ever posted for it (Bank/Cheque). */
    public static function reverseDepositReceived(FdDeposit $deposit, ?int $userId = null): void
    {
        JournalEntry::where('source_type', 'FdDeposit')
            ->where('source_id', $deposit->id)
            ->where('status', 'Posted')
            ->whereNull('reversal_of_id')
            ->get()
            ->each(fn (JournalEntry $entry) => LedgerPoster::reverse($entry, "Deleted: {$deposit->deposit_number}", $userId));
    }

    /** A Cash-method interest installment paid out — Dr 5100 Interest Expense - FDs, Cr the branch till. */
    public static function postInterestPaid(FdInterestPayment $payment): void
    {
        if ($payment->payment_method !== 'Cash') {
            return;
        }
        $fd = $payment->fixedDeposit;
        $till = CashAccount::branchPrimary((int) $fd->branch_id);
        if (!$till) {
            return;
        }
        $tillAccount = ChartOfAccount::forCashTill($till);
        $expense = ChartOfAccount::system('5100');

        LedgerPoster::post([
            ['account_id' => $expense->id, 'debit' => $payment->paid_amount, 'credit' => 0],
            ['account_id' => $tillAccount->id, 'debit' => 0, 'credit' => $payment->paid_amount],
        ], $payment->paid_at, [
            'source_type' => 'FdInterestPayment',
            'source_id' => $payment->id,
            'description' => "FD interest paid - {$fd->fd_number} #{$payment->installment_no}",
            'branch_id' => $fd->branch_id,
            'posted_by' => $payment->paid_by,
        ]);
    }

    /** A Cash-method withdrawal — Dr 2010 (principal, liability settled) [+ Dr 5100 if this is a maturity-method FD's accrued interest], Cr the branch till for the total. */
    public static function postWithdrawal(FdWithdrawal $withdrawal): void
    {
        if ($withdrawal->payment_method !== 'Cash') {
            return;
        }
        $fd = $withdrawal->fixedDeposit;
        $till = CashAccount::branchPrimary((int) $fd->branch_id);
        if (!$till) {
            return;
        }
        $tillAccount = ChartOfAccount::forCashTill($till);
        $payable = ChartOfAccount::system('2010');

        $lines = [
            ['account_id' => $payable->id, 'debit' => $withdrawal->principal_amount, 'credit' => 0],
        ];
        if ((float) $withdrawal->interest_amount > 0) {
            $expense = ChartOfAccount::system('5100');
            $lines[] = ['account_id' => $expense->id, 'debit' => $withdrawal->interest_amount, 'credit' => 0];
        }
        $lines[] = ['account_id' => $tillAccount->id, 'debit' => 0, 'credit' => $withdrawal->total_amount];

        LedgerPoster::post($lines, $withdrawal->closed_at, [
            'source_type' => 'FdWithdrawal',
            'source_id' => $withdrawal->id,
            'description' => "FD closed - {$fd->fd_number} ({$withdrawal->voucher_no})",
            'branch_id' => $fd->branch_id,
            'posted_by' => $withdrawal->created_by,
        ]);
    }

    /** Reverses a withdrawal's posting — called when a mistaken FD closure is voided (FixedDepositController::voidWithdrawal()). No-op if nothing was ever posted for it (Bank/Cheque). */
    public static function reverseWithdrawal(FdWithdrawal $withdrawal, ?int $userId = null): void
    {
        JournalEntry::where('source_type', 'FdWithdrawal')
            ->where('source_id', $withdrawal->id)
            ->where('status', 'Posted')
            ->whereNull('reversal_of_id')
            ->get()
            ->each(fn (JournalEntry $entry) => LedgerPoster::reverse($entry, "Voided: {$withdrawal->voucher_no}", $userId));
    }

    /** Reverses an interest payment's posting — called when a mistaken payment is voided (FdInterestPaymentController::void()). No-op if nothing was ever posted for it (Bank/Cheque). */
    public static function reverseInterestPaid(FdInterestPayment $payment, ?int $userId = null): void
    {
        JournalEntry::where('source_type', 'FdInterestPayment')
            ->where('source_id', $payment->id)
            ->where('status', 'Posted')
            ->whereNull('reversal_of_id')
            ->get()
            ->each(fn (JournalEntry $entry) => LedgerPoster::reverse($entry, "Voided installment #{$payment->installment_no}", $userId));
    }

    /**
     * A Cash-method premature part-payment — Dr 2010 (principal returned, liability
     * settled for that portion) [+ Dr 5100 if this is a maturity-method FD's premature-
     * rate interest for the amount withdrawn], Cr 5100 for any clawback (a monthly-method
     * FD's prior interest payouts turning out to exceed the reduced entitlement — see
     * FixedDepositController::partPayment()), Cr the branch till for the net cash paid.
     */
    public static function postPartPayment(FdPartPayment $partPayment): void
    {
        if ($partPayment->payment_method !== 'Cash') {
            return;
        }
        $fd = $partPayment->fixedDeposit;
        $till = CashAccount::branchPrimary((int) $fd->branch_id);
        if (!$till) {
            return;
        }
        $tillAccount = ChartOfAccount::forCashTill($till);
        $payable = ChartOfAccount::system('2010');
        $expense = ChartOfAccount::system('5100');

        $lines = [
            ['account_id' => $payable->id, 'debit' => $partPayment->principal_amount, 'credit' => 0],
        ];
        if ((float) $partPayment->interest_paid > 0) {
            $lines[] = ['account_id' => $expense->id, 'debit' => $partPayment->interest_paid, 'credit' => 0];
        }
        if ((float) $partPayment->clawback_amount > 0) {
            $lines[] = ['account_id' => $expense->id, 'debit' => 0, 'credit' => $partPayment->clawback_amount];
        }
        $lines[] = ['account_id' => $tillAccount->id, 'debit' => 0, 'credit' => $partPayment->net_amount_paid];

        LedgerPoster::post($lines, $partPayment->paid_at, [
            'source_type' => 'FdPartPayment',
            'source_id' => $partPayment->id,
            'description' => "FD part payment - {$fd->fd_number} ({$partPayment->voucher_no})",
            'branch_id' => $fd->branch_id,
            'posted_by' => $partPayment->created_by,
        ]);
    }

    /** Reverses a part payment's posting — called when a mistaken part payment is voided (FixedDepositController::voidPartPayment()). No-op if nothing was ever posted for it (Bank/Cheque). */
    public static function reversePartPayment(FdPartPayment $partPayment, ?int $userId = null): void
    {
        JournalEntry::where('source_type', 'FdPartPayment')
            ->where('source_id', $partPayment->id)
            ->where('status', 'Posted')
            ->whereNull('reversal_of_id')
            ->get()
            ->each(fn (JournalEntry $entry) => LedgerPoster::reverse($entry, "Voided: {$partPayment->voucher_no}", $userId));
    }
}
