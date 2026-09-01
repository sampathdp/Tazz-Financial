<?php

namespace App\Support;

use App\Models\CashAccount;
use App\Models\ChartOfAccount;
use App\Models\FacilityPayment;
use App\Models\JournalEntry;
use App\Models\SpeedDraft;

/**
 * Bridges Speed Drafts into the general ledger — the Speed Draft counterpart
 * of LoanLedgerPoster, reusing the same LedgerPoster/ChartOfAccount/
 * CashbookPoster primitives rather than reinventing posting.
 *
 * Scope: Cash-method receipts and disbursements by Cash or Bank. A Bank
 * disbursement credits the account it was paid out of (speed_draft_facility_
 * details.disbursement_bank_account_id, captured at disburse() time); a Cash
 * one credits the branch till. Bank-method receipts are still NOT posted —
 * a plain FacilityPayment carries no bank account.
 */
class SpeedDraftLedgerPoster
{
    /**
     * Capital handed to the customer at disbursement. Routes through
     * FinancePostingService (the one money writer) exactly like
     * LoanLedgerPoster::postDisbursement — posts the GL (Dr 1110 capital
     * outstanding / Cr the branch till for Cash, or the chosen bank account
     * for Bank / Cheque) AND moves that account's physical current_balance,
     * with a finance_transactions row. The journal entry stays
     * source_type='SpeedDraft' / source_id=draft. Idempotent per draft.
     */
    public static function postDisbursement(SpeedDraft $draft, ?int $executedBy = null): void
    {
        $detail = $draft->speedDraftDetail;
        $method = $detail?->disbursement_method;
        $target = LoanLedgerPoster::disbursementTarget(
            $method,
            (int) $draft->branch_id,
            $detail?->disbursement_bank_account_id ? (int) $detail->disbursement_bank_account_id : null,
        );
        if (!$target) {
            return;
        }
        $amount = (string) $draft->disbursed_amount;
        $entryDate = optional($draft->disbursed_at)->toDateString() ?? now()->toDateString();

        FinancePostingService::execute([
            'txn_type' => 'DISBURSEMENT',
            'amount' => $amount,
            'entry_date' => $entryDate,
            'branch_id' => $draft->branch_id,
            'movements' => [[
                'account_type' => $target['phys_type'],
                'account_id' => $target['phys_id'],
                'delta' => '-' . $amount,
            ]],
            'gl_lines' => [
                ['account_id' => ChartOfAccount::system('1110')->id, 'debit' => $amount, 'credit' => 0],
                ['account_id' => $target['leaf']->id, 'debit' => 0, 'credit' => $amount],
            ],
            'gl_source_type' => 'SpeedDraft',
            'gl_source_id' => $draft->id,
            'gl_description' => "Speed Draft disbursed ({$method}) - {$draft->code}",
            'description' => "Speed Draft disbursed ({$method}) - {$draft->code}",
            'source_module' => 'SpeedDraft',
            'source_id' => $draft->id,
            'idempotency_key' => "speed-draft-disbursement-{$draft->id}",
            'executed_by' => $executedBy ?? $draft->approved_by,
        ]);
    }

    /** An interest payment received in cash — Dr till, Cr 4015, sourced from the allocation rows just written. */
    public static function postInterestReceipt(FacilityPayment $payment): void
    {
        if ($payment->payment_method !== 'Cash') {
            return;
        }
        $interest = (float) $payment->allocations()->sum('interest_applied');
        if ($interest <= 0) {
            return;
        }
        $till = CashAccount::branchPrimary((int) $payment->branch_id);
        if (!$till) {
            return;
        }
        $tillAccount = ChartOfAccount::forCashTill($till);
        $income = ChartOfAccount::system('4015');
        $entryDate = optional($payment->paid_at)->toDateString() ?? now()->toDateString();

        LedgerPoster::post([
            ['account_id' => $tillAccount->id, 'debit' => $interest, 'credit' => 0],
            ['account_id' => $income->id, 'debit' => 0, 'credit' => $interest],
        ], $entryDate, self::meta($payment, "Speed Draft interest received - {$payment->code}"));
    }

    /** A penalty payment received in cash — Dr till, Cr 4030 (Penalty Income — shared with Loan, not a Speed-Draft-specific account). */
    public static function postPenaltyReceipt(FacilityPayment $payment): void
    {
        if ($payment->payment_method !== 'Cash') {
            return;
        }
        $penalty = (float) $payment->allocations()->sum('penalty_applied');
        if ($penalty <= 0) {
            return;
        }
        $till = CashAccount::branchPrimary((int) $payment->branch_id);
        if (!$till) {
            return;
        }
        $tillAccount = ChartOfAccount::forCashTill($till);
        $income = ChartOfAccount::system('4030');
        $entryDate = optional($payment->paid_at)->toDateString() ?? now()->toDateString();

        LedgerPoster::post([
            ['account_id' => $tillAccount->id, 'debit' => $penalty, 'credit' => 0],
            ['account_id' => $income->id, 'debit' => 0, 'credit' => $penalty],
        ], $entryDate, self::meta($payment, "Speed Draft penalty received - {$payment->code}"));
    }

    /**
     * Non-cash income recognition for whatever interest/penalty a renewal
     * capitalized into the new draft's principal instead of collecting in
     * cash (see SpeedDraftController::renew()) — Dr 1110 (the new draft's
     * capital just grew by this much), Cr 4015/4030. Unlike every other
     * poster here, this has no FacilityPayment to source from (nothing was
     * actually paid) — it's what makes the books "proceed as if the interest
     * was collected" per the renewal requirement, rather than that income
     * silently vanishing when a customer can't pay in cash.
     */
    public static function postCapitalizedRenewalIncome(SpeedDraft $newDraft, float $interestAmount, float $penaltyAmount, string $entryDate, ?int $postedBy, int $renewalId): void
    {
        $capitalAccount = ChartOfAccount::system('1110');
        $lines = [];
        if ($interestAmount > 0) {
            $lines[] = ['account_id' => ChartOfAccount::system('4015')->id, 'debit' => 0, 'credit' => $interestAmount];
        }
        if ($penaltyAmount > 0) {
            $lines[] = ['account_id' => ChartOfAccount::system('4030')->id, 'debit' => 0, 'credit' => $penaltyAmount];
        }
        if (empty($lines)) {
            return;
        }
        $total = round($interestAmount + $penaltyAmount, 2);

        LedgerPoster::post([
            ['account_id' => $capitalAccount->id, 'debit' => $total, 'credit' => 0],
            ...$lines,
        ], $entryDate, [
            'source_type' => 'SpeedDraftRenewal',
            'source_id' => $renewalId,
            'description' => "Speed Draft renewal - capitalized interest/penalty - {$newDraft->code}",
            'branch_id' => $newDraft->branch_id,
            'posted_by' => $postedBy,
        ]);
    }

    /** A capital-reduction (or the capital portion of a settlement) received in cash — Dr till, Cr 1110. */
    public static function postCapitalReceipt(FacilityPayment $payment): void
    {
        if ($payment->payment_method !== 'Cash') {
            return;
        }
        $capital = (float) $payment->allocations()->sum('principal_applied');
        if ($capital <= 0) {
            return;
        }
        $till = CashAccount::branchPrimary((int) $payment->branch_id);
        if (!$till) {
            return;
        }
        $tillAccount = ChartOfAccount::forCashTill($till);
        $capitalAccount = ChartOfAccount::system('1110');
        $entryDate = optional($payment->paid_at)->toDateString() ?? now()->toDateString();

        LedgerPoster::post([
            ['account_id' => $tillAccount->id, 'debit' => $capital, 'credit' => 0],
            ['account_id' => $capitalAccount->id, 'debit' => 0, 'credit' => $capital],
        ], $entryDate, self::meta($payment, "Speed Draft capital received - {$payment->code}"));
    }

    /** Reverses every posted journal entry this payment ever caused — called when a payment is voided. */
    public static function reverseAllFor(FacilityPayment $payment, ?int $userId = null): void
    {
        JournalEntry::where('source_type', 'SpeedDraftPayment')
            ->where('source_id', $payment->id)
            ->where('status', 'Posted')
            ->whereNull('reversal_of_id')
            ->get()
            ->each(fn (JournalEntry $entry) => LedgerPoster::reverse($entry, "Voided: {$payment->code}", $userId));
    }

    private static function meta(FacilityPayment $payment, string $description): array
    {
        return [
            'source_type' => 'SpeedDraftPayment',
            'source_id' => $payment->id,
            'description' => $description,
            'branch_id' => $payment->branch_id,
            'posted_by' => $payment->received_by,
        ];
    }
}
