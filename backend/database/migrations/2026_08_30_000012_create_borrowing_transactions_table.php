<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Movements against a borrowing after it's opened:
 *
 *  - Drawdown          : an additional draw on the facility (e.g. a top-up on a
 *                        party borrowing). Cash in.
 *  - InterestPayment   : interest paid to the lender — Dr 5110, Cr cash/bank.
 *  - PrincipalRepayment: part-repayment of the principal — Dr 21xx, Cr cash/bank.
 *  - Settlement        : final repayment closing the borrowing.
 *
 * Same "only Cash posts to the GL/Cashbook" convention as the FD posters.
 * Voiding a transaction reverses its journal entry and its effect on
 * borrowings.outstanding_amount; only the borrowing's most recent transaction
 * may be voided (enforced in the controller), so there's never a stacked
 * out-of-order reversal to reconcile.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('borrowing_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('borrowing_id')->constrained('borrowings')->cascadeOnDelete();
            $table->string('voucher_no', 24)->unique();
            $table->enum('kind', ['Drawdown', 'InterestPayment', 'PrincipalRepayment', 'Settlement']);
            $table->decimal('amount', 15, 2)->unsigned();
            $table->date('txn_date');
            $table->enum('payment_method', ['Cash', 'Bank', 'Cheque']);
            $table->foreignId('cash_account_id')->nullable()->constrained('cash_accounts')->nullOnDelete();
            $table->foreignId('bank_account_id')->nullable()->constrained('bank_accounts')->nullOnDelete();
            $table->string('reference_no', 64)->nullable();
            $table->text('notes')->nullable();
            $table->decimal('outstanding_before', 15, 2)->unsigned()->default(0);
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['borrowing_id', 'txn_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('borrowing_transactions');
    }
};
