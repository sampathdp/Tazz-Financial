<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Links a rescheduled (restructured) loan back to the loan it replaced.
 * Set on the NEW credit_facilities row created by
 * LoanController::reschedule(); the old row's status moves to the
 * already-reserved 'Restructured' value. A loan can be rescheduled again,
 * forming a chain (each new row points at its immediate predecessor).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credit_facilities', function (Blueprint $table) {
            $table->foreignId('restructured_from_id')->nullable()->after('status')
                ->constrained('credit_facilities')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('credit_facilities', function (Blueprint $table) {
            $table->dropConstrainedForeignId('restructured_from_id');
        });
    }
};
