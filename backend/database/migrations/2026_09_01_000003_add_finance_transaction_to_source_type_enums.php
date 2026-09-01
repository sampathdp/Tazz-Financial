<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Finance module — Phase 1. Wires the new FinanceTransaction source into
 * every enum that already lists the other money sources, same widening every
 * previous new source type has needed (see
 * add_borrowing_to_source_type_enums):
 *
 *  - number_sequences.module      += finance_transaction  (NumberGenerator
 *                                    counter for the FTX- code)
 *  - document_number_log.source_type += FinanceTransaction (NumberGenerator::
 *                                    attach() keys off class_basename())
 *  - journal_entries.source_type  += FinanceTransaction (LedgerPoster meta)
 *  - cashbook_entries.source_type += FinanceTransaction (the cashbook read
 *                                    model, once it is fed from finance_
 *                                    transactions)
 *
 * Only the one new value is added — the number_sequences enum has been
 * carrying pre-existing rows with an empty-string module (invalid values
 * coerced in during its MyISAM era); widening it to the values NumberGenerator
 * actually uses is a separate cleanup, deliberately not bundled here.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE `number_sequences`
            MODIFY `module` ENUM('loan','customer','payment','fd_deposit','fixed_deposit','fd_withdrawal','journal','expense','payment_voucher','speed_draft','speed_draft_payment','finance_transaction') NOT NULL
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE `document_number_log`
            MODIFY `source_type` ENUM('Loan','Customer','FacilityPayment','SpeedDraft','Pawning','PawningAuction','FdDeposit','FixedDeposit','FdWithdrawal','FdPartPayment','JournalEntry','Expense','PaymentVoucher','PettyCashFund','PettyCashReplenishment','PettyCashVoucher','Borrowing','BorrowingTransaction','ManualJournalEntry','FinanceTransaction') DEFAULT NULL
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE `journal_entries`
            MODIFY `source_type` ENUM('Loan','Customer','LoanPayment','LoanProvision','LoanReschedule','SpeedDraft','SpeedDraftPayment','SpeedDraftRenewal','Pawning','PawningPayment','PawningAuctionItem','FdDeposit','FixedDeposit','FdWithdrawal','FdInterestPayment','FdRenewal','CashTransfer','Expense','PaymentVoucher','PettyCashReplenishment','PettyCashVoucher','SalesExecutiveCommission','ManualAdjustment','OpeningBalance','Borrowing','BorrowingTransaction','FinanceTransaction') DEFAULT NULL
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE `cashbook_entries`
            MODIFY `source_type` ENUM('LoanPayment','Expense','CashTransfer','FdDeposit','FdWithdrawal','FdInterestPayment','FdPartPayment','ManualAdjustment','Borrowing','BorrowingTransaction','FinanceTransaction') DEFAULT NULL
        SQL);
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE `number_sequences`
            MODIFY `module` ENUM('loan','customer','payment','fd_deposit','fixed_deposit','fd_withdrawal','journal','expense','payment_voucher','speed_draft','speed_draft_payment') NOT NULL
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE `document_number_log`
            MODIFY `source_type` ENUM('Loan','Customer','FacilityPayment','SpeedDraft','Pawning','PawningAuction','FdDeposit','FixedDeposit','FdWithdrawal','FdPartPayment','JournalEntry','Expense','PaymentVoucher','PettyCashFund','PettyCashReplenishment','PettyCashVoucher','Borrowing','BorrowingTransaction','ManualJournalEntry') DEFAULT NULL
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
};
