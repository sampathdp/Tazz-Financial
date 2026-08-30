<?php

namespace App\Support;

use App\Models\Borrowing;
use App\Models\BorrowingTransaction;
use App\Models\CashAccount;
use App\Models\CashbookEntry;
use Illuminate\Support\Facades\DB;

/**
 * Bridges company Borrowing cash movements into the Cashbook ledger — the
 * counterpart of FdCashbookPoster. Only Cash-method movements post here, same
 * "silent no-op for Bank/Cheque or a branch with no active cash account"
 * convention as the FD posters.
 */
class BorrowingCashbookPoster
{
    private static function account(int $branchId, ?int $cashAccountId): ?CashAccount
    {
        if ($cashAccountId) {
            return CashAccount::find($cashAccountId);
        }

        return CashAccount::branchPrimary($branchId);
    }

    private static function move(CashAccount $account, string $direction, string $category, string $amount, string $date, string $sourceType, int $sourceId, string $description, ?int $userId): void
    {
        DB::transaction(function () use ($account, $direction, $category, $amount, $date, $sourceType, $sourceId, $description, $userId) {
            $account = CashAccount::lockForUpdate()->find($account->id);
            $newBalance = $direction === 'In'
                ? bcadd((string) $account->current_balance, $amount, 2)
                : bcsub((string) $account->current_balance, $amount, 2);
            $account->update(['current_balance' => $newBalance]);

            CashbookEntry::create([
                'branch_id' => $account->branch_id,
                'cash_account_id' => $account->id,
                'entry_date' => $date,
                'direction' => $direction,
                'category' => $category,
                'amount' => $amount,
                'balance_after' => $newBalance,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'description' => $description,
                'created_by' => $userId,
            ]);
        });
    }

    public static function postDrawdown(Borrowing $borrowing): void
    {
        if ($borrowing->receipt_method !== 'Cash') {
            return;
        }
        $account = self::account((int) $borrowing->branch_id, $borrowing->receipt_cash_account_id);
        if (!$account) {
            return;
        }
        self::move($account, 'In', 'Other', (string) $borrowing->principal_amount, $borrowing->borrowing_date, 'Borrowing', $borrowing->id, "Borrowing received - {$borrowing->borrowing_number} ({$borrowing->lender_name})", $borrowing->created_by);
    }

    public static function postTransaction(BorrowingTransaction $txn): void
    {
        if ($txn->payment_method !== 'Cash') {
            return;
        }
        $borrowing = $txn->borrowing;
        $account = self::account((int) $borrowing->branch_id, $txn->cash_account_id);
        if (!$account) {
            return;
        }
        $direction = $txn->kind === 'Drawdown' ? 'In' : 'Out';
        $category = $txn->kind === 'InterestPayment' ? 'Expense' : 'Other';
        self::move($account, $direction, $category, (string) $txn->amount, $txn->txn_date, 'BorrowingTransaction', $txn->id, "Borrowing {$txn->kind} - {$borrowing->borrowing_number} ({$txn->voucher_no})", $txn->created_by);
    }

    /** Unwinds a Cashbook entry for a borrowing / transaction being voided — reverses the till balance and deletes the entry. */
    public static function reverse(string $sourceType, int $sourceId): void
    {
        $entry = CashbookEntry::where('source_type', $sourceType)->where('source_id', $sourceId)->first();
        if (!$entry) {
            return;
        }
        DB::transaction(function () use ($entry) {
            $account = CashAccount::lockForUpdate()->find($entry->cash_account_id);
            if ($account) {
                $delta = $entry->direction === 'In' ? bcsub((string) $account->current_balance, (string) $entry->amount, 2) : bcadd((string) $account->current_balance, (string) $entry->amount, 2);
                $account->update(['current_balance' => $delta]);
            }
            $entry->delete();
        });
    }
}
