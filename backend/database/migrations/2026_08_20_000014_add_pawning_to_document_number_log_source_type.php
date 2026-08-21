<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Pawning tickets generate a code via NumberGenerator same as Loan/SpeedDraft — class_basename(Pawning) needs a slot in the enum. */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE `document_number_log`
            MODIFY `source_type` ENUM('Loan','Customer','FacilityPayment','SpeedDraft','Pawning','FdDeposit','FixedDeposit','FdWithdrawal','JournalEntry','Expense','PaymentVoucher','PettyCashFund','PettyCashReplenishment','PettyCashVoucher') DEFAULT NULL
        SQL);
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE `document_number_log`
            MODIFY `source_type` ENUM('Loan','Customer','FacilityPayment','SpeedDraft','FdDeposit','FixedDeposit','FdWithdrawal','JournalEntry','Expense','PaymentVoucher','PettyCashFund','PettyCashReplenishment','PettyCashVoucher') DEFAULT NULL
        SQL);
    }
};
