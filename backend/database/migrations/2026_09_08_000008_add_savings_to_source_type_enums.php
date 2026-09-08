<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Daily Savings — wires the new sources into every enum that already lists the
 * other money sources, same widening every new module has needed (see
 * add_finance_transaction_to_source_type_enums / add_borrowing_to_source_type_enums):
 *
 *  - number_sequences.module      += savings_account / savings_txn /
 *                                    savings_collection / savings_interest
 *  - document_number_log.source_type += SavingsAccount / SavingsTransaction /
 *                                    SavingsCollectionSheet / SavingsInterestRun
 *                                    (NumberGenerator::attach() keys off class_basename())
 *  - journal_entries.source_type  += SavingsTransaction / SavingsInterestRun
 *                                    (SavingsLedgerPoster keys the GL entry to these)
 *  - cashbook_entries.category    += 'Savings' (the mirror category
 *                                    SavingsLedgerPoster passes via meta.cashbook_category)
 *
 * cashbook_entries.source_type needs nothing new — a savings cash leg mirrors
 * through FinancePostingService as source_type='FinanceTransaction', already listed.
 *
 * The full enum lists are re-declared (MySQL MODIFY replaces the whole set), so
 * every value already in place — finance_request / FinanceRequest included — is
 * carried forward here verbatim.
 */
return new class extends Migration
{
    private const MODULES_BASE = [
        'loan', 'customer', 'payment', 'fd_deposit', 'fixed_deposit', 'fd_withdrawal',
        'fd_part_payment', 'journal', 'finance_transaction', 'finance_request', 'manual_journal', 'expense',
        'payment_voucher', 'petty_cash_fund', 'petty_cash_replenishment', 'petty_cash_voucher',
        'speed_draft', 'speed_draft_payment', 'pawning', 'pawning_auction', 'borrowing', 'borrowing_txn',
    ];

    private const MODULES_NEW = ['savings_account', 'savings_txn', 'savings_collection', 'savings_interest'];

    private const DOCLOG_BASE = [
        'Loan', 'Customer', 'FacilityPayment', 'SpeedDraft', 'Pawning', 'PawningAuction',
        'FdDeposit', 'FixedDeposit', 'FdWithdrawal', 'FdPartPayment', 'JournalEntry', 'Expense',
        'PaymentVoucher', 'PettyCashFund', 'PettyCashReplenishment', 'PettyCashVoucher',
        'Borrowing', 'BorrowingTransaction', 'ManualJournalEntry', 'FinanceTransaction', 'FinanceRequest',
    ];

    private const DOCLOG_NEW = ['SavingsAccount', 'SavingsTransaction', 'SavingsCollectionSheet', 'SavingsInterestRun'];

    private const JOURNAL_BASE = [
        'Loan', 'Customer', 'LoanPayment', 'LoanProvision', 'LoanReschedule',
        'SpeedDraft', 'SpeedDraftPayment', 'SpeedDraftRenewal',
        'Pawning', 'PawningPayment', 'PawningAuctionItem',
        'FdDeposit', 'FixedDeposit', 'FdWithdrawal', 'FdInterestPayment', 'FdPartPayment', 'FdRenewal',
        'CashTransfer', 'Expense', 'PaymentVoucher', 'PettyCashReplenishment', 'PettyCashVoucher',
        'SalesExecutiveCommission', 'ManualAdjustment', 'OpeningBalance',
        'Borrowing', 'BorrowingTransaction', 'FinanceTransaction', 'BankTransaction',
    ];

    private const JOURNAL_NEW = ['SavingsTransaction', 'SavingsInterestRun'];

    public function up(): void
    {
        $this->setModules(array_merge(self::MODULES_BASE, self::MODULES_NEW));
        $this->setDocLog(array_merge(self::DOCLOG_BASE, self::DOCLOG_NEW));
        $this->setJournal(array_merge(self::JOURNAL_BASE, self::JOURNAL_NEW));
        DB::statement("ALTER TABLE `cashbook_entries` MODIFY `category` ENUM('Loan Collection','Expense','Transfer','Deposit','Savings','Other') NOT NULL");
    }

    public function down(): void
    {
        $this->setModules(self::MODULES_BASE);
        $this->setDocLog(self::DOCLOG_BASE);
        $this->setJournal(self::JOURNAL_BASE);
        DB::statement("ALTER TABLE `cashbook_entries` MODIFY `category` ENUM('Loan Collection','Expense','Transfer','Deposit','Other') NOT NULL");
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

    private function setJournal(array $v): void
    {
        DB::statement('ALTER TABLE `journal_entries` MODIFY `source_type` ENUM('.$this->enumList($v).') DEFAULT NULL');
    }
};
