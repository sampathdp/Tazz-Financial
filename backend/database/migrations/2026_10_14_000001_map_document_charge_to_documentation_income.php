<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Charge categories with no income account all post to 4020 Loan Charges / Fees, so the chart's
 * own 4910 "Documentation Income" never receives a document charge. Point the Document category
 * at 4910 when that account exists and the category is still unmapped. Other categories keep the
 * 4020 fallback (map them in Masters > Charge Categories). Idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        $account = DB::table('chart_of_accounts')->where('code', '4910')->where('account_type', 'Income')->value('id');
        if (!$account) {
            return;
        }
        DB::table('charge_categories')->where('name', 'Document')->whereNull('chart_of_account_id')->update(['chart_of_account_id' => $account]);
    }

    public function down(): void
    {
        $account = DB::table('chart_of_accounts')->where('code', '4910')->value('id');
        if ($account) {
            DB::table('charge_categories')->where('name', 'Document')->where('chart_of_account_id', $account)->update(['chart_of_account_id' => null]);
        }
    }
};
