<?php

namespace App\Support;

use App\Models\CashAccount;
use App\Models\ChartOfAccount;
use App\Models\FinanceTransaction;
use App\Models\JournalEntry;
use App\Models\SavingsInterestRun;
use App\Models\SavingsTransaction;

/**
 * Bridges Daily Savings money movements into the general ledger — the savings
 * counterpart of FdLedgerPoster.
 *
 * Cash legs (deposit / withdrawal / closure payout) go through
 * FinancePostingService (the one money writer): the branch till moves, an
 * immutable finance_transactions row is written and the cashbook mirror is
 * created, atomically with the GL entry. The journal entry stays keyed to the
 * savings_transactions row (gl_source_type='SavingsTransaction') so the
 * reverse* lookups here and any report filtering journal_entries by that
 * source keep working.
 *
 * Non-cash legs — a fee deducted from the account balance, and an interest
 * run capitalised to balances — post straight through LedgerPoster (no till
 * movement).
 *
 * Scope, deliberately: only Cash-method deposits/withdrawals are wired to the
 * till, same reasoning as every other poster — Bank/Cheque have no
 * unambiguous account to move without a captured bank_account_id.
 *
 *   Deposit  (Cash)  : Dr <branch till>   Cr 2050
 *   Withdrawal (Cash) : Dr 2050            Cr <branch till>
 *   Fee               : Dr 2050            Cr 4045   (GL-only)
 *   Interest run      : Dr 5120 (total)    Cr 2050 (total)   (GL-only)
 */
class SavingsLedgerPoster
{
    public static function postDeposit(SavingsTransaction $txn): void
    {
        if ($txn->payment_method !== 'Cash') {
            return;
        }
        $account = $txn->savingsAccount;
        $till = CashAccount::branchPrimary((int) $account->branch_id);
        if (!$till) {
            return;
        }
        $tillAccount = ChartOfAccount::forCashTill($till);
        $payable = ChartOfAccount::system('2050');

        self::postCashLeg($till, '+' . $txn->amount, [
            ['account_id' => $tillAccount->id, 'debit' => $txn->amount, 'credit' => 0],
            ['account_id' => $payable->id, 'debit' => 0, 'credit' => $txn->amount],
        ], [
            'txn_type' => 'CASH_IN',
            'amount' => $txn->amount,
            'entry_date' => $txn->txn_date,
            'branch_id' => $account->branch_id,
            'source_id' => $txn->id,
            'idempotency_key' => "savings-txn-{$txn->id}",
            'description' => "Savings deposit - {$account->account_number} ({$txn->txn_number})",
            'posted_by' => $txn->created_by,
        ]);
    }

    /** A Cash-method withdrawal or closure payout — Dr 2050, Cr the branch till. */
    public static function postWithdrawal(SavingsTransaction $txn): void
    {
        if ($txn->payment_method !== 'Cash') {
            return;
        }
        $account = $txn->savingsAccount;
        $till = CashAccount::branchPrimary((int) $account->branch_id);
        if (!$till) {
            return;
        }
        $tillAccount = ChartOfAccount::forCashTill($till);
        $payable = ChartOfAccount::system('2050');

        self::postCashLeg($till, '-' . $txn->amount, [
            ['account_id' => $payable->id, 'debit' => $txn->amount, 'credit' => 0],
            ['account_id' => $tillAccount->id, 'debit' => 0, 'credit' => $txn->amount],
        ], [
            'txn_type' => 'CASH_OUT',
            'amount' => $txn->amount,
            'entry_date' => $txn->txn_date,
            'branch_id' => $account->branch_id,
            'source_id' => $txn->id,
            'idempotency_key' => "savings-txn-{$txn->id}",
            'description' => "Savings withdrawal - {$account->account_number} ({$txn->txn_number})",
            'posted_by' => $txn->created_by,
        ]);
    }

    /** A fee charged to the account balance — Dr 2050 (liability reduced), Cr 4045 (fee income). GL-only, no cash. */
    public static function postFee(SavingsTransaction $txn): void
    {
        $account = $txn->savingsAccount;
        LedgerPoster::post([
            ['account_id' => ChartOfAccount::system('2050')->id, 'debit' => $txn->amount, 'credit' => 0],
            ['account_id' => ChartOfAccount::system('4045')->id, 'debit' => 0, 'credit' => $txn->amount],
        ], (string) $txn->txn_date, [
            'source_type' => 'SavingsTransaction',
            'source_id' => $txn->id,
            'description' => "Savings fee - {$account->account_number} ({$txn->txn_number})",
            'branch_id' => $account->branch_id,
            'posted_by' => $txn->created_by,
        ]);
    }

    /**
     * One combined entry for an interest run — Dr 5120 Interest Expense -
     * Savings Deposits (total), Cr 2050 (total). GL-only: interest is
     * capitalised to each account's balance by SavingsAccountService, no cash
     * changes hands.
     */
    public static function postInterestRun(SavingsInterestRun $run, string $totalInterest, ?int $userId): void
    {
        if (bccomp($totalInterest, '0.00', 2) <= 0) {
            return;
        }
        LedgerPoster::post([
            ['account_id' => ChartOfAccount::system('5120')->id, 'debit' => $totalInterest, 'credit' => 0],
            ['account_id' => ChartOfAccount::system('2050')->id, 'debit' => 0, 'credit' => $totalInterest],
        ], (string) $run->period_to, [
            'source_type' => 'SavingsInterestRun',
            'source_id' => $run->id,
            'description' => "Savings interest run {$run->run_number} ({$run->period_from} to {$run->period_to})",
            'branch_id' => $run->branch_id,
            'posted_by' => $userId,
        ]);
    }

    public static function reverseTransaction(SavingsTransaction $txn, ?int $userId = null): void
    {
        self::reverseVia('SavingsTransaction', $txn->id, "Voided: {$txn->txn_number}", $userId);
    }

    public static function reverseInterestRun(SavingsInterestRun $run, ?int $userId = null): void
    {
        self::reverseVia('SavingsInterestRun', $run->id, "Voided interest run: {$run->run_number}", $userId);
    }

    /**
     * @param array{txn_type:string, amount:numeric, entry_date:mixed, branch_id:?int, source_id:int, idempotency_key:string, description:string, posted_by:?int} $ctx
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
            'gl_source_type' => 'SavingsTransaction',
            'gl_source_id' => $ctx['source_id'],
            'gl_description' => $ctx['description'],
            'description' => $ctx['description'],
            'source_module' => 'SavingsTransaction',
            'source_id' => $ctx['source_id'],
            'idempotency_key' => $ctx['idempotency_key'],
            'executed_by' => $ctx['posted_by'],
            'meta' => ['cashbook_category' => 'Savings'],
        ]);
    }

    /** Undoes a savings GL leg — Finance reversal if it went through FinancePostingService, else a plain GL reversal. No-op if nothing was posted. */
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
