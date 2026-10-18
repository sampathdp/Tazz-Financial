<?php

namespace App\Support;

use App\Models\Borrowing;
use App\Models\ChartOfAccount;
use Illuminate\Support\Facades\DB;

/**
 * Re-asserts the invariant between the Borrowings sub-ledger and the general
 * ledger, the borrowing counterpart of the cash/bank drift check:
 *
 *  - per borrowing: what the borrowing says it still owes (outstanding_amount)
 *    equals the net credit its own journal entries left on its liability account
 *    (2200 / 2130), and its accrued_interest_unpaid equals the net credit left on
 *    2220. A borrowing recorded before Bank/Cheque legs posted, or one edited in
 *    the database, shows up here.
 *  - per account: the sum of those balances equals the whole-ledger balance of
 *    2200 / 2130 / 2220. A difference not explained by any one borrowing means
 *    something else (a manual journal, an opening balance) has touched the account.
 *
 * Overdraft facilities are skipped: their balance is the linked bank account's,
 * which the cash/bank drift check already covers.
 */
class BorrowingLedgerCheck
{
    /**
     * @return array{borrowings: array<int, array>, accounts: array<int, array>}
     */
    public static function run(string $tolerance = '0.00'): array
    {
        $borrowings = [];
        $sums = ['2200' => '0.00', '2130' => '0.00', '2220' => '0.00'];

        $query = Borrowing::withoutGlobalScopes()->where('type', '!=', 'Overdraft')->where('status', '!=', 'Void')->with('transactions');
        foreach ($query->get() as $b) {
            $liabilityCode = $b->type === 'RePledgedPawning' ? '2130' : '2200';
            $txnIds = $b->transactions->pluck('id')->all();

            $liability = self::net($liabilityCode, $b->id, $txnIds);
            $accrued = self::net('2220', $b->id, $txnIds, withAccrual: true);
            $sums[$liabilityCode] = bcadd($sums[$liabilityCode], (string) $b->outstanding_amount, 2);
            $sums['2220'] = bcadd($sums['2220'], (string) $b->accrued_interest_unpaid, 2);

            foreach ([
                ['Outstanding', $liabilityCode, (string) $b->outstanding_amount, $liability],
                ['Accrued interest', '2220', (string) $b->accrued_interest_unpaid, $accrued],
            ] as [$what, $code, $sub, $gl]) {
                $variance = bcsub($sub, $gl, 2);
                if (bccomp(self::abs($variance), $tolerance, 2) > 0) {
                    $borrowings[] = [
                        'id' => $b->id, 'number' => $b->borrowing_number, 'what' => $what, 'gl_code' => $code,
                        'borrowing' => $sub, 'ledger' => $gl, 'variance' => $variance,
                    ];
                }
            }
        }

        $accounts = [];
        foreach ($sums as $code => $fromBorrowings) {
            $l = DB::table('journal_lines')->where('account_id', ChartOfAccount::system($code)->id)->selectRaw('COALESCE(SUM(credit),0) c, COALESCE(SUM(debit),0) d')->first();
            $ledger = bcsub((string) $l->c, (string) $l->d, 2); // all three are credit-normal liabilities
            $variance = bcsub($fromBorrowings, $ledger, 2);
            if (bccomp(self::abs($variance), $tolerance, 2) > 0) {
                $accounts[] = ['gl_code' => $code, 'borrowings' => $fromBorrowings, 'ledger' => $ledger, 'variance' => $variance];
            }
        }

        return ['borrowings' => $borrowings, 'accounts' => $accounts];
    }

    /** Net credit on an account from a borrowing's own entries (its drawdown, its transactions, optionally its accruals); reversals net out. */
    private static function net(string $code, int $borrowingId, array $txnIds, bool $withAccrual = false): string
    {
        $accountId = ChartOfAccount::system($code)->id;
        $r = DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_lines.account_id', $accountId)
            ->where(function ($q) use ($borrowingId, $txnIds, $withAccrual) {
                $q->where(fn ($w) => $w->where('journal_entries.source_type', 'Borrowing')->where('journal_entries.source_id', $borrowingId));
                if ($txnIds) {
                    $q->orWhere(fn ($w) => $w->where('journal_entries.source_type', 'BorrowingTransaction')->whereIn('journal_entries.source_id', $txnIds));
                }
                if ($withAccrual) {
                    $q->orWhere(fn ($w) => $w->where('journal_entries.source_type', 'BorrowingAccrual')->where('journal_entries.source_id', $borrowingId));
                }
            })
            ->selectRaw('COALESCE(SUM(journal_lines.credit),0) c, COALESCE(SUM(journal_lines.debit),0) d')->first();

        return bcsub((string) $r->c, (string) $r->d, 2);
    }

    private static function abs(string $n): string
    {
        return bccomp($n, '0.00', 2) < 0 ? bcmul($n, '-1', 2) : $n;
    }
}
