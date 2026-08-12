<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Small, receipt-level spend drawn straight out of a petty_cash_funds float —
 * the low-value counterpart to expenses+payment_vouchers, without that
 * flow's request/approve/pay steps (an imprest float exists precisely so a
 * custodian can disburse small amounts without routing each one through
 * approval). Reuses expense_categories for the GL split, same as Expense,
 * so petty cash spend rolls up into the same expense reporting rather than
 * a parallel category list.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('petty_cash_vouchers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 24)->unique();
            $table->foreignId('petty_cash_fund_id')->constrained('petty_cash_funds')->restrictOnDelete();
            $table->foreignId('expense_category_id')->constrained('expense_categories')->restrictOnDelete();
            $table->foreignId('vendor_id')->nullable()->constrained('vendors')->nullOnDelete();
            $table->decimal('amount', 15, 2)->unsigned();
            $table->string('description', 255)->nullable();
            $table->date('spent_at');
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('status', ['Issued', 'Voided'])->default('Issued');
            $table->string('void_reason', 255)->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('voided_at')->nullable();
            // The GL posting this spend created — see LedgerPoster.
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->timestamps();
            $table->index(['petty_cash_fund_id', 'spent_at']);
            $table->index('status');
        });

        DB::statement('ALTER TABLE `petty_cash_vouchers` ADD CONSTRAINT `chk_pcv_amount` CHECK (`amount` > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('petty_cash_vouchers');
    }
};
