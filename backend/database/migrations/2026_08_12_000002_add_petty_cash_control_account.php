<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Parent control account for petty cash float leaves — same role as 1010
 * "Cash in Hand" for tills, but kept separate so a float's balance never
 * mixes with the main tills on the Balance Sheet. Leaves (one per
 * petty_cash_funds row) are created lazily by ChartOfAccount::forPettyCashFund(),
 * same pattern as forCashTill()/forBankAccount().
 */
return new class extends Migration
{
    public function up(): void
    {
        $exists = DB::table('chart_of_accounts')->where('code', '1015')->exists();
        if ($exists) {
            return;
        }
        DB::table('chart_of_accounts')->insert([
            'code' => '1015',
            'name' => 'Petty Cash Floats',
            'account_type' => 'Asset',
            'normal_balance' => 'Debit',
            'parent_id' => null,
            'is_system' => true,
            'status' => 'Active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('chart_of_accounts')->where('code', '1015')->delete();
    }
};
