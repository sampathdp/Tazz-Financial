<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Two new leaf accounts for the Speed Drafts module — kept distinct from 1100
 * ("Loan Principal Outstanding") and 4010 ("Interest Income - Loans") so Speed
 * Draft activity is separately reportable without a parallel ledger. See
 * App\Support\SpeedDraftLedgerPoster. Idempotent, same shape as
 * 2026_08_01_000013_add_unapplied_loan_receipts_account.
 */
return new class extends Migration
{
    private const ACCOUNTS = [
        // code, name, account_type, normal_balance
        ['1110', 'Speed Draft Capital Outstanding', 'Asset', 'Debit'],
        ['4015', 'Interest Income - Speed Drafts', 'Income', 'Credit'],
    ];

    public function up(): void
    {
        $now = now();
        foreach (self::ACCOUNTS as [$code, $name, $accountType, $normalBalance]) {
            if (DB::table('chart_of_accounts')->where('code', $code)->exists()) {
                continue;
            }
            DB::table('chart_of_accounts')->insert([
                'code' => $code,
                'name' => $name,
                'account_type' => $accountType,
                'normal_balance' => $normalBalance,
                'parent_id' => null,
                'is_system' => true,
                'status' => 'Active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('chart_of_accounts')->whereIn('code', ['1110', '4015'])->delete();
    }
};
