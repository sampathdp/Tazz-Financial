<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Widens document_number_log.source_type for the three new petty-cash
 * models — see App\Services\NumberGenerator::attach()'s class_basename()
 * and the 2026_08_03_000029 migration this mirrors.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE `document_number_log`
            MODIFY `source_type` ENUM('Loan','Customer','FacilityPayment','SpeedDraft','FdDeposit','FixedDeposit','FdWithdrawal','JournalEntry','Expense','PaymentVoucher','PettyCashFund','PettyCashReplenishment','PettyCashVoucher') DEFAULT NULL
        SQL);
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE `document_number_log`
            MODIFY `source_type` ENUM('Loan','Customer','FacilityPayment','SpeedDraft','FdDeposit','FixedDeposit','FdWithdrawal','JournalEntry','Expense','PaymentVoucher') DEFAULT NULL
        SQL);
    }
};
