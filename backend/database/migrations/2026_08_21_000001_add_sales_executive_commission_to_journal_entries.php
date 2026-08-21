<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SalesExecutiveCommissionController::pay() has always posted a journal
 * entry with source_type='SalesExecutiveCommission' — that value was never
 * added to the enum, so every real commission payment threw "Data
 * truncated for column 'source_type'" (a 500) the moment LedgerPoster::post()
 * tried to insert the row. Found while testing the void() companion method
 * added alongside this fix — pay() itself was broken independently of that.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE `journal_entries`
            MODIFY `source_type` ENUM('Loan','Customer','LoanPayment','SpeedDraft','SpeedDraftPayment','Pawning','PawningPayment','PawningAuctionItem','FdDeposit','FixedDeposit','FdWithdrawal','FdInterestPayment','FdRenewal','CashTransfer','Expense','PaymentVoucher','PettyCashReplenishment','PettyCashVoucher','SalesExecutiveCommission','ManualAdjustment','OpeningBalance') DEFAULT NULL
        SQL);
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE `journal_entries`
            MODIFY `source_type` ENUM('Loan','Customer','LoanPayment','SpeedDraft','SpeedDraftPayment','Pawning','PawningPayment','PawningAuctionItem','FdDeposit','FixedDeposit','FdWithdrawal','FdInterestPayment','FdRenewal','CashTransfer','Expense','PaymentVoucher','PettyCashReplenishment','PettyCashVoucher','ManualAdjustment','OpeningBalance') DEFAULT NULL
        SQL);
    }
};
