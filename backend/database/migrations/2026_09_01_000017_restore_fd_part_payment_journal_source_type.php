<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 9 gap fix — journal_entries.source_type lost 'FdPartPayment' along
 * the way (a later migration re-declared the enum from an older list). It was
 * added by 2026_08_22_000004 and FdLedgerPoster still keys the part-payment
 * journal entry off class_basename(FdPartPayment) = 'FdPartPayment', so a
 * Cash-method FD part-payment silently truncated its journal entry's
 * source_type. Adds the value back.
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
                'Borrowing','BorrowingTransaction','FinanceTransaction'
            ) DEFAULT NULL
        SQL);
    }

    public function down(): void
    {
        // Non-destructive — leaving the extra enum value in place is harmless.
    }
};
