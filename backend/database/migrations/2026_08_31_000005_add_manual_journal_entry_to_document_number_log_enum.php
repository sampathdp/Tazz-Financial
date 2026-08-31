<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * NumberGenerator::attach() stamps class_basename() into
 * document_number_log.source_type — 'ManualJournalEntry' for the new staging
 * model. Same enum-widening every new numbered source type has needed.
 * (journal_entries.source_type already lists 'ManualAdjustment' / 'OpeningBalance',
 * which is what the real posting uses.)
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE `document_number_log`
            MODIFY `source_type` ENUM('Loan','Customer','FacilityPayment','SpeedDraft','Pawning','PawningAuction','FdDeposit','FixedDeposit','FdWithdrawal','FdPartPayment','JournalEntry','Expense','PaymentVoucher','PettyCashFund','PettyCashReplenishment','PettyCashVoucher','Borrowing','BorrowingTransaction','ManualJournalEntry') DEFAULT NULL
        SQL);
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE `document_number_log`
            MODIFY `source_type` ENUM('Loan','Customer','FacilityPayment','SpeedDraft','Pawning','PawningAuction','FdDeposit','FixedDeposit','FdWithdrawal','FdPartPayment','JournalEntry','Expense','PaymentVoucher','PettyCashFund','PettyCashReplenishment','PettyCashVoucher','Borrowing','BorrowingTransaction') DEFAULT NULL
        SQL);
    }
};
