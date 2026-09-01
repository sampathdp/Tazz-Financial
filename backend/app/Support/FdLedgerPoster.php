<?php

namespace App\Support;

use App\Models\CashAccount;
use App\Models\ChartOfAccount;
use App\Models\FdDeposit;
use App\Models\FdInterestPayment;
use App\Models\FdPartPayment;
use App\Models\FinanceTransaction;
use App\Models\FdWithdrawal;
use App\Models\JournalEntry;

/**
 * Bridges Fixed Deposit cash movements into the general ledger — the FD
 * counterpart of LoanLedgerPoster/SpeedDraftLedgerPoster.
 *
 * Since Phase 9 every cash leg goes through FinancePostingService (the one
 * money writer): the branch till's current_balance moves, an immutable
 * finance_transactions row is recorded and a cashbook_entries mirror is
 * written — atomically with the GL entry. The separate FdCashbookPoster that
 * used to move the till on its own is gone.
 *
 * The journal entry stays keyed to the FD record (source_type='FdDeposit'
 * etc.) via gl_source_type, so the reverse* lookups here and any report that
 * filters journal_entries by the FD source keep working unchanged.
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

        self::postCashLeg($till, '+' . $deposit->amount, [
            ['account_id' => $tillAccount->id, 'debit' => $deposit->amount, 'credit' => 0],
            ['account_id' => $payable->id, 'debit' => 0, 'credit' => $deposit->amount],
        ], [
            'txn_type' => 'CASH_IN',
            'amount' => $deposit->amount,
            'entry_date' => $deposit->deposit_date,
            'branch_id' => $deposit->branch_id,
            'source_type' => 'FdDeposit',
            'source_id' => $deposit->id,
            'idempotency_key' => "fd-deposit-{$deposit->id}",
            'description' => "FD deposit received - {$deposit->deposit_number}",
            'posted_by' => $deposit->created_by,
        ]);
    }

    /** Reverses a deposit's posting — called when a still-Available (never-allocated) deposit is deleted. No-op if nothing was ever posted for it (Bank/Cheque). */
    public static function reverseDepositReceived(FdDeposit $deposit, ?int $userId = null): void
    {
        self::reverseVia('FdDeposit', $deposit->id, "Deleted: {$deposit->deposit_number}", $userId);
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

        self::postCashLeg($till, '-' . $payment->paid_amount, [
            ['account_id' => $expense->id, 'debit' => $payment->paid_amount, 'credit' => 0],
            ['account_id' => $tillAccount->id, 'debit' => 0, 'credit' => $payment->paid_amount],
        ], [
            'txn_type' => 'CASH_OUT',
            'amount' => $payment->paid_amount,
            'entry_date' => $payment->paid_at,
            'branch_id' => $fd->branch_id,
            'source_type' => 'FdInterestPayment',
            'source_id' => $payment->id,
            'idempotency_key' => "fd-interest-{$payment->id}",
            'description' => "FD interest paid - {$fd->fd_number} #{$payment->installment_no}",
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

        self::postCashLeg($till, '-' . $withdrawal->total_amount, $lines, [
            'txn_type' => 'CASH_OUT',
            'amount' => $withdrawal->total_amount,
            'entry_date' => $withdrawal->closed_at,
            'branch_id' => $fd->branch_id,
            'source_type' => 'FdWithdrawal',
            'source_id' => $withdrawal->id,
            'idempotency_key' => "fd-withdrawal-{$withdrawal->id}",
            'description' => "FD closed - {$fd->fd_number} ({$withdrawal->voucher_no})",
            'posted_by' => $withdrawal->created_by,
        ]);
    }

    /** Reverses a withdrawal's posting — called when a mistaken FD closure is voided (FixedDepositController::voidWithdrawal()). No-op if nothing was ever posted for it (Bank/Cheque). */
    public static function reverseWithdrawal(FdWithdrawal $withdrawal, ?int $userId = null): void
    {
        self::reverseVia('FdWithdrawal', $withdrawal->id, "Voided: {$withdrawal->voucher_no}", $userId);
    }

    /** Reverses an interest payment's posting — called when a mistaken payment is voided (FdInterestPaymentController::void()). No-op if nothing was ever posted for it (Bank/Cheque). */
    public static function reverseInterestPaid(FdInterestPayment $payment, ?int $userId = null): void
    {
        self::reverseVia('FdInterestPayment', $payment->id, "Voided installment #{$payment->installment_no}", $userId);
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

        self::postCashLeg($till, '-' . $partPayment->net_amount_paid, $lines, [
            'txn_type' => 'CASH_OUT',
            'amount' => $partPayment->net_amount_paid,
            'entry_date' => $partPayment->paid_at,
            'branch_id' => $fd->branch_id,
            'source_type' => 'FdPartPayment',
            'source_id' => $partPayment->id,
            'idempotency_key' => "fd-part-payment-{$partPayment->id}",
            'description' => "FD part payment - {$fd->fd_number} ({$partPayment->voucher_no})",
            'posted_by' => $partPayment->created_by,
        ]);
    }

    /** Reverses a part payment's posting — called when a mistaken part payment is voided (FixedDepositController::voidPartPayment()). No-op if nothing was ever posted for it (Bank/Cheque). */
    public static function reversePartPayment(FdPartPayment $partPayment, ?int $userId = null): void
    {
        self::reverseVia('FdPartPayment', $partPayment->id, "Voided: {$partPayment->voucher_no}", $userId);
    }

    /**
     * Books one FD cash leg through the one money writer. $glLines is the full
     * balanced entry (till leg included); $delta is the signed till movement
     * ('+123.00' / '-123.00'); $ctx carries the shared spec fields.
     *
     * @param array{txn_type:string, amount:numeric, entry_date:mixed, branch_id:?int, source_type:string, source_id:int, idempotency_key:string, description:string, posted_by:?int} $ctx
     */
    private static function postCashLeg(CashAccount $till, string $delta, array $glLines, array $ctx): void
    {
        FinancePostingService::execute([
            'txn_type' => $ctx['txn_type'],
            'amount' => $ctx['amount'],
            'entry_date' => $ctx['entry_date'] instanceof \DateTimeInterface
                ? $ctx['entry_date']->format('Y-m-d')
                : (string) \Illuminate\Support\Carbon::parse($ctx['entry_date'])->toDateString(),
            'branch_id' => $ctx['branch_id'],
            'movements' => [['account_type' => 'cash', 'account_id' => (int) $till->id, 'delta' => $delta]],
            'gl_lines' => $glLines,
            'gl_source_type' => $ctx['source_type'],
            'gl_source_id' => $ctx['source_id'],
            'gl_description' => $ctx['description'],
            'description' => $ctx['description'],
            'source_module' => $ctx['source_type'],
            'source_id' => $ctx['source_id'],
            'idempotency_key' => $ctx['idempotency_key'],
            'executed_by' => $ctx['posted_by'],
        ]);
    }

    /**
     * Undoes a Cash-method FD leg. Prefers FinancePostingService::reverse()
     * (restores the till + posts the mirrored GL reversal + leaves a REVERSAL
     * row), falling back to a GL-only reversal for an FD leg posted before the
     * Finance posting service existed. No-op if nothing was ever posted.
     */
    private static function reverseVia(string $sourceType, int $sourceId, string $reason, ?int $userId): void
    {
        $txn = FinanceTransaction::where('source_module', $sourceType)
            ->where('source_id', $sourceId)
            ->whereNull('reversal_of_id')
            ->where('status', 'Completed')
            ->latest('id')
            ->first();
        if ($txn) {
            FinancePostingService::reverse($txn, $reason, $userId);

            return;
        }

        JournalEntry::where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('status', 'Posted')
            ->whereNull('reversal_of_id')
            ->get()
            ->each(fn (JournalEntry $entry) => LedgerPoster::reverse($entry, $reason, $userId));
    }
}
