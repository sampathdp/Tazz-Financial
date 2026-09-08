<?php

namespace App\Support;

use App\Models\SavingsAccount;
use App\Models\SavingsTransaction;
use App\Services\NumberGenerator;
use Illuminate\Support\Carbon;

/**
 * The single writer for savings_accounts.current_balance. Every movement on a
 * savings account — a deposit, a withdrawal, a fee, an interest posting, an
 * adjustment — goes through record() so the running_balance snapshot on each
 * savings_transactions row, the account's cached current_balance and its
 * last_activity_on all stay in step, and nothing re-implements "lock the
 * account, add the delta, write the row" on its own.
 *
 * Does NOT post to the general ledger or move a till — the caller does that
 * through SavingsLedgerPoster after record() returns. Must be called from
 * inside the caller's own DB::transaction (like NumberGenerator / LedgerPoster).
 */
class SavingsAccountService
{
    /** Transaction types that count as customer activity (reset the dormancy clock / reactivate a Dormant account). */
    private const ACTIVITY_TYPES = ['Deposit', 'Withdrawal'];

    /**
     * @param array{
     *   type: string, direction: string, amount: numeric, txn_date: string,
     *   payment_method?: ?string, bank_id?: ?int, reference_no?: ?string,
     *   collection_sheet_id?: ?int, interest_run_id?: ?int, notes?: ?string,
     *   void_of_id?: ?int, created_by?: ?int
     * } $spec
     */
    public static function record(SavingsAccount $account, array $spec): SavingsTransaction
    {
        $locked = SavingsAccount::lockForUpdate()->findOrFail($account->id);

        $signed = $spec['direction'] === 'Out'
            ? bcmul((string) $spec['amount'], '-1', 2)
            : (string) $spec['amount'];
        $newBalance = bcadd((string) $locked->current_balance, $signed, 2);

        $number = NumberGenerator::next('savings_txn', [
            'branch_id' => $locked->branch_id,
            'branch_code' => $locked->branch->code ?? null,
            'user_id' => $spec['created_by'] ?? null,
        ]);

        $txn = SavingsTransaction::create([
            'txn_number' => $number,
            'savings_account_id' => $locked->id,
            'txn_date' => $spec['txn_date'],
            'type' => $spec['type'],
            'direction' => $spec['direction'],
            'amount' => $spec['amount'],
            'running_balance' => $newBalance,
            'payment_method' => $spec['payment_method'] ?? null,
            'bank_id' => $spec['bank_id'] ?? null,
            'reference_no' => $spec['reference_no'] ?? null,
            'collection_sheet_id' => $spec['collection_sheet_id'] ?? null,
            'interest_run_id' => $spec['interest_run_id'] ?? null,
            'notes' => $spec['notes'] ?? null,
            'status' => 'Posted',
            'void_of_id' => $spec['void_of_id'] ?? null,
            'created_by' => $spec['created_by'] ?? null,
        ]);
        NumberGenerator::attach($number, $txn);

        $updates = ['current_balance' => $newBalance];
        if (in_array($spec['type'], self::ACTIVITY_TYPES, true)) {
            $updates['last_activity_on'] = $spec['txn_date'];
            // A fresh deposit wakes a dormant account.
            if ($locked->status === 'Dormant' && $spec['direction'] === 'In') {
                $updates['status'] = 'Active';
            }
        }
        $locked->update($updates);
        $account->fill($updates);
        $account->setRelation('savingsProduct', $account->savingsProduct);

        return $txn;
    }

    /**
     * Balance the account held immediately after the last Posted transaction
     * on or before $asOf (0 before its first transaction). Reads the
     * running_balance snapshots — no re-summing.
     */
    public static function balanceAsOf(SavingsAccount $account, string $asOf): string
    {
        $row = SavingsTransaction::where('savings_account_id', $account->id)
            ->where('status', 'Posted')
            ->whereDate('txn_date', '<=', $asOf)
            ->orderByDesc('txn_date')->orderByDesc('id')
            ->first();

        return $row ? (string) $row->running_balance : '0.00';
    }

    /** Whether $account has had no customer activity for its product's dormancy window as of today. */
    public static function isDueForDormancy(SavingsAccount $account): bool
    {
        $days = (int) ($account->savingsProduct->dormancy_days ?? 0);
        if ($days <= 0 || $account->status !== 'Active') {
            return false;
        }
        $last = $account->last_activity_on ?? $account->open_date;

        return $last && Carbon::parse($last)->addDays($days)->lt(Carbon::today());
    }
}
