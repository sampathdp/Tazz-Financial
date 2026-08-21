<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Two things auction settlement needs that plain redemption doesn't:
 *
 * 2110 Pawning Auction Surplus Payable (Liability) — when a lot's hammer
 * price exceeds what the pledger owed, the excess is legally theirs, not
 * lender profit (see PawningAuctionController's docblock) — it sits here as
 * a liability until claimed/refunded or forfeited after the claim period,
 * same idempotent shape as 2026_08_20_000015's capital/income pair.
 *
 * journal_entries.source_type widened for PawningAuctionItem — auction
 * sale/surplus-refund/surplus-forfeiture postings are keyed to the lot, not
 * the ticket (Pawning) or a FacilityPayment (PawningPayment), since auction
 * proceeds don't flow through facility_payments at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!DB::table('chart_of_accounts')->where('code', '2110')->exists()) {
            $now = now();
            DB::table('chart_of_accounts')->insert([
                'code' => '2110',
                'name' => 'Pawning Auction Surplus Payable',
                'account_type' => 'Liability',
                'normal_balance' => 'Credit',
                'parent_id' => null,
                'is_system' => true,
                'status' => 'Active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        DB::statement(<<<'SQL'
            ALTER TABLE `journal_entries`
            MODIFY `source_type` ENUM('Loan','Customer','LoanPayment','SpeedDraft','SpeedDraftPayment','Pawning','PawningPayment','PawningAuctionItem','FdDeposit','FixedDeposit','FdWithdrawal','FdInterestPayment','FdRenewal','CashTransfer','Expense','PaymentVoucher','PettyCashReplenishment','PettyCashVoucher','ManualAdjustment','OpeningBalance') DEFAULT NULL
        SQL);
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE `journal_entries`
            MODIFY `source_type` ENUM('Loan','Customer','LoanPayment','SpeedDraft','SpeedDraftPayment','Pawning','PawningPayment','FdDeposit','FixedDeposit','FdWithdrawal','FdInterestPayment','FdRenewal','CashTransfer','Expense','PaymentVoucher','PettyCashReplenishment','PettyCashVoucher','ManualAdjustment','OpeningBalance') DEFAULT NULL
        SQL);

        DB::table('chart_of_accounts')->where('code', '2110')->delete();
    }
};
