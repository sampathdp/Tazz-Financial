<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loan_types', function (Blueprint $table) {
            // Only meaningful for daily-frequency products: which calendar days
            // installments are due on. all_days = every day; week_days = Mon-Fri
            // only (Sat/Sun skipped when the schedule is generated).
            $table->string('collection_days', 16)->default('all_days')->after('frequency');
        });
    }

    public function down(): void
    {
        Schema::table('loan_types', function (Blueprint $table) {
            $table->dropColumn('collection_days');
        });
    }
};
