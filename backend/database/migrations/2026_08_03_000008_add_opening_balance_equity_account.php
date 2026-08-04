<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Two fixes for the same root cause, found via the new Ledger Drift report
 * (AccountingReportController::ledgerDrift): creating a cash till or bank
 * account with a nonzero opening_balance never posted that balance to the
 * general ledger, so chart_of_accounts/journal_lines started at 0 for it
 * forever while cash_accounts/bank_accounts.current_balance carried the real
 * figure. See App\Support\OpeningBalanceLedgerPoster for the forward fix (now
 * called from CashAccountController::store() / BankAccountController::store()).
 *
 * This migration:
 *  1. Adds 3015 Opening Balance Equity, the account those postings credit.
 *  2. Backfills a correcting entry for every till/bank account that predates
 *     the fix — identified as "linked to a GL leaf account with zero
 *     journal_lines ever posted", not by comparing current balances, so this
 *     can never double-post for an account the new code path already
 *     covered, and never touches one with real (correctly posted) activity.
 *
 * Uses DB::table() throughout rather than Eloquent/Support classes, matching
 * every other migration in this file — a migration should never depend on
 * application classes that can change shape after this file is written. The
 * debit/credit-sign logic below mirrors OpeningBalanceLedgerPoster::post();
 * keep the two in sync if that logic ever changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $equityId = DB::table('chart_of_accounts')->where('code', '3015')->value('id');
        if (!$equityId) {
            $equityId = DB::table('chart_of_accounts')->insertGetId([
                'code' => '3015',
                'name' => 'Opening Balance Equity',
                'account_type' => 'Equity',
                'normal_balance' => 'Credit',
                'parent_id' => null,
                'is_system' => true,
                'status' => 'Active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $tills = DB::table('cash_accounts')
            ->select('chart_of_account_id as leaf_id', 'name', 'opening_balance', 'branch_id', 'created_at')
            ->get();
        foreach ($tills as $row) {
            $this->backfill($row->leaf_id, $equityId, $row);
        }

        $banks = DB::table('bank_accounts')
            ->select('chart_of_account_id as leaf_id', 'name', 'opening_balance', 'branch_id', 'created_at')
            ->get();
        foreach ($banks as $row) {
            $this->backfill($row->leaf_id, $equityId, $row);
        }
    }

    private function backfill(int $leafAccountId, int $equityId, object $source): void
    {
        if (DB::table('journal_lines')->where('account_id', $leafAccountId)->exists()) {
            return;
        }
        $amount = (string) $source->opening_balance;
        if (bccomp($amount, '0.00', 2) === 0) {
            return;
        }
        $positive = bccomp($amount, '0.00', 2) > 0;
        $abs = $positive ? $amount : bcsub('0.00', $amount, 2);
        $entryDate = $source->created_at ? Carbon::parse($source->created_at)->toDateString() : now()->toDateString();
        $now = now();

        $entryId = DB::table('journal_entries')->insertGetId([
            'code' => $this->nextJournalCode(),
            'entry_date' => $entryDate,
            'source_type' => null,
            'source_id' => null,
            'description' => "Opening balance - {$source->name}",
            'branch_id' => $source->branch_id,
            'posted_by' => null,
            'posted_at' => $now,
            'status' => 'Posted',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('journal_lines')->insert([
            [
                'journal_entry_id' => $entryId,
                'account_id' => $leafAccountId,
                'entry_date' => $entryDate,
                'debit' => $positive ? $abs : '0.00',
                'credit' => $positive ? '0.00' : $abs,
                'description' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'journal_entry_id' => $entryId,
                'account_id' => $equityId,
                'entry_date' => $entryDate,
                'debit' => $positive ? '0.00' : $abs,
                'credit' => $positive ? $abs : '0.00',
                'description' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    /** Mirrors NumberGenerator::next('journal') (format JV-{YEAR:2}-{SEQ:6}, one global series) without depending on that class — see this migration's docblock. */
    private function nextJournalCode(): string
    {
        $year = (int) now()->format('Y');
        $key = ['module' => 'journal', 'branch_id' => null, 'loan_type_id' => null, 'funding_product_id' => null, 'year' => $year];

        DB::table('number_sequences')->insertOrIgnore([$key + ['last_sequence' => 0, 'created_at' => now(), 'updated_at' => now()]]);
        $seq = DB::table('number_sequences')->where($key)->lockForUpdate()->value('last_sequence') + 1;
        DB::table('number_sequences')->where($key)->update(['last_sequence' => $seq, 'updated_at' => now()]);

        $yearSuffix = substr((string) $year, -2);
        $seqPadded = str_pad((string) $seq, 6, '0', STR_PAD_LEFT);

        return "JV-{$yearSuffix}-{$seqPadded}";
    }

    public function down(): void
    {
        // Not reversed: by the time this would roll back, real activity may
        // already be posted on top of these backfilled entries. Reversing via
        // a proper Journal Entry reversal (Accounting > Journal Entries) is
        // the safe way to undo one if you ever need to — not attempted
        // automatically here.
    }
};
