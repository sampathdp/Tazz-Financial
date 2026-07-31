<?php

namespace App\Support;

use App\Models\CashAccount;
use App\Models\CashbookEntry;
use App\Models\LoanPayment;
use Illuminate\Support\Facades\DB;

/**
 * Bridges Loan Payments into the Cashbook ledger. A cash loan payment is
 * physical money in the cashier's hand the moment it's recorded — regardless
 * of whether it's later applied to the loan schedule (e.g. a pending
 * settlement) — so it's posted to the branch's cash account immediately
 * rather than requiring a duplicate manual Cashbook entry.
 */
class CashbookPoster
{
    /** Posts a Cash-method loan payment as a Cashbook "In" entry. No-op for Bank/Cheque payments or a branch with no active cash account. */
    public static function postCashReceipt(LoanPayment $payment): void
    {
        if ($payment->payment_method !== 'Cash') {
            return;
        }
        $account = self::branchCashAccount((int) $payment->branch_id);
        if (!$account) {
            return;
        }

        DB::transaction(function () use ($account, $payment) {
            $account = CashAccount::lockForUpdate()->find($account->id);
            $newBalance = bcadd((string) $account->current_balance, (string) $payment->amount, 2);
            $account->update(['current_balance' => $newBalance]);

            CashbookEntry::create([
                'branch_id' => $account->branch_id,
                'cash_account_id' => $account->id,
                'entry_date' => optional($payment->paid_at)->toDateString() ?? now()->toDateString(),
                'type' => 'In',
                'category' => 'Loan Collection',
                'amount' => $payment->amount,
                'balance_after' => $newBalance,
                'reference_type' => LoanPayment::class,
                'reference_id' => $payment->id,
                'description' => "Loan payment {$payment->code}",
                'created_by' => $payment->received_by,
            ]);
        });
    }

    /** Unwinds the Cashbook entry for a cash loan payment that's now voided/rejected — deletes it and reverses the balance (same "delete to reverse" convention as CashbookEntryController::destroy). No-op if the payment never had one (Bank/Cheque, or no cash account at the time). */
    public static function reverseCashReceipt(LoanPayment $payment): void
    {
        $entry = CashbookEntry::where('reference_type', LoanPayment::class)
            ->where('reference_id', $payment->id)
            ->first();
        if (!$entry) {
            return;
        }

        DB::transaction(function () use ($entry) {
            $account = CashAccount::lockForUpdate()->find($entry->cash_account_id);
            if ($account) {
                $account->update(['current_balance' => bcsub((string) $account->current_balance, (string) $entry->amount, 2)]);
            }
            $entry->delete();
        });
    }

    /** The branch's primary till for auto-posted collections — its oldest active cash account. Branches expected to keep exactly one; if several exist, the first one created wins. */
    private static function branchCashAccount(int $branchId): ?CashAccount
    {
        return CashAccount::where('branch_id', $branchId)->where('status', 'Active')->orderBy('id')->first();
    }
}
