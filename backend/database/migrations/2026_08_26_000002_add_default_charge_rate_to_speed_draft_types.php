<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Speed Draft has had no penalty concept at all (see facility_schedules'
 * migration docblock) — grace_period_days already existed on this table but
 * was never paired with a rate. Same shape as loan_types.default_charge_rate_percent,
 * so SpeedDraftSummary::penaltyDueForRow() can mirror LoanSummary's directly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('speed_draft_types', function (Blueprint $table) {
            $table->decimal('default_charge_rate_percent', 6, 3)->nullable()->after('grace_period_days');
        });
    }

    public function down(): void
    {
        Schema::table('speed_draft_types', function (Blueprint $table) {
            $table->dropColumn('default_charge_rate_percent');
        });
    }
};
