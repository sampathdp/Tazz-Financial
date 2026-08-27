<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * LoanLedgerPoster::postProvisionAdjustment() posts a journal entry with
 * source_type='LoanProvision' — same widening this enum has needed for
 * every previous new source type (see
 * 2026_08_26_000004_add_speed_draft_renewal_to_journal_entries.php).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE `journal_entries`
            MODIFY `source_type` ENUM('Loan','Customer','LoanPayment','LoanProvision','SpeedDraft','SpeedDraftPayment','SpeedDraftRenewal','Pawning','PawningPayment','PawningAuctionItem','FdDeposit','FixedDeposit','FdWithdrawal','FdInterestPayment','FdRenewal','CashTransfer','Expense','PaymentVoucher','PettyCashReplenishment','PettyCashVoucher','SalesExecutiveCommission','ManualAdjustment','OpeningBalance') DEFAULT NULL
        SQL);
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE `journal_entries`
            MODIFY `source_type` ENUM('Loan','Customer','LoanPayment','SpeedDraft','SpeedDraftPayment','SpeedDraftRenewal','Pawning','PawningPayment','PawningAuctionItem','FdDeposit','FixedDeposit','FdWithdrawal','FdInterestPayment','FdRenewal','CashTransfer','Expense','PaymentVoucher','PettyCashReplenishment','PettyCashVoucher','SalesExecutiveCommission','ManualAdjustment','OpeningBalance') DEFAULT NULL
        SQL);
    }
};
