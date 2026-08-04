<?php

namespace App\Support;

use App\Models\BankAccount;
use App\Models\CashAccount;
use App\Models\ChartOfAccount;
use Carbon\Carbon;

/**
 * Posts the one-time entry that establishes a newly created cash till's or
 * bank account's opening_balance in the general ledger — Dr the till/bank
 * leaf account (creating it if this is the first posting against it, see
 * ChartOfAccount::forCashTill()/forBankAccount()), Cr 3015 Opening Balance
 * Equity. Without this, current_balance and the GL's own derived balance for
 * the same leaf account permanently disagree from the moment the account is
 * created — found via the Ledger Drift report (see
 * AccountingReportController::ledgerDrift()), which is also how to check this
 * never regresses. No-op for a zero opening balance — nothing to post.
 *
 * post() is also the one place 2026_08_03_000008's backfill migration would
 * reuse if migrations in this codebase depended on Support classes — they
 * deliberately don't (see that migration's docblock), so it re-derives the
 * same debit/credit-sign logic in raw SQL instead. Keep the two in sync if
 * this logic ever changes.
 */
class OpeningBalanceLedgerPoster
{
    public static function postForCashAccount(CashAccount $account): void
    {
        self::post(ChartOfAccount::forCashTill($account), (string) $account->opening_balance, $account->name, $account->branch_id, $account->created_at);
    }

    public static function postForBankAccount(BankAccount $account): void
    {
        self::post(ChartOfAccount::forBankAccount($account), (string) $account->opening_balance, $account->name, $account->branch_id, $account->created_at);
    }

    private static function post(ChartOfAccount $leaf, string $amount, string $accountName, ?int $branchId, ?Carbon $asOf): void
    {
        if (bccomp($amount, '0.00', 2) === 0) {
            return;
        }
        $equity = ChartOfAccount::system('3015');
        $positive = bccomp($amount, '0.00', 2) > 0;
        $abs = $positive ? $amount : bcsub('0.00', $amount, 2);

        LedgerPoster::post([
            ['account_id' => $leaf->id, 'debit' => $positive ? $abs : 0, 'credit' => $positive ? 0 : $abs],
            ['account_id' => $equity->id, 'debit' => $positive ? 0 : $abs, 'credit' => $positive ? $abs : 0],
        ], ($asOf ?? now())->toDateString(), [
            'description' => "Opening balance - {$accountName}",
            'branch_id' => $branchId,
        ]);
    }
}
