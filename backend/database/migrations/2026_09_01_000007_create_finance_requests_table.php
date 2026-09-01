<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Finance module — Phase 2. The request queue: the single front door for
 * every outbound payment and transfer.
 *
 * A source module (Loans, Expenses, ...) raises a finance_request after its
 * own business approval, then reacts to a FinanceRequestExecuted /
 * FinanceRequestRejected event — it never touches cash itself. Finance
 * reviews, the approval engine routes it by amount tier, an executor picks
 * the source account and runs it through FinancePostingService, which is
 * what actually moves money and links back here via
 * finance_transactions.finance_request_id.
 *
 * Request != transaction: nothing here moves a balance. Money moves only
 * when a finance_transaction is executed.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE `number_sequences`
            MODIFY `module` ENUM('loan','customer','payment','fd_deposit','fixed_deposit','fd_withdrawal','fd_part_payment','journal','finance_transaction','finance_request','manual_journal','expense','payment_voucher','petty_cash_fund','petty_cash_replenishment','petty_cash_voucher','speed_draft','speed_draft_payment','pawning','pawning_auction','borrowing','borrowing_txn') NOT NULL
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE `document_number_log`
            MODIFY `source_type` ENUM('Loan','Customer','FacilityPayment','SpeedDraft','Pawning','PawningAuction','FdDeposit','FixedDeposit','FdWithdrawal','FdPartPayment','JournalEntry','Expense','PaymentVoucher','PettyCashFund','PettyCashReplenishment','PettyCashVoucher','Borrowing','BorrowingTransaction','ManualJournalEntry','FinanceTransaction','FinanceRequest') DEFAULT NULL
        SQL);

        Schema::create('finance_requests', function (Blueprint $t) {
            $t->id();
            $t->string('code', 32)->unique();

            $t->enum('request_type', [
                'LoanDisbursement', 'ExpensePayment', 'SupplierPayment', 'Refund', 'Advance',
                'CashFloat', 'BranchFunding', 'CommissionPayout', 'FdPayout', 'CashTransfer', 'Other',
            ]);
            $t->string('source_module', 64)->nullable();
            $t->unsignedBigInteger('source_id')->nullable();
            $t->unsignedBigInteger('branch_id')->nullable();

            $t->decimal('amount', 15, 2);
            $t->char('currency', 3)->default('LKR');

            $t->enum('payee_type', ['Customer', 'Vendor', 'User', 'Other'])->nullable();
            $t->unsignedBigInteger('payee_id')->nullable();
            $t->string('payee_name', 191)->nullable();

            $t->enum('preferred_method', ['Cash', 'Bank', 'Cheque'])->nullable();
            $t->string('purpose', 255)->nullable();
            $t->string('notes', 500)->nullable();
            $t->enum('priority', ['Normal', 'Urgent'])->default('Normal');

            $t->enum('status', [
                'Draft', 'Submitted', 'PendingApproval', 'Approved', 'Rejected',
                'ReadyForPayment', 'Processing', 'Paid', 'Completed', 'Cancelled', 'Reversed',
            ])->default('Draft');

            $t->unsignedBigInteger('approval_request_id')->nullable();
            $t->unsignedBigInteger('source_cash_account_id')->nullable();
            $t->unsignedBigInteger('source_bank_account_id')->nullable();
            $t->unsignedBigInteger('finance_transaction_id')->nullable();
            $t->string('idempotency_key', 80)->nullable()->unique();

            $t->unsignedBigInteger('requested_by')->nullable();
            $t->unsignedBigInteger('approved_by')->nullable();
            $t->unsignedBigInteger('executed_by')->nullable();
            $t->dateTime('requested_at')->nullable();
            $t->dateTime('approved_at')->nullable();
            $t->dateTime('executed_at')->nullable();
            $t->dateTime('closed_at')->nullable();
            $t->string('reject_reason', 255)->nullable();
            $t->json('meta')->nullable();

            $t->timestamps();

            $t->foreign('branch_id', 'fk_fr_branch')->references('id')->on('branches')->nullOnDelete();
            $t->foreign('approval_request_id', 'fk_fr_approval_request')->references('id')->on('approval_requests')->nullOnDelete();
            $t->foreign('source_cash_account_id', 'fk_fr_cash_account')->references('id')->on('cash_accounts')->nullOnDelete();
            $t->foreign('source_bank_account_id', 'fk_fr_bank_account')->references('id')->on('bank_accounts')->nullOnDelete();
            $t->foreign('finance_transaction_id', 'fk_fr_finance_txn')->references('id')->on('finance_transactions')->nullOnDelete();
            $t->foreign('requested_by', 'fk_fr_requested_by')->references('id')->on('users')->nullOnDelete();
            $t->foreign('approved_by', 'fk_fr_approved_by')->references('id')->on('users')->nullOnDelete();
            $t->foreign('executed_by', 'fk_fr_executed_by')->references('id')->on('users')->nullOnDelete();

            $t->index(['status', 'branch_id'], 'ix_fr_status_branch');
            $t->index(['request_type', 'status'], 'ix_fr_type_status');
            $t->index(['source_module', 'source_id'], 'ix_fr_source');
        });

        DB::statement('ALTER TABLE `finance_requests` ADD CONSTRAINT `chk_fr_amount` CHECK (`amount` > 0)');

        // Now that finance_requests exists, wire the transaction's back-link.
        Schema::table('finance_transactions', function (Blueprint $t) {
            $t->foreign('finance_request_id', 'fk_ftx_finance_request')->references('id')->on('finance_requests')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('finance_transactions', function (Blueprint $t) {
            $t->dropForeign('fk_ftx_finance_request');
        });
        Schema::dropIfExists('finance_requests');

        DB::statement(<<<'SQL'
            ALTER TABLE `document_number_log`
            MODIFY `source_type` ENUM('Loan','Customer','FacilityPayment','SpeedDraft','Pawning','PawningAuction','FdDeposit','FixedDeposit','FdWithdrawal','FdPartPayment','JournalEntry','Expense','PaymentVoucher','PettyCashFund','PettyCashReplenishment','PettyCashVoucher','Borrowing','BorrowingTransaction','ManualJournalEntry','FinanceTransaction') DEFAULT NULL
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE `number_sequences`
            MODIFY `module` ENUM('loan','customer','payment','fd_deposit','fixed_deposit','fd_withdrawal','fd_part_payment','journal','finance_transaction','manual_journal','expense','payment_voucher','petty_cash_fund','petty_cash_replenishment','petty_cash_voucher','speed_draft','speed_draft_payment','pawning','pawning_auction','borrowing','borrowing_txn') NOT NULL
        SQL);
    }
};
