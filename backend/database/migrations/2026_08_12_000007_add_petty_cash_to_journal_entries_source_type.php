<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * journal_entries.source_type also needs the two petty-cash postings this
 * module makes (PettyCashReplenishment, PettyCashVoucher) — the fund itself
 * never posts directly, only its replenishments and spend do, so
 * PettyCashFund isn't added here (unlike document_number_log, which logs a
 * reference number for all three). See 2026_08_12_000006 for that one.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE `journal_entries`
            MODIFY `source_type` ENUM(
                'Loan', 'Customer', 'LoanPayment', 'SpeedDraft', 'SpeedDraftPayment',
                'FdDeposit', 'FixedDeposit', 'FdWithdrawal', 'FdInterestPayment', 'FdRenewal',
                'CashTransfer', 'Expense', 'PaymentVoucher', 'PettyCashReplenishment', 'PettyCashVoucher',
                'ManualAdjustment', 'OpeningBalance'
            ) DEFAULT NULL
        SQL);
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE `journal_entries`
            MODIFY `source_type` ENUM(
                'Loan', 'Customer', 'LoanPayment', 'SpeedDraft', 'SpeedDraftPayment',
                'FdDeposit', 'FixedDeposit', 'FdWithdrawal', 'FdInterestPayment', 'FdRenewal',
                'CashTransfer', 'Expense', 'PaymentVoucher', 'ManualAdjustment', 'OpeningBalance'
            ) DEFAULT NULL
        SQL);
    }
};
