<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 'Reverse' read like undoing a mistake — what actually happens when a
 * provision requirement drops is a cash recovery against an already-
 * expensed balance, booked to 4050 Bad Debt Recovery Income (see
 * LoanLedgerPoster::postProvisionAdjustment()). Renamed for clarity before
 * this audit trail accumulates real rows under the old, misleading name.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE `loan_provisions` MODIFY `action` ENUM('Post','Reverse','Recover') NOT NULL");
        DB::table('loan_provisions')->where('action', 'Reverse')->update(['action' => 'Recover']);
        DB::statement("ALTER TABLE `loan_provisions` MODIFY `action` ENUM('Post','Recover') NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE `loan_provisions` MODIFY `action` ENUM('Post','Reverse','Recover') NOT NULL");
        DB::table('loan_provisions')->where('action', 'Recover')->update(['action' => 'Reverse']);
        DB::statement("ALTER TABLE `loan_provisions` MODIFY `action` ENUM('Post','Reverse') NOT NULL");
    }
};
