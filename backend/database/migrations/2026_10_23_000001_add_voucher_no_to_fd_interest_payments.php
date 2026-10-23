<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every FD interest payout now gets its own payment voucher number (the slip
 * that goes with the bank transfer), the same way FD withdrawals and part
 * payments already do. Rows paid before this migration keep a NULL voucher_no.
 *
 *  - fd_interest_payments.voucher_no (unique, nullable)
 *  - number_sequences.module          += fd_interest
 *  - document_number_log.source_type  += FdInterestPayment
 *
 * The full enum lists are re-declared (MySQL MODIFY replaces the whole set), so
 * every value already in place is carried forward verbatim.
 */
return new class extends Migration
{
    private const MODULES_BASE = [
        'loan', 'customer', 'payment', 'fd_deposit', 'fixed_deposit', 'fd_withdrawal',
        'fd_part_payment', 'journal', 'finance_transaction', 'finance_request', 'manual_journal', 'expense',
        'payment_voucher', 'petty_cash_fund', 'petty_cash_replenishment', 'petty_cash_voucher',
        'speed_draft', 'speed_draft_payment', 'pawning', 'pawning_auction', 'borrowing', 'borrowing_txn',
        'savings_account', 'savings_txn', 'savings_collection', 'savings_interest',
    ];

    private const DOCLOG_BASE = [
        'Loan', 'Customer', 'FacilityPayment', 'SpeedDraft', 'Pawning', 'PawningAuction',
        'FdDeposit', 'FixedDeposit', 'FdWithdrawal', 'FdPartPayment', 'JournalEntry', 'Expense',
        'PaymentVoucher', 'PettyCashFund', 'PettyCashReplenishment', 'PettyCashVoucher',
        'Borrowing', 'BorrowingTransaction', 'ManualJournalEntry', 'FinanceTransaction', 'FinanceRequest',
        'SavingsAccount', 'SavingsTransaction', 'SavingsCollectionSheet', 'SavingsInterestRun',
    ];

    public function up(): void
    {
        Schema::table('fd_interest_payments', function (Blueprint $table) {
            $table->string('voucher_no', 32)->nullable()->unique()->after('installment_no');
        });

        $this->setModules(array_merge(self::MODULES_BASE, ['fd_interest']));
        $this->setDocLog(array_merge(self::DOCLOG_BASE, ['FdInterestPayment']));
    }

    public function down(): void
    {
        Schema::table('fd_interest_payments', function (Blueprint $table) {
            $table->dropUnique(['voucher_no']);
            $table->dropColumn('voucher_no');
        });

        DB::table('number_sequences')->where('module', 'fd_interest')->delete();
        $this->setModules(self::MODULES_BASE);
        DB::table('document_number_log')->where('source_type', 'FdInterestPayment')->update(['source_type' => null]);
        $this->setDocLog(self::DOCLOG_BASE);
    }

    private function enumList(array $values): string
    {
        return collect($values)->map(fn ($v) => "'".$v."'")->implode(',');
    }

    private function setModules(array $v): void
    {
        DB::statement('ALTER TABLE `number_sequences` MODIFY `module` ENUM('.$this->enumList($v).') NOT NULL');
    }

    private function setDocLog(array $v): void
    {
        DB::statement('ALTER TABLE `document_number_log` MODIFY `source_type` ENUM('.$this->enumList($v).') DEFAULT NULL');
    }
};
