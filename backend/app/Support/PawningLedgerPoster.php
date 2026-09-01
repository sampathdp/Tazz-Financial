<?php

namespace App\Support;

use App\Models\CashAccount;
use App\Models\ChartOfAccount;
use App\Models\FacilityPayment;
use App\Models\JournalEntry;
use App\Models\Pawning;
use App\Models\PawningAuctionItem;

/**
 * Bridges Pawning into the general ledger — same shape as
 * SpeedDraftLedgerPoster, reusing the same LedgerPoster/ChartOfAccount
 * primitives. Cash-only, same reasoning as Loan/SpeedDraft: a Bank-method
 * disbursement/receipt has no unambiguous account to move without a
 * captured bank_account_id, which this module doesn't have. Unlike
 * SpeedDraft/Loan, Pawning never guards on a `disbursement_method` column
 * because there isn't one to guard on — a ticket is issued and disbursed in
 * a single store() call (see PawningController's docblock), never via a
 * separate approve-then-choose-method step, so it's cash-only by how the
 * counter workflow itself is shaped, not a runtime possibility this class
 * needs to branch on.
 *
 * postAuctionSale/postSurplusRefund/postSurplusForfeiture cover the default
 * side (see PawningAuctionController) — these are NOT reversible the way a
 * payment is (reverseAllFor is payment-only): once a lot is sold at
 * auction there is no "void the sale" workflow, matching the real-world
 * finality of an auction.
 */
class PawningLedgerPoster
{
    /**
     * Capital handed to the customer when a ticket is drawn. Routes through
     * FinancePostingService (the one money writer) — posts the GL (Dr 1120
     * capital outstanding / Cr the branch till) AND moves the till's physical
     * current_balance, with a finance_transactions row. Cash only, always
     * from the branch till (a pawning ticket is issued and disbursed over the
     * counter in one step). Journal entry stays source_type='Pawning'.
     */
    public static function postDisbursement(Pawning $pawning, ?int $executedBy = null): void
    {
        $till = CashAccount::branchPrimary((int) $pawning->branch_id);
        if (!$till) {
            return;
        }
        $amount = (string) $pawning->disbursed_amount;
        $entryDate = optional($pawning->disbursed_at)->toDateString() ?? now()->toDateString();

        FinancePostingService::execute([
            'txn_type' => 'DISBURSEMENT',
            'amount' => $amount,
            'entry_date' => $entryDate,
            'branch_id' => $pawning->branch_id,
            'movements' => [['account_type' => 'cash', 'account_id' => (int) $till->id, 'delta' => '-' . $amount]],
            'gl_lines' => [
                ['account_id' => ChartOfAccount::system('1120')->id, 'debit' => $amount, 'credit' => 0],
                ['account_id' => ChartOfAccount::forCashTill($till)->id, 'debit' => 0, 'credit' => $amount],
            ],
            'gl_source_type' => 'Pawning',
            'gl_source_id' => $pawning->id,
            'gl_description' => "Pawning ticket drawn - {$pawning->code}",
            'description' => "Pawning ticket drawn - {$pawning->code}",
            'source_module' => 'Pawning',
            'source_id' => $pawning->id,
            'idempotency_key' => "pawning-disbursement-{$pawning->id}",
            'executed_by' => $executedBy ?? $pawning->created_by,
        ]);
    }

    /** An interest payment (renewal or the interest portion of a redemption) received in cash — Dr till, Cr 4025. */
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
        $income = ChartOfAccount::system('4025');
        $entryDate = optional($payment->paid_at)->toDateString() ?? now()->toDateString();

        LedgerPoster::post([
            ['account_id' => $tillAccount->id, 'debit' => $interest, 'credit' => 0],
            ['account_id' => $income->id, 'debit' => 0, 'credit' => $interest],
        ], $entryDate, self::meta($payment, "Pawning interest received - {$payment->code}"));
    }

    /** A capital reduction (or the capital portion of a redemption) received in cash — Dr till, Cr 1120. */
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
        $capitalAccount = ChartOfAccount::system('1120');
        $entryDate = optional($payment->paid_at)->toDateString() ?? now()->toDateString();

        LedgerPoster::post([
            ['account_id' => $tillAccount->id, 'debit' => $capital, 'credit' => 0],
            ['account_id' => $capitalAccount->id, 'debit' => 0, 'credit' => $capital],
        ], $entryDate, self::meta($payment, "Pawning capital received - {$payment->code}"));
    }

    /**
     * A lot's hammer price, split by a strict waterfall so the entry always
     * balances regardless of whether proceeds cover the debt, land short, or
     * run over: capital is recovered first (any shortfall debited to 1190,
     * the same provision account Loan already uses for uncollectable
     * balances — this is the same kind of loss, not a new category),
     * interest is recognised only out of what's left after capital (never
     * more than what was actually quoted at sale time), and anything beyond
     * the full debt is credited to 2110 as owed back to the pledger, not
     * kept as income. All three outcomes reduce to the same six-line shape;
     * zero-amount legs are simply omitted rather than branched on.
     */
    public static function postAuctionSale(PawningAuctionItem $item, ?int $userId = null): void
    {
        $pawning = Pawning::find($item->credit_facility_id);
        if (!$pawning) {
            return;
        }
        $till = CashAccount::branchPrimary((int) $pawning->branch_id);
        if (!$till) {
            return;
        }
        $proceeds = (float) $item->hammer_price;
        if ($proceeds <= 0) {
            return;
        }
        $capital = (float) $item->outstanding_capital;
        $interest = (float) $item->outstanding_interest;

        $capitalRecovered = min($capital, $proceeds);
        $capitalShortfall = round($capital - $capitalRecovered, 2);
        $interestRecovered = min($interest, max(0, round($proceeds - $capitalRecovered, 2)));
        $surplus = max(0, round($proceeds - $capital - $interest, 2));

        $tillAccount = ChartOfAccount::forCashTill($till);
        $entryDate = optional($item->sold_at)->toDateString() ?? now()->toDateString();

        $lines = [['account_id' => $tillAccount->id, 'debit' => $proceeds, 'credit' => 0]];
        if ($capitalShortfall > 0) {
            $lines[] = ['account_id' => ChartOfAccount::system('1190')->id, 'debit' => $capitalShortfall, 'credit' => 0];
        }
        if ($capital > 0) {
            $lines[] = ['account_id' => ChartOfAccount::system('1120')->id, 'debit' => 0, 'credit' => $capital];
        }
        if ($interestRecovered > 0) {
            $lines[] = ['account_id' => ChartOfAccount::system('4025')->id, 'debit' => 0, 'credit' => $interestRecovered];
        }
        if ($surplus > 0) {
            $lines[] = ['account_id' => ChartOfAccount::system('2110')->id, 'debit' => 0, 'credit' => $surplus];
        }

        LedgerPoster::post($lines, $entryDate, [
            'source_type' => 'PawningAuctionItem',
            'source_id' => $item->id,
            'description' => "Pawning auction sale - {$pawning->code}",
            'branch_id' => $pawning->branch_id,
            'posted_by' => $userId,
        ]);
    }

    /** Surplus actually paid out to the pledger — Dr 2110 (liability relieved), Cr till. */
    public static function postSurplusRefund(PawningAuctionItem $item, ?int $userId = null): void
    {
        $surplus = (float) $item->surplus_amount;
        if ($surplus <= 0) {
            return;
        }
        $pawning = Pawning::find($item->credit_facility_id);
        if (!$pawning) {
            return;
        }
        $till = CashAccount::branchPrimary((int) $pawning->branch_id);
        if (!$till) {
            return;
        }

        LedgerPoster::post([
            ['account_id' => ChartOfAccount::system('2110')->id, 'debit' => $surplus, 'credit' => 0],
            ['account_id' => ChartOfAccount::forCashTill($till)->id, 'debit' => 0, 'credit' => $surplus],
        ], now()->toDateString(), [
            'source_type' => 'PawningAuctionItem',
            'source_id' => $item->id,
            'description' => "Pawning auction surplus refunded - {$pawning->code}",
            'branch_id' => $pawning->branch_id,
            'posted_by' => $userId,
        ]);
    }

    /** Surplus never claimed within the branch's window — Dr 2110, Cr 4900 (it becomes the company's, not kept as a permanent liability). */
    public static function postSurplusForfeiture(PawningAuctionItem $item, ?int $userId = null): void
    {
        $surplus = (float) $item->surplus_amount;
        if ($surplus <= 0) {
            return;
        }
        $pawning = Pawning::find($item->credit_facility_id);
        if (!$pawning) {
            return;
        }

        LedgerPoster::post([
            ['account_id' => ChartOfAccount::system('2110')->id, 'debit' => $surplus, 'credit' => 0],
            ['account_id' => ChartOfAccount::system('4900')->id, 'debit' => 0, 'credit' => $surplus],
        ], now()->toDateString(), [
            'source_type' => 'PawningAuctionItem',
            'source_id' => $item->id,
            'description' => "Pawning auction surplus forfeited (unclaimed) - {$pawning->code}",
            'branch_id' => $pawning->branch_id,
            'posted_by' => $userId,
        ]);
    }

    /** Reverses every posted journal entry this payment ever caused — called when a payment is voided. */
    public static function reverseAllFor(FacilityPayment $payment, ?int $userId = null): void
    {
        JournalEntry::where('source_type', 'PawningPayment')
            ->where('source_id', $payment->id)
            ->where('status', 'Posted')
            ->whereNull('reversal_of_id')
            ->get()
            ->each(fn (JournalEntry $entry) => LedgerPoster::reverse($entry, "Voided: {$payment->code}", $userId));
    }

    private static function meta(FacilityPayment $payment, string $description): array
    {
        return [
            'source_type' => 'PawningPayment',
            'source_id' => $payment->id,
            'description' => $description,
            'branch_id' => $payment->branch_id,
            'posted_by' => $payment->received_by,
        ];
    }
}
