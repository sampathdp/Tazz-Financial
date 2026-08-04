<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * cashbook_entries.source_type already anticipated 'FdDeposit'/'FdWithdrawal'
 * (matching journal_entries' fuller FD source_type list) but was missing
 * 'FdInterestPayment' — needed now that FdCashbookPoster posts a cash-out
 * entry for each interest installment paid, alongside deposits and
 * withdrawals.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE `cashbook_entries`
            MODIFY `source_type` ENUM('LoanPayment','Expense','CashTransfer','FdDeposit','FdWithdrawal','FdInterestPayment','ManualAdjustment') DEFAULT NULL
        SQL);
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE `cashbook_entries`
            MODIFY `source_type` ENUM('LoanPayment','Expense','CashTransfer','FdDeposit','FdWithdrawal','ManualAdjustment') DEFAULT NULL
        SQL);
    }
};
