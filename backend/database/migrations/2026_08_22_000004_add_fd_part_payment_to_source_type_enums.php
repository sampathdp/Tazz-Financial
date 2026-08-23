<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Wires the new FdPartPayment source into every enum that already lists FdWithdrawal/FdInterestPayment — NumberGenerator::attach(), FdCashbookPoster, and FdLedgerPoster all key off class_basename(FdPartPayment) = 'FdPartPayment'. */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE `document_number_log`
            MODIFY `source_type` ENUM('Loan','Customer','FacilityPayment','SpeedDraft','Pawning','PawningAuction','FdDeposit','FixedDeposit','FdWithdrawal','FdPartPayment','JournalEntry','Expense','PaymentVoucher','PettyCashFund','PettyCashReplenishment','PettyCashVoucher') DEFAULT NULL
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE `journal_entries`
            MODIFY `source_type` ENUM('Loan','Customer','LoanPayment','SpeedDraft','SpeedDraftPayment','Pawning','PawningPayment','PawningAuctionItem','FdDeposit','FixedDeposit','FdWithdrawal','FdInterestPayment','FdPartPayment','FdRenewal','CashTransfer','Expense','PaymentVoucher','PettyCashReplenishment','PettyCashVoucher','SalesExecutiveCommission','ManualAdjustment','OpeningBalance') DEFAULT NULL
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE `cashbook_entries`
            MODIFY `source_type` ENUM('LoanPayment','Expense','CashTransfer','FdDeposit','FdWithdrawal','FdInterestPayment','FdPartPayment','ManualAdjustment') DEFAULT NULL
        SQL);
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE `document_number_log`
            MODIFY `source_type` ENUM('Loan','Customer','FacilityPayment','SpeedDraft','Pawning','PawningAuction','FdDeposit','FixedDeposit','FdWithdrawal','JournalEntry','Expense','PaymentVoucher','PettyCashFund','PettyCashReplenishment','PettyCashVoucher') DEFAULT NULL
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE `journal_entries`
            MODIFY `source_type` ENUM('Loan','Customer','LoanPayment','SpeedDraft','SpeedDraftPayment','Pawning','PawningPayment','PawningAuctionItem','FdDeposit','FixedDeposit','FdWithdrawal','FdInterestPayment','FdRenewal','CashTransfer','Expense','PaymentVoucher','PettyCashReplenishment','PettyCashVoucher','SalesExecutiveCommission','ManualAdjustment','OpeningBalance') DEFAULT NULL
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE `cashbook_entries`
            MODIFY `source_type` ENUM('LoanPayment','Expense','CashTransfer','FdDeposit','FdWithdrawal','FdInterestPayment','ManualAdjustment') DEFAULT NULL
        SQL);
    }
};
