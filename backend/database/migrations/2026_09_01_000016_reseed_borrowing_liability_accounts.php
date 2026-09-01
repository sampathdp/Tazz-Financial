<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 9 gap fix — the borrowing liability control accounts 2200 (Borrowings
 * - Party / Institutional) and 2210 (Bank Overdrafts) were found missing from
 * the ledger even though 2026_08_30_000010_seed_borrowing_accounts_to_chart_
 * of_accounts had run (2130 / 5110 from the same list are present). A Cash
 * drawdown on a Party or Overdraft borrowing therefore threw
 * "Chart of accounts is missing expected control account '2200'".
 *
 * Re-seeds all four borrowing accounts, idempotent on `code` — a no-op where
 * they already exist.
 */
return new class extends Migration
{
    private const ACCOUNTS = [
        ['2200', 'Borrowings - Party / Institutional', 'Liability', 'Credit'],
        ['2210', 'Bank Overdrafts', 'Liability', 'Credit'],
        ['2130', 'Re-Pledged Pawning Liability', 'Liability', 'Credit'],
        ['5110', 'Interest Expense - Borrowings', 'Expense', 'Debit'],
    ];

    public function up(): void
    {
        foreach (self::ACCOUNTS as [$code, $name, $type, $normalBalance]) {
            if (DB::table('chart_of_accounts')->where('code', $code)->exists()) {
                continue;
            }
            DB::table('chart_of_accounts')->insert([
                'code' => $code,
                'name' => $name,
                'account_type' => $type,
                'normal_balance' => $normalBalance,
                'parent_id' => null,
                'is_system' => true,
                'status' => 'Active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Not reversed — journal_lines may already reference these.
    }
};
