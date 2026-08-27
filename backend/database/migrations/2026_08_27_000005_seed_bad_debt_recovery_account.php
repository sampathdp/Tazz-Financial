<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A payment collected against a loan that's already been provisioned (see
 * LoanProvisioning) is not ordinary interest income — the balance was
 * already expensed as impairment, so collecting any of it back is a direct
 * P&L gain. Tracked as its own account (not netted back into 5300 or lumped
 * into 4900 Other Income) so it can sit right alongside "Provisioning for
 * Impairment" in the P&L waterfall — see AccountingReportController::
 * plWaterfall()'s badDebtRecovery line, added the same place this account is
 * read from. LoanLedgerPoster::postProvisionAdjustment() credits it when a
 * provision requirement drops. Idempotent, same pattern as
 * 2026_08_20_000001_add_pl_waterfall_accounts_to_chart_of_accounts (whose
 * 4040 this deliberately avoids — that code is already Inter Branch/Zonal
 * Interest Income, live in the same waterfall).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('chart_of_accounts')->where('code', '4050')->exists()) {
            return;
        }
        DB::table('chart_of_accounts')->insert([
            'code' => '4050',
            'name' => 'Bad Debt Recovery Income',
            'account_type' => 'Income',
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
        // Not reversed — journal_lines may already reference it by the time
        // this would run, same rationale as the original COA seed migration.
    }
};
