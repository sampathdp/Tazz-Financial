<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WrittenOff is a listed status (Loan's LIVE_STATUSES, loan report counts)
 * that nothing ever actually set — see LoanController::writeOff(), the
 * missing action. Dedicated columns rather than reusing cancelled_reason/
 * cancelled_by: a write-off is a different event (bad debt, not a
 * cancelled application/undisbursed approval) and reusing those would make
 * a written-off loan display as "Cancelled by ..." wherever that pair is
 * already rendered.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credit_facilities', function (Blueprint $table) {
            $table->string('written_off_reason', 255)->nullable()->after('cancelled_at');
            $table->foreignId('written_off_by')->nullable()->after('written_off_reason')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('credit_facilities', function (Blueprint $table) {
            $table->dropForeign(['written_off_by']);
            $table->dropColumn(['written_off_reason', 'written_off_by']);
        });
    }
};
