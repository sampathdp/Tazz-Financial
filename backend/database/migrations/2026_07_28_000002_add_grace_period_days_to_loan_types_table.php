<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loan_types', function (Blueprint $table) {
            // Days after due_date before default (penalty) interest starts accruing.
            // Client requirement: 5-day grace, then daily interest on the overdue
            // installment amount — see LoanSummary::build().
            $table->unsignedSmallInteger('grace_period_days')->default(5)->after('penalty_method');
        });
    }

    public function down(): void
    {
        Schema::table('loan_types', function (Blueprint $table) {
            $table->dropColumn('grace_period_days');
        });
    }
};
