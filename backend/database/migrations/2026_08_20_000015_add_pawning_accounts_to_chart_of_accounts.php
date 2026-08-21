<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Two new leaf accounts for the Pawning module — same reasoning as
 * 2026_08_03_000004_add_speed_draft_accounts_to_chart_of_accounts: kept
 * distinct from 1100/4010 (Loan) and 1110/4015 (Speed Draft) so pawning
 * activity is separately reportable. See App\Support\PawningLedgerPoster.
 * Idempotent, same shape as that migration.
 */
return new class extends Migration
{
    private const ACCOUNTS = [
        // code, name, account_type, normal_balance
        ['1120', 'Pawning Capital Outstanding', 'Asset', 'Debit'],
        ['4025', 'Interest Income - Pawning', 'Income', 'Credit'],
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
        DB::table('chart_of_accounts')->whereIn('code', ['1120', '4025'])->delete();
    }
};
