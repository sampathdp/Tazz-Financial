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
 * Scope, deliberately: Cash-method only, same reasoning as LoanLedgerPoster —
 * Bank-method disbursement/receipts have no unambiguous account to move
 * without a captured bank_account_id, which this module doesn't have yet.
 * Unlike Loan (whose disbursement is never posted at all), disbursing a Speed
 * Draft's capital *is* posted here — it's the module's central money-out
 * event, not an incidental gap.
 */
class SpeedDraftLedgerPoster
{
    /** Capital handed to the customer at disbursement — Dr 1110 (capital now outstanding), Cr the branch till. */
    public static function postDisbursement(SpeedDraft $draft): void
    {
        if ($draft->speedDraftDetail?->disbursement_method !== 'Cash') {
            return;
        }
        $till = CashAccount::branchPrimary((int) $draft->branch_id);
        if (!$till) {
            return;
        }
        $tillAccount = ChartOfAccount::forCashTill($till);
        $capitalAccount = ChartOfAccount::system('1110');
        $entryDate = optional($draft->disbursed_at)->toDateString() ?? now()->toDateString();

        LedgerPoster::post([
            ['account_id' => $capitalAccount->id, 'debit' => $draft->disbursed_amount, 'credit' => 0],
            ['account_id' => $tillAccount->id, 'debit' => 0, 'credit' => $draft->disbursed_amount],
        ], $entryDate, [
            'source_type' => 'SpeedDraft',
            'source_id' => $draft->id,
            'description' => "Speed Draft disbursed - {$draft->code}",
            'branch_id' => $draft->branch_id,
            'posted_by' => $draft->approved_by,
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
