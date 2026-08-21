<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Approved (Completed) cash transfers had no way back — CashTransferController::
 * destroy() only ever allowed deleting a still-Pending transfer, so a
 * mistakenly-approved transfer left both till balances and its GL entry
 * permanently wrong. Voided joins Pending/Completed/Rejected; void_reason/
 * voided_by/voided_at mirror the audit columns every other voidable record
 * in this codebase already carries (FacilityPayment, FdWithdrawal, ...).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE `cash_transfers`
            MODIFY `status` ENUM('Pending', 'Completed', 'Rejected', 'Voided') DEFAULT 'Pending'
        SQL);

        Schema::table('cash_transfers', function (Blueprint $table) {
            $table->string('void_reason', 255)->nullable()->after('approved_at');
            $table->foreignId('voided_by')->nullable()->after('void_reason')->constrained('users')->nullOnDelete();
            $table->dateTime('voided_at')->nullable()->after('voided_by');
        });
    }

    public function down(): void
    {
        Schema::table('cash_transfers', function (Blueprint $table) {
            $table->dropForeign(['voided_by']);
            $table->dropColumn(['void_reason', 'voided_by', 'voided_at']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE `cash_transfers`
            MODIFY `status` ENUM('Pending', 'Completed', 'Rejected') DEFAULT 'Pending'
        SQL);
    }
};
