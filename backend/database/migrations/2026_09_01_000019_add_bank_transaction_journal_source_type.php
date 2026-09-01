<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 9 gap fix — journal_entries.source_type never had 'BankTransaction',
 * yet BankTransactionController has always tagged its journal entry with it
 * (LedgerPoster::post(..., ['source_type' => 'BankTransaction'])). Every
 * manual bank movement's journal entry silently truncated its source_type to
 * '' (MySQL non-strict). The reverse path keys off the journal_entry_id FK so
 * nothing broke visibly, but reports that filter journal_entries by source
 * never saw bank transactions. Adds the value.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE `journal_entries`
            MODIFY `source_type` ENUM(
                'Loan','Customer','LoanPayment','LoanProvision','LoanReschedule',
                'SpeedDraft','SpeedDraftPayment','SpeedDraftRenewal',
                'Pawning','PawningPayment','PawningAuctionItem',
                'FdDeposit','FixedDeposit','FdWithdrawal','FdInterestPayment','FdPartPayment','FdRenewal',
                'CashTransfer','Expense','PaymentVoucher','PettyCashReplenishment','PettyCashVoucher',
                'SalesExecutiveCommission','ManualAdjustment','OpeningBalance',
                'Borrowing','BorrowingTransaction','FinanceTransaction','BankTransaction'
            ) DEFAULT NULL
        SQL);
    }

    public function down(): void
    {
        // Non-destructive.
    }
};
