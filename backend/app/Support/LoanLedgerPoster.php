<?php

namespace App\Support;

use App\Models\CashAccount;
use App\Models\ChartOfAccount;
use App\Models\FacilityPayment;
use App\Models\JournalEntry;
use App\Models\Loan;

/**
 * Bridges Loan Payments into the general ledger — the GL counterpart of
 * CashbookPoster, triggered from the same places, but answering "what does
 * the business now own/earn" rather than "how much is in the till."
 *
 * Scope, deliberately: only Cash-method receipts, Cheque realizations, and
 * (now) Cash-method disbursements are wired here. Each has an unambiguous
 * account to move (the branch's till, or 1030 Cheques/Undeposited Funds).
 * Bank-method loan payments/disbursements are still NOT posted —
 * FacilityPayment.bank_id is only ever set for a cheque, loan_facility_
 * details.disbursement_method captures Cash/Bank/Cheque but no
 * bank_account_id, so there is no unambiguous account to move for Bank
 * either way; inventing one here would just be a second kind of drift. Same
 * limitation SpeedDraftLedgerPoster/PawningLedgerPoster already carry for
 * disbursement — not a new gap, just extended to Loan for consistency.
 */
class LoanLedgerPoster
{
    /** Capital handed to the customer at disbursement — Dr 1100 (principal now outstanding), Cr the branch till. Cash-method only, see class docblock. */
    public static function postDisbursement(Loan $loan): void
    {
        if ($loan->loanDetail?->disbursement_method !== 'Cash') {
            return;
        }
        $till = CashAccount::branchPrimary((int) $loan->branch_id);
        if (!$till) {
            return;
        }
        $tillAccount = ChartOfAccount::forCashTill($till);
        $principalAccount = ChartOfAccount::system('1100');
        $entryDate = optional($loan->disbursed_at)->toDateString() ?? now()->toDateString();

        LedgerPoster::post([
            ['account_id' => $principalAccount->id, 'debit' => $loan->disbursed_amount, 'credit' => 0],
            ['account_id' => $tillAccount->id, 'debit' => 0, 'credit' => $loan->disbursed_amount],
        ], $entryDate, [
            'source_type' => 'Loan',
            'source_id' => $loan->id,
            'description' => "Loan disbursed - {$loan->code}",
            'branch_id' => $loan->branch_id,
            'posted_by' => $loan->approved_by,
        ]);
    }

    /**
     * A bad-debt write-off — allowance method: relieves whatever's already
     * been provisioned via 1190 first (Dr 1190/Cr 1100), since that portion
     * was already expensed when the provision was posted (see
     * LoanProvisioning::post()) and hitting 5300 again here would double-
     * count it. Only the uncovered remainder (e.g. not-yet-due installments,
     * which the arrears-based provision base never included) is expensed
     * directly (Dr 5300/Cr 1100), same as this method used to do for the
     * whole amount before provisioning existed. LoanController::writeOff()
     * gates on the provision already being fully posted before calling this.
     */
    public static function postWriteOff(Loan $loan, float $amount, ?int $userId = null): void
    {
        if ($amount <= 0) {
            return;
        }
        $fromProvision = min((float) $loan->provision_posted_amount, $amount);
        $direct = round($amount - $fromProvision, 2);

        $lines = [];
        if ($fromProvision > 0) {
            $lines[] = ['account_id' => ChartOfAccount::system('1190')->id, 'debit' => $fromProvision, 'credit' => 0];
        }
        if ($direct > 0) {
            $lines[] = ['account_id' => ChartOfAccount::system('5300')->id, 'debit' => $direct, 'credit' => 0];
        }
        $lines[] = ['account_id' => ChartOfAccount::system('1100')->id, 'debit' => 0, 'credit' => $amount];

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

    /** Reverses a Loan's disbursement entry — called when an Active-but-unpaid loan is cancelled (LoanController::approveCancelRequest() zeroes disbursed_amount/outstanding_balance_cache for exactly this case; the GL side needs the same undo). No-op if disbursement was never posted (Bank/Cheque method). */
    public static function reverseDisbursement(Loan $loan, ?int $userId = null): void
    {
        JournalEntry::where('source_type', 'Loan')
            ->where('source_id', $loan->id)
            ->where('status', 'Posted')
            ->whereNull('reversal_of_id')
            ->get()
            ->each(fn (JournalEntry $entry) => LedgerPoster::reverse($entry, "Cancelled: {$loan->code}", $userId));
    }

    /**
     * Cash received right now — a regular payment (already allocated to the
     * schedule/charges by the caller) or a settlement request (not allocated
     * yet, held in the 2100 suspense account until approved).
     */
    public static function postCashReceipt(FacilityPayment $payment): void
    {
        if ($payment->payment_method !== 'Cash') {
            return;
        }
        $till = CashAccount::branchPrimary((int) $payment->branch_id);
        if (!$till) {
            return;
        }
        $tillAccount = ChartOfAccount::forCashTill($till);
        $entryDate = optional($payment->paid_at)->toDateString() ?? now()->toDateString();

        // Settlement and ManualAllocation both hold the cash pending approval —
        // neither has been allocated to the schedule yet (see
        // LoanPaymentAllocator::applyAllocation()/applyManualAllocation()), so
        // there's nothing splitLines() below could credit yet.
        if (in_array($payment->payment_type, ['Settlement', 'ManualAllocation'], true)) {
            $suspense = ChartOfAccount::system('2100');
            $label = $payment->payment_type === 'Settlement' ? 'settlement' : 'manual allocation';
            LedgerPoster::post([
                ['account_id' => $tillAccount->id, 'debit' => $payment->amount, 'credit' => 0],
                ['account_id' => $suspense->id, 'debit' => 0, 'credit' => $payment->amount],
            ], $entryDate, self::meta($payment, "Cash receipt pending {$label} approval - {$payment->code}"));

            return;
        }

        $credits = self::splitLines($payment);
        if (!$credits) {
            return;
        }
        $lines = array_merge([['account_id' => $tillAccount->id, 'debit' => $payment->amount, 'credit' => 0]], $credits);
        LedgerPoster::post($lines, $entryDate, self::meta($payment, "Loan payment {$payment->code}"));
    }

    /** A pending settlement's cash moves from suspense into what it actually paid off — called once LoanPaymentAllocator::applyAllocation() has run at approval time. Cash-method only, matching postCashReceipt()'s original suspense entry. */
    public static function postSettlementApplied(FacilityPayment $payment): void
    {
        if ($payment->payment_method !== 'Cash') {
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
     *  Cash-method only, same shape as postSettlementApplied(). */
    public static function postManualAllocationApplied(FacilityPayment $payment): void
    {
        if ($payment->payment_method !== 'Cash') {
            return;
        }
        $credits = self::splitLines($payment);
        if (!$credits) {
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

    /** Reverses every posted journal entry this payment ever caused (one for a plain receipt, or two for an approved-then-voided settlement) — called wherever a payment is voided/rejected/bounced, mirroring CashbookPoster::reverseCashReceipt(). No-op if nothing was ever posted for it (Bank method, or a still-pending cheque). */
    public static function reverseAllFor(FacilityPayment $payment, ?int $userId = null): void
    {
        JournalEntry::where('source_type', 'LoanPayment')
            ->where('source_id', $payment->id)
            ->where('status', 'Posted')
            ->whereNull('reversal_of_id') // never re-reverse a reversal entry, even though it shares the same reference
            ->get()
            ->each(fn (JournalEntry $entry) => LedgerPoster::reverse($entry, "Voided: {$payment->code}", $userId));
    }

    /** The principal/interest/penalty/charges split this payment was actually allocated to, as credit lines — sourced from the allocation rows LoanPaymentAllocator just wrote, not from $payment->amount, so this can never post a figure the schedule doesn't agree with. Empty if nothing has been allocated yet (a pending settlement/cheque). */
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

        // Split by the charge's category account rather than one blanket
        // bucket — a category with no chart_of_account_id set yet (or a
        // charge with no category) still falls back to 4020, same as before
        // this split existed, instead of silently going nowhere.
        $chargesByAccount = $payment->chargeAllocations()
            ->with('charge.category:id,chart_of_account_id')
            ->get()
            ->groupBy(fn ($allocation) => $allocation->charge?->category?->chart_of_account_id ?? ChartOfAccount::system('4020')->id)
            ->map(fn ($group) => (float) $group->sum('amount_applied'));

        foreach ($chargesByAccount as $accountId => $amount) {
            if ($amount > 0) {
                $lines[] = ['account_id' => (int) $accountId, 'credit' => $amount];
            }
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
