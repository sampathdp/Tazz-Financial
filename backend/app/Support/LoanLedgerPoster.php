<?php

namespace App\Support;

use App\Models\BankAccount;
use App\Models\CashAccount;
use App\Models\ChartOfAccount;
use App\Models\FacilityPayment;
use App\Models\FinanceTransaction;
use App\Models\JournalEntry;
use App\Models\Loan;

/**
 * Bridges Loan Payments into the general ledger — the GL counterpart of
 * CashbookPoster, triggered from the same places, but answering "what does
 * the business now own/earn" rather than "how much is in the till."
 *
 * Scope: Cash-method receipts, Cheque realizations, and disbursements by
 * every method (Cash / Bank / Cheque). A Bank or Cheque disbursement credits
 * the bank account it was paid out of (loan_facility_details
 * .disbursement_bank_account_id, captured at disburse() time); a Cash one
 * credits the branch till. Bank-method loan *payments* are still NOT posted —
 * FacilityPayment carries no bank_account_id for a plain receipt.
 */
class LoanLedgerPoster
{
    /**
     * Capital handed to the customer at disbursement. Now goes through
     * FinancePostingService (the one money writer), so it both posts the GL
     * (Dr 1100 principal outstanding / Cr wherever the money left from — the
     * branch till for Cash, the selected bank account for Bank / Cheque) AND
     * moves that account's physical current_balance and records a
     * finance_transactions row. Before this, disbursement posted the GL only
     * and the till / bank running balance silently overstated cash by every
     * disbursement ever made.
     *
     * The journal entry is still keyed source_type='Loan' / source_id=loan
     * so LoanController::purge() and reverseDisbursement() find it unchanged.
     * Idempotent on "loan-disbursement-{id}" — a retried disburse call can
     * never pay twice.
     *
     * No-ops (same as before) when the target account can't be resolved: an
     * unknown method, a branch with no till, or a Bank/Cheque disbursement
     * with no bank account recorded (a pre-cutover loan).
     */
    public static function postDisbursement(Loan $loan, ?int $executedBy = null, ?int $cashAccountId = null): void
    {
        $detail = $loan->loanDetail;
        $method = $detail?->disbursement_method;
        $target = self::disbursementTarget(
            $method,
            (int) $loan->branch_id,
            $detail?->disbursement_bank_account_id ? (int) $detail->disbursement_bank_account_id : null,
            $cashAccountId,
        );
        if (!$target) {
            throw new \App\Exceptions\FinancePostingException('The paying cash or bank account could not be resolved for this disbursement, so nothing was recorded.');
        }
        $amount = (string) $loan->disbursed_amount;
        $entryDate = optional($loan->disbursed_at)->toDateString() ?? now()->toDateString();

        FinancePostingService::execute([
            'txn_type' => 'DISBURSEMENT',
            'amount' => $amount,
            'entry_date' => $entryDate,
            'branch_id' => $loan->branch_id,
            'movements' => [[
                'account_type' => $target['phys_type'],
                'account_id' => $target['phys_id'],
                'delta' => '-' . $amount,
            ]],
            'gl_lines' => [
                ['account_id' => ChartOfAccount::system('1100')->id, 'debit' => $amount, 'credit' => 0],
                ['account_id' => $target['leaf']->id, 'debit' => 0, 'credit' => $amount],
            ],
            'gl_source_type' => 'Loan',
            'gl_source_id' => $loan->id,
            'gl_description' => "Loan disbursed ({$method}) - {$loan->code}",
            'description' => "Loan disbursed ({$method}) - {$loan->code}",
            'source_module' => 'Loan',
            'source_id' => $loan->id,
            'idempotency_key' => "loan-disbursement-{$loan->id}",
            'executed_by' => $executedBy ?? $loan->approved_by,
        ]);
    }

    /**
     * The leaf account a disbursement's capital is credited to — kept for
     * SpeedDraftLedgerPoster, which still calls it directly. See
     * disbursementTarget() for the full (leaf + physical account) resolution.
     */
    public static function disbursementCreditAccount(?string $method, int $branchId, ?int $bankAccountId): ?ChartOfAccount
    {
        return self::disbursementTarget($method, $branchId, $bankAccountId)['leaf'] ?? null;
    }

    /**
     * Resolves both halves a disbursement needs: the chart-of-accounts leaf
     * to credit, and the physical cash/bank account whose balance moves.
     * Cash → the branch's primary till; Bank/Cheque → the chosen bank
     * account. Null (caller no-ops) when it can't be resolved.
     *
     * @return array{leaf: ChartOfAccount, phys_type: string, phys_id: int}|null
     */
    public static function disbursementTarget(?string $method, int $branchId, ?int $bankAccountId, ?int $cashAccountId = null): ?array
    {
        if ($method === 'Cash') {
            // An explicit till (chosen on the disburse form) wins; otherwise the branch's General till. (Loans are normally paid
            // out of the Head Office bank account - see LoanController::headOfficeBankError - so this is the legacy path.)
            $till = $cashAccountId ? CashAccount::where('branch_id', $branchId)->find($cashAccountId) : CashAccount::branchPrimary($branchId);

            return $till ? ['leaf' => ChartOfAccount::forCashTill($till), 'phys_type' => 'cash', 'phys_id' => (int) $till->id] : null;
        }
        if (in_array($method, ['Bank', 'Cheque'], true) && $bankAccountId) {
            $bank = BankAccount::find($bankAccountId);

            return $bank ? ['leaf' => ChartOfAccount::forBankAccount($bank), 'phys_type' => 'bank', 'phys_id' => (int) $bank->id] : null;
        }

        return null;
    }

    /**
     * A bad-debt write-off — allowance method. Only the loan's remaining
     * PRINCIPAL is relieved from 1100: unpaid interest / penalty / other
     * charges were never recognised as income or booked to a receivable (this
     * ledger recognises them only as collected — see splitLines()), so writing
     * the loan off simply means they are never collected, exactly like a
     * waiver of unrecognised income — nothing to post for them.
     *
     * Of that principal, whatever's already been provisioned is relieved
     * against 1190 first (Dr 1190/Cr 1100), since that portion was already
     * expensed when the provision was posted (LoanProvisioning::post()) and
     * hitting 5300 again would double-count it. The uncovered remainder is
     * expensed directly (Dr 5300/Cr 1100).
     *
     * `$amount` is the loan's all-in outstanding (what the caller passes) but
     * is used here only as an upper bound / short-circuit — the principal to
     * write off is recomputed from the schedule (LoanPaymentAllocator).
     */
    public static function postWriteOff(Loan $loan, float $amount, ?int $userId = null): void
    {
        if ($amount <= 0) {
            return;
        }
        $principal = round((float) LoanPaymentAllocator::outstandingByBucket($loan)['capital'], 2);
        if ($principal <= 0) {
            return;
        }
        $fromProvision = min((float) $loan->provision_posted_amount, $principal);
        $direct = round($principal - $fromProvision, 2);

        $lines = [];
        if ($fromProvision > 0) {
            $lines[] = ['account_id' => ChartOfAccount::system('1190')->id, 'debit' => $fromProvision, 'credit' => 0];
        }
        if ($direct > 0) {
            $lines[] = ['account_id' => ChartOfAccount::system('5300')->id, 'debit' => $direct, 'credit' => 0];
        }
        $lines[] = ['account_id' => ChartOfAccount::system('1100')->id, 'debit' => 0, 'credit' => $principal];

        LedgerPoster::post($lines, now()->toDateString(), [
            'source_type' => 'Loan',
            'source_id' => $loan->id,
            'description' => "Loan written off - {$loan->code}",
            'branch_id' => $loan->branch_id,
            'posted_by' => $userId,
        ]);
    }

    /**
     * Builds up (delta > 0: Dr 5300 Provision Expense / Cr 1190 contra-asset)
     * or releases (delta < 0: Dr 1190 / Cr 4050 Bad Debt Recovery Income) the
     * impairment provision — see LoanProvisioning::post(), the only caller.
     * A release deliberately does NOT credit back into 5300: the loan's
     * arrears dropping (always because a payment landed — see
     * LoanPaymentAllocator::finalizeApplied()) is a cash recovery against
     * an already-expensed balance, which the business books as its own P&L
     * gain (4050) rather than a smaller net provision expense, so recoveries
     * stay visible next to the impairment charge they offset instead of
     * silently shrinking it.
     */
    public static function postProvisionAdjustment(Loan $loan, float $delta, ?int $userId = null): void
    {
        if (abs($delta) <= 0.01) {
            return;
        }
        $amount = abs($delta);
        $contraAsset = ChartOfAccount::system('1190')->id;

        if ($delta > 0) {
            $expense = ChartOfAccount::system('5300')->id;
            $lines = [['account_id' => $expense, 'debit' => $amount, 'credit' => 0], ['account_id' => $contraAsset, 'debit' => 0, 'credit' => $amount]];
        } else {
            $recoveryIncome = ChartOfAccount::system('4050')->id;
            $lines = [['account_id' => $contraAsset, 'debit' => $amount, 'credit' => 0], ['account_id' => $recoveryIncome, 'debit' => 0, 'credit' => $amount]];
        }

        LedgerPoster::post($lines, now()->toDateString(), [
            'source_type' => 'LoanProvision',
            'source_id' => $loan->id,
            'description' => ($delta > 0 ? 'Provision posted' : 'Bad debt recovery') . " - {$loan->code}",
            'branch_id' => $loan->branch_id,
            'posted_by' => $userId,
        ]);
    }

    /**
     * The GL side of a loan reschedule (see LoanController::reschedule()).
     * No cash moves: the old loan's capital already sits in 1100 and simply
     * carries to the new loan, so there is no disbursement entry.
     *
     * The one entry posted here recognises the amounts capitalised into the
     * new loan's principal — but ONLY amounts that are already EARNED, keeping
     * to this ledger's "interest recognised as collected" rule:
     *   - $capitalisedInterest is the earned/overdue slice of the old
     *     schedule's unpaid interest (the caller writes off the unearned
     *     future portion — no entry, same as any interest waiver here);
     *   - retained OD and unpaid charges are for penalties incurred / services
     *     rendered, i.e. also already earned.
     * Recognising them now is a catch-up: they were contractually due and are
     * now a firm, re-documented receivable.
     *
     *   Dr 1100  (capitalised interest + retained OD + rolled/added charges)
     *   Cr 4010  capitalised earned interest
     *   Cr 4030  retained default charges (OD)
     *   Cr 4020  other charges rolled in + manually added charges
     *
     * If nothing is being capitalised (a pure term extension with everything
     * waived), there is nothing to post.
     */
    public static function postRescheduleRollover(
        Loan $oldLoan,
        Loan $newLoan,
        float $capitalisedInterest,
        float $retainedOd,
        float $rolledAndAddedCharges,
        string $entryDate,
        ?int $userId = null
    ): void {
        $capitalisedInterest = round(max(0, $capitalisedInterest), 2);
        $retainedOd = round(max(0, $retainedOd), 2);
        $rolledAndAddedCharges = round(max(0, $rolledAndAddedCharges), 2);
        $total = round($capitalisedInterest + $retainedOd + $rolledAndAddedCharges, 2);
        if ($total <= 0.01) {
            return;
        }

        $lines = [['account_id' => ChartOfAccount::system('1100')->id, 'debit' => $total, 'credit' => 0]];
        if ($capitalisedInterest > 0) {
            $lines[] = ['account_id' => ChartOfAccount::system('4010')->id, 'debit' => 0, 'credit' => $capitalisedInterest];
        }
        if ($retainedOd > 0) {
            $lines[] = ['account_id' => ChartOfAccount::system('4030')->id, 'debit' => 0, 'credit' => $retainedOd];
        }
        if ($rolledAndAddedCharges > 0) {
            $lines[] = ['account_id' => ChartOfAccount::system('4020')->id, 'debit' => 0, 'credit' => $rolledAndAddedCharges];
        }

        LedgerPoster::post($lines, $entryDate, [
            'source_type' => 'LoanReschedule',
            'source_id' => $newLoan->id,
            'description' => "Loan rescheduled - {$oldLoan->code} to {$newLoan->code}",
            'branch_id' => $newLoan->branch_id,
            'posted_by' => $userId,
        ]);
    }

    /**
     * Reverses a Loan's disbursement — called when an Active-but-unpaid loan
     * is cancelled (LoanController::approveCancelRequest() zeroes
     * disbursed_amount/outstanding_balance_cache for exactly this case). Goes
     * through FinancePostingService::reverse(), which restores the till/bank
     * current_balance AND posts the mirrored GL entry, then leaves a linked
     * REVERSAL finance_transactions row. Falls back to a GL-only reversal for
     * a loan disbursed before the Finance posting service existed. No-op if
     * disbursement was never recorded at all.
     */
    public static function reverseDisbursement(Loan $loan, ?int $userId = null): void
    {
        $txn = FinanceTransaction::where('source_module', 'Loan')
            ->where('source_id', $loan->id)
            ->where('txn_type', 'DISBURSEMENT')
            ->where('status', 'Completed')
            ->latest('id')
            ->first();
        if ($txn) {
            FinancePostingService::reverse($txn, "Cancelled: {$loan->code}", $userId);

            return;
        }

        JournalEntry::where('source_type', 'Loan')
            ->where('source_id', $loan->id)
            ->where('status', 'Posted')
            ->whereNull('reversal_of_id')
            ->get()
            ->each(fn (JournalEntry $entry) => LedgerPoster::reverse($entry, "Cancelled: {$loan->code}", $userId));
    }

    /**
     * A payment received right now:
     *   - Cash: through FinancePostingService (txn_type COLLECTION) — the
     *     branch till's current_balance moves, a finance_transactions row is
     *     recorded and a cashbook_entries mirror is written, atomic with the GL.
     *   - Bank: a GL-only entry crediting the schedule split against 1030
     *     Cheques / Undeposited Funds — the bank transfer has cleared but no
     *     company bank_accounts row is captured on a plain receipt, so the
     *     funds sit in 1030 until a Bank Deposit (contra = Ledger 1030) moves
     *     them into the right bank account. Same landing account a realized
     *     cheque uses (postChequeRealized).
     *   - Cheque: nothing here — deferred to postChequeRealized() once it clears.
     *
     * A regular payment is already allocated to the schedule/charges by the
     * caller; a settlement / manual-allocation request is not allocated yet and
     * is parked in 2100 suspense until approved (see LoanPaymentAllocator).
     */
    public static function postCashReceipt(FacilityPayment $payment): void
    {
        $isPending = in_array($payment->payment_type, ['Settlement', 'ManualAllocation'], true);
        $label = $payment->payment_type === 'Settlement' ? 'settlement' : 'manual allocation';

        if ($payment->payment_method === 'Bank') {
            $credits = $isPending
                ? [['account_id' => ChartOfAccount::system('2100')->id, 'credit' => (string) $payment->amount]]
                : self::splitLines($payment);
            if (!$credits) {
                return;
            }
            $undeposited = ChartOfAccount::system('1030');
            $entryDate = optional($payment->paid_at)->toDateString() ?? now()->toDateString();
            $lines = array_merge(
                [['account_id' => $undeposited->id, 'debit' => (string) $payment->amount, 'credit' => 0]],
                array_map(fn ($l) => ['account_id' => $l['account_id'], 'debit' => 0, 'credit' => $l['credit']], $credits),
            );
            LedgerPoster::post($lines, $entryDate, self::meta(
                $payment,
                $isPending ? "Bank receipt pending {$label} approval - {$payment->code}" : "Loan payment (Bank) {$payment->code}",
            ));

            return;
        }

        if ($payment->payment_method !== 'Cash') {
            return;
        }
        // Cash goes into the till of the branch that took it (the loan's own branch unless collected elsewhere).
        $till = CashAccount::branchPrimary((int) ($payment->collected_branch_id ?: $payment->branch_id), self::receiptPurpose($payment->facility));
        if (!$till) {
            throw new \App\Exceptions\FinancePostingException('This branch has no active cash account, so the cash movement cannot be recorded. Add a till under Cashbook > Accounts first.');
        }

        if ($isPending) {
            self::postCollection($payment, $till, [
                ['account_id' => ChartOfAccount::system('2100')->id, 'credit' => (string) $payment->amount],
            ], "Cash receipt pending {$label} approval - {$payment->code}");

            return;
        }

        $credits = self::splitLines($payment);
        if ($credits) {
            self::postCollection($payment, $till, $credits, "Loan payment {$payment->code}");
        }
    }

    /** Which till a loan receipt belongs in: before disbursement the only thing collected is the document charge. */
    public static function receiptPurpose(?Loan $loan): string
    {
        return $loan && $loan->status === 'Approved' ? CashAccount::PURPOSE_DOCUMENT_CHARGES : CashAccount::PURPOSE_COLLECTIONS;
    }

    /** Books a cash collection through the one money writer. $credits is the Cr side (each ['account_id'=>, 'credit'=>]); the till Dr and the +movement are added here. */
    private static function postCollection(FacilityPayment $payment, CashAccount $till, array $credits, string $description): void
    {
        $amount = (string) $payment->amount;
        $tillLeaf = ChartOfAccount::forCashTill($till);
        $entryDate = optional($payment->paid_at)->toDateString() ?? now()->toDateString();

        FinancePostingService::execute([
            'txn_type' => 'COLLECTION',
            'amount' => $amount,
            'entry_date' => $entryDate,
            'branch_id' => $payment->branch_id,
            'movements' => [['account_type' => 'cash', 'account_id' => (int) $till->id, 'delta' => $amount]],
            'gl_lines' => array_merge(
                [['account_id' => $tillLeaf->id, 'debit' => $amount, 'credit' => 0]],
                array_map(fn ($l) => ['account_id' => $l['account_id'], 'debit' => 0, 'credit' => $l['credit']], $credits),
            ),
            'gl_source_type' => 'LoanPayment',
            'gl_source_id' => $payment->id,
            'gl_description' => $description,
            'description' => $description,
            'source_module' => 'LoanPayment',
            'source_id' => $payment->id,
            'idempotency_key' => "loan-payment-{$payment->id}",
            'executed_by' => $payment->received_by,
        ]);
    }

    /** A pending settlement's receipt moves from 2100 suspense into what it actually paid off — called once LoanPaymentAllocator::applyAllocation() has run at approval time. The reclass (Dr 2100 / Cr split) is method-agnostic; only Cheque defers (postChequeRealized). */
    public static function postSettlementApplied(FacilityPayment $payment): void
    {
        if (!in_array($payment->payment_method, ['Cash', 'Bank'], true)) {
            return;
        }
        $credits = self::splitLines($payment);
        if (!$credits) {
            return;
        }
        $suspense = ChartOfAccount::system('2100');
        $lines = array_merge([['account_id' => $suspense->id, 'debit' => $payment->amount, 'credit' => 0]], $credits);
        LedgerPoster::post($lines, now()->toDateString(), self::meta($payment, "Settlement applied {$payment->code}"));
    }

    /** A pending manual-allocation payment's cash moves from suspense into
     *  what it was actually allocated to — called once
     *  LoanPaymentAllocator::applyManualAllocation() has run at approval time.
     *  Same shape as postSettlementApplied(). */
    public static function postManualAllocationApplied(FacilityPayment $payment): void
    {
        $credits = self::splitLines($payment);
        if (!$credits) {
            return;
        }
        // A cheque was never parked in 2100 (postCashReceipt posts nothing for one), but approval has
        // already applied it to the schedule — so book it now the way postChequeRealized() would,
        // otherwise the instalment shows paid with no receipt and no income in the ledger.
        if ($payment->payment_method === 'Cheque') {
            $lines = array_merge([['account_id' => ChartOfAccount::system('1030')->id, 'debit' => $payment->amount, 'credit' => 0]], $credits);
            LedgerPoster::post($lines, now()->toDateString(), self::meta($payment, "Cheque allocation applied {$payment->code}"));

            return;
        }
        if (!in_array($payment->payment_method, ['Cash', 'Bank'], true)) {
            return;
        }
        $suspense = ChartOfAccount::system('2100');
        $lines = array_merge([['account_id' => $suspense->id, 'debit' => $payment->amount, 'credit' => 0]], $credits);
        LedgerPoster::post($lines, now()->toDateString(), self::meta($payment, "Manual allocation applied {$payment->code}"));
    }

    /** A Cheque-method payment's amount, once realized — called after LoanPaymentAllocator::applyAllocation() has run, from both the manual clear-cheque flow and post-dated-cheque realization. Lands in 1030 (not a specific bank account) since neither flow captures which bank account it was actually deposited into. */
    public static function postChequeRealized(FacilityPayment $payment): void
    {
        $credits = self::splitLines($payment);
        if (!$credits) {
            return;
        }
        $undeposited = ChartOfAccount::system('1030');
        $entryDate = optional($payment->realized_at)->toDateString() ?? now()->toDateString();
        $lines = array_merge([['account_id' => $undeposited->id, 'debit' => $payment->amount, 'credit' => 0]], $credits);
        LedgerPoster::post($lines, $entryDate, self::meta($payment, "Cheque realized {$payment->code}"));
    }

    /**
     * Reverses everything this payment ever caused — called wherever a payment
     * is voided/rejected/bounced. The cash-receipt COLLECTION transaction goes
     * back through FinancePostingService::reverse() (restores the till +
     * mirrors the GL + cashbook); any other posted LoanPayment journal entry
     * (a settlement/manual-allocation reclass) is reversed on the GL directly.
     * No-op if nothing was ever posted (Bank method, or a still-pending cheque).
     */
    public static function reverseAllFor(FacilityPayment $payment, ?int $userId = null): void
    {
        $collection = FinanceTransaction::where('source_module', 'LoanPayment')
            ->where('source_id', $payment->id)
            ->where('txn_type', 'COLLECTION')
            ->where('status', 'Completed')
            ->latest('id')
            ->first();
        if ($collection) {
            FinancePostingService::reverse($collection, "Voided: {$payment->code}", $userId);
        }

        JournalEntry::where('source_type', 'LoanPayment')
            ->where('source_id', $payment->id)
            ->where('status', 'Posted')
            ->whereNull('reversal_of_id')
            ->get()
            ->each(fn (JournalEntry $entry) => LedgerPoster::reverse($entry, "Voided: {$payment->code}", $userId));
    }

    /** The principal/interest/penalty/charges split this payment was actually allocated to, as credit lines — sourced from the allocation rows LoanPaymentAllocator just wrote, not from $payment->amount, so this can never post a figure the schedule doesn't agree with. Empty if nothing has been allocated yet (a pending settlement/cheque). */
    /** The credit side a payment was allocated to (principal / interest / penalty / charges), for callers that build their own balanced entry - e.g. an FD adjusted against its loan. */
    public static function creditLinesFor(FacilityPayment $payment): array
    {
        return self::splitLines($payment);
    }

    private static function splitLines(FacilityPayment $payment): array
    {
        $principal = (float) $payment->allocations()->sum('principal_applied');
        $interest = (float) $payment->allocations()->sum('interest_applied');
        $penalty = (float) $payment->allocations()->sum('penalty_applied');

        $lines = [];
        if ($principal > 0) {
            $lines[] = ['account_id' => ChartOfAccount::system('1100')->id, 'credit' => $principal];
        }
        if ($interest > 0) {
            $lines[] = ['account_id' => ChartOfAccount::system('4010')->id, 'credit' => $interest];
        }
        if ($penalty > 0) {
            $lines[] = ['account_id' => ChartOfAccount::system('4030')->id, 'credit' => $penalty];
        }

        // All "Other Charges" collected on a loan are Documentation Income
        // (4910), whatever the charge category is mapped to. 4020 is only the
        // fallback on a chart that has no 4910 account.
        $charges = (float) $payment->chargeAllocations()->sum('amount_applied');
        if ($charges > 0) {
            $chargeAccount = ChartOfAccount::where('code', '4910')->value('id') ?? ChartOfAccount::system('4020')->id;
            $lines[] = ['account_id' => (int) $chargeAccount, 'credit' => $charges];
        }

        return $lines;
    }

    private static function meta(FacilityPayment $payment, string $description): array
    {
        return [
            'source_type' => 'LoanPayment',
            'source_id' => $payment->id,
            'description' => $description,
            'branch_id' => $payment->branch_id,
            'posted_by' => $payment->received_by,
        ];
    }
}
