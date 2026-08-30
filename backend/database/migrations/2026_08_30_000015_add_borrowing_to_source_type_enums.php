<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Wires the new Borrowing / BorrowingTransaction sources into every enum that
 * already lists the FD sources — NumberGenerator::attach(),
 * BorrowingCashbookPoster and BorrowingLedgerPoster all key off
 * class_basename() = 'Borrowing' / 'BorrowingTransaction'. Same enum widening
 * every previous new source type has needed.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE `document_number_log`
            MODIFY `source_type` ENUM('Loan','Customer','FacilityPayment','SpeedDraft','Pawning','PawningAuction','FdDeposit','FixedDeposit','FdWithdrawal','FdPartPayment','JournalEntry','Expense','PaymentVoucher','PettyCashFund','PettyCashReplenishment','PettyCashVoucher','Borrowing','BorrowingTransaction') DEFAULT NULL
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE `journal_entries`
            MODIFY `source_type` ENUM('Loan','Customer','LoanPayment','LoanProvision','LoanReschedule','SpeedDraft','SpeedDraftPayment','SpeedDraftRenewal','Pawning','PawningPayment','PawningAuctionItem','FdDeposit','FixedDeposit','FdWithdrawal','FdInterestPayment','FdRenewal','CashTransfer','Expense','PaymentVoucher','PettyCashReplenishment','PettyCashVoucher','SalesExecutiveCommission','ManualAdjustment','OpeningBalance','Borrowing','BorrowingTransaction') DEFAULT NULL
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE `cashbook_entries`
            MODIFY `source_type` ENUM('LoanPayment','Expense','CashTransfer','FdDeposit','FdWithdrawal','FdInterestPayment','FdPartPayment','ManualAdjustment','Borrowing','BorrowingTransaction') DEFAULT NULL
        SQL);
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE `document_number_log`
            MODIFY `source_type` ENUM('Loan','Customer','FacilityPayment','SpeedDraft','Pawning','PawningAuction','FdDeposit','FixedDeposit','FdWithdrawal','FdPartPayment','JournalEntry','Expense','PaymentVoucher','PettyCashFund','PettyCashReplenishment','PettyCashVoucher') DEFAULT NULL
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE `journal_entries`
            MODIFY `source_type` ENUM('Loan','Customer','LoanPayment','LoanProvision','LoanReschedule','SpeedDraft','SpeedDraftPayment','SpeedDraftRenewal','Pawning','PawningPayment','PawningAuctionItem','FdDeposit','FixedDeposit','FdWithdrawal','FdInterestPayment','FdRenewal','CashTransfer','Expense','PaymentVoucher','PettyCashReplenishment','PettyCashVoucher','SalesExecutiveCommission','ManualAdjustment','OpeningBalance') DEFAULT NULL
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE `cashbook_entries`
            MODIFY `source_type` ENUM('LoanPayment','Expense','CashTransfer','FdDeposit','FdWithdrawal','FdInterestPayment','FdPartPayment','ManualAdjustment') DEFAULT NULL
        SQL);
    }
};
