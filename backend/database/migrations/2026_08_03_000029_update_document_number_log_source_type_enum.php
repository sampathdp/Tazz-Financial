<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * document_number_log.source_type is populated via
 * NumberGenerator::attach()'s class_basename($record) — since Loan/
 * SpeedDraft payments now share one FacilityPayment model instead of two
 * separate LoanPayment/SpeedDraftPayment classes, the enum's old values for
 * those two are dead (nothing will ever write them again) and too narrow to
 * hold the new basename.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE `document_number_log`
            MODIFY `source_type` ENUM('Loan','Customer','FacilityPayment','SpeedDraft','FdDeposit','FixedDeposit','FdWithdrawal','JournalEntry','Expense','PaymentVoucher') DEFAULT NULL
        SQL);
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE `document_number_log`
            MODIFY `source_type` ENUM('Loan','Customer','LoanPayment','SpeedDraft','SpeedDraftPayment','FdDeposit','FixedDeposit','FdWithdrawal','JournalEntry','Expense','PaymentVoucher') DEFAULT NULL
        SQL);
    }
};
