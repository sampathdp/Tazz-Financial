<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A full-settlement request is cash-in-hand the moment it's recorded (see
 * CashbookPoster) but isn't applied to the loan schedule until a second
 * person approves it — so the receipt and the earning of that cash are two
 * separate, dated events. This liability account is the gap between them:
 * Dr Till / Cr 2100 at receipt, Dr 2100 / Cr (principal/interest/penalty/
 * charges) at approval — see App\Support\LoanLedgerPoster.
 */
return new class extends Migration
{
    public function up(): void
    {
        $exists = DB::table('chart_of_accounts')->where('code', '2100')->exists();
        if ($exists) {
            return;
        }
        DB::table('chart_of_accounts')->insert([
            'code' => '2100',
            'name' => 'Unapplied Loan Receipts',
            'account_type' => 'Liability',
            'normal_balance' => 'Credit',
            'parent_id' => null,
            'is_system' => true,
            'status' => 'Active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('chart_of_accounts')->where('code', '2100')->delete();
    }
};
