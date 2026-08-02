<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loan_types', function (Blueprint $table) {
            $table->decimal('default_charge_rate_percent', 6, 3)->default(0)->after('grace_period_days');
        });

        // Carry forward whatever OD/penalty rate was already configured, so
        // existing loan types keep charging the same rate under the new field
        // instead of silently resetting to 0.
        DB::table('loan_types')->update(['default_charge_rate_percent' => DB::raw('penalty_rate_percent')]);
    }

    public function down(): void
    {
        Schema::table('loan_types', function (Blueprint $table) {
            $table->dropColumn('default_charge_rate_percent');
        });
    }
};
