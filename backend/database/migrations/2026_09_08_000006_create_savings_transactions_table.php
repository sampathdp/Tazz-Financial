<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One movement on a savings account. running_balance is the account balance
 * immediately after this transaction — a snapshot written by
 * SavingsAccountService so the pass-book / statement never has to re-sum the
 * whole history to show a line's balance.
 *
 * type:
 *   Deposit        : cash/bank in                              (direction In)
 *   Withdrawal     : cash/bank out                             (direction Out)
 *   InterestPosting: interest capitalised by an interest run   (direction In)
 *   Fee            : ledger / withdrawal / dormancy fee        (direction Out)
 *   Adjustment     : admin correction                          (either)
 *   OpeningBalance : migrated starting balance                 (direction In)
 *
 * A Cash-method Deposit/Withdrawal/Fee also moves the branch till and posts
 * the GL through FinancePostingService (see SavingsLedgerPoster); finance_
 * transaction_id links that row. InterestPosting is GL-only (Dr 5120 / Cr
 * 2050), no cash — finance_transaction_id stays null.
 *
 * Never edited. A mistake is corrected by voiding (status Voided, a paired
 * reversing transaction, and the GL reversal) — same convention as
 * FdWithdrawal / FacilityPayment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('savings_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('txn_number', 24)->unique();
            $table->foreignId('savings_account_id')->constrained('savings_accounts');
            $table->date('txn_date');
            $table->enum('type', ['Deposit', 'Withdrawal', 'InterestPosting', 'Fee', 'Adjustment', 'OpeningBalance']);
            $table->enum('direction', ['In', 'Out']);
            $table->decimal('amount', 15, 2)->unsigned();
            $table->decimal('running_balance', 15, 2);
            $table->enum('payment_method', ['Cash', 'Bank', 'Cheque'])->nullable();
            $table->foreignId('bank_id')->nullable()->constrained('banks')->nullOnDelete();
            $table->string('reference_no', 64)->nullable();
            $table->foreignId('collection_sheet_id')->nullable()->constrained('savings_collection_sheets')->nullOnDelete();
            $table->foreignId('interest_run_id')->nullable()->constrained('savings_interest_runs')->nullOnDelete();
            $table->foreignId('finance_transaction_id')->nullable()->constrained('finance_transactions')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->enum('status', ['Posted', 'Voided'])->default('Posted');
            $table->foreignId('void_of_id')->nullable()->constrained('savings_transactions')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['savings_account_id', 'id']);
            $table->index('collection_sheet_id');
            $table->index('interest_run_id');
            $table->index(['type', 'txn_date']);
        });

        DB::statement('ALTER TABLE `savings_transactions` ADD CONSTRAINT `chk_savings_txn_amount` CHECK (`amount` > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('savings_transactions');
    }
};
