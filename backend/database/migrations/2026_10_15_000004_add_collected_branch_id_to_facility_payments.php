<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit B-8: a payment can be collected at a different branch from the one that owns the loan. The loan and
 * the receipt stay with the owning branch (branch_id); collected_branch_id is the counter that took the cash,
 * whose till is credited. The ledger then settles the two branches through the inter-branch accounts.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('facility_payments', 'collected_branch_id')) {
            Schema::table('facility_payments', function (Blueprint $t) {
                $t->unsignedBigInteger('collected_branch_id')->nullable()->after('branch_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('facility_payments', 'collected_branch_id')) {
            Schema::table('facility_payments', function (Blueprint $t) {
                $t->dropColumn('collected_branch_id');
            });
        }
    }
};
