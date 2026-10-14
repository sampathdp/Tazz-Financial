<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Loans are paid out only from a Head Office bank account, so no branch keeps a Disbursements cash account.
 * An empty one (no balance, postings, cashbook rows, sessions, transfers or float children) is deleted together
 * with its ledger account. One that has history stays, as a General account, so its books are untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('cash_accounts')->where('purpose', 'Disbursements')->get(['id', 'chart_of_account_id', 'current_balance']) as $till) {
            $used = (float) $till->current_balance !== 0.0
                || ($till->chart_of_account_id && DB::table('journal_lines')->where('account_id', $till->chart_of_account_id)->exists())
                || DB::table('cashbook_entries')->where('cash_account_id', $till->id)->exists()
                || DB::table('cash_sessions')->where('cash_account_id', $till->id)->exists()
                || DB::table('cash_transfers')->where(fn ($q) => $q->where('from_cash_account_id', $till->id)->orWhere('to_cash_account_id', $till->id))->exists()
                || DB::table('cash_accounts')->where('parent_cash_account_id', $till->id)->exists();
            if ($used) {
                DB::table('cash_accounts')->where('id', $till->id)->update(['purpose' => 'General']);

                continue;
            }
            DB::table('cash_accounts')->where('id', $till->id)->delete();
            if ($till->chart_of_account_id) {
                DB::table('chart_of_accounts')->where('id', $till->chart_of_account_id)->where('is_system', 0)->delete();
            }
        }
    }

    public function down(): void
    {
        // The deleted accounts are not recreated.
    }
};
