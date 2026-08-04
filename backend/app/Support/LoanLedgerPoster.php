<?php

namespace App\Support;

use App\Models\CashAccount;
use App\Models\ChartOfAccount;
use App\Models\FacilityPayment;
use App\Models\JournalEntry;

/**
 * Bridges Loan Payments into the general ledger — the GL counterpart of
 * CashbookPoster, triggered from the same places, but answering "what does
 * the business now own/earn" rather than "how much is in the till."
 *
 * Scope, deliberately: only Cash-method receipts and Cheque realizations are
 * wired here. Both have an unambiguous account to debit (the branch's till,
 * or 1030 Cheques/Undeposited Funds). Bank-method loan payments are NOT
 * posted — FacilityPayment.bank_id is only ever set for a cheque, so there is
 * no unambiguous account to debit for Bank, and today's system doesn't move a
 * bank balance for one either; inventing one here would just be a second kind
 * of drift. Same reasoning applies to loan disbursement (no payment method is
 * even captured yet). Both are natural next steps once that data exists.
 */
class LoanLedgerPoster
{
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

        if ($payment->payment_type === 'Settlement') {
            $suspense = ChartOfAccount::system('2100');
            LedgerPoster::post([
                ['account_id' => $tillAccount->id, 'debit' => $payment->amount, 'credit' => 0],
                ['account_id' => $suspense->id, 'debit' => 0, 'credit' => $payment->amount],
            ], $entryDate, self::meta($payment, "Cash receipt pending settlement approval - {$payment->code}"));

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
        $charges = (float) $payment->chargeAllocations()->sum('amount_applied');

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
        if ($charges > 0) {
            $lines[] = ['account_id' => ChartOfAccount::system('4020')->id, 'credit' => $charges];
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
