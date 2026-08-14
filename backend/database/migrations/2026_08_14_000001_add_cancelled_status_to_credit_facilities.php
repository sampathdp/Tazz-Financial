<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds a Cancelled status for Approved-but-not-yet-disbursed loans/speed
 * drafts — distinct from Rejected (which only applies to Pending
 * applications, see LoanController::reject()). Cancelled_by/at/reason mirror
 * the existing approved_by/approved_at/rejected_reason columns so the same
 * "who did it, when, why" audit trail applies to this transition too.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE `credit_facilities`
            MODIFY `status` ENUM('Pending', 'Approved', 'Rejected', 'Cancelled', 'Active', 'Closed', 'Restructured', 'WrittenOff') DEFAULT 'Pending'
        SQL);

        Schema::table('credit_facilities', function (Blueprint $table) {
            $table->string('cancelled_reason', 255)->nullable()->after('rejected_reason');
            $table->foreignId('cancelled_by')->nullable()->after('cancelled_reason')->constrained('users')->nullOnDelete();
            $table->dateTime('cancelled_at')->nullable()->after('cancelled_by');
        });
    }

    public function down(): void
    {
        Schema::table('credit_facilities', function (Blueprint $table) {
            $table->dropForeign(['cancelled_by']);
            $table->dropColumn(['cancelled_reason', 'cancelled_by', 'cancelled_at']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE `credit_facilities`
            MODIFY `status` ENUM('Pending', 'Approved', 'Rejected', 'Active', 'Closed', 'Restructured', 'WrittenOff') DEFAULT 'Pending'
        SQL);
    }
};
