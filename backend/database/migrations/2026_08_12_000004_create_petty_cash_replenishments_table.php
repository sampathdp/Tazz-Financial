<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tops a petty_cash_funds row back up from a cash till or bank account —
 * the "reimbursement" step of the imprest cycle. Same funding-source shape
 * (exactly one of cash_account_id/bank_account_id, matching funding_method)
 * as payment_vouchers, and the same Issued/Voided + void audit columns as
 * facility_payments, since a mis-recorded top-up needs to be reversible
 * without deleting the row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('petty_cash_replenishments', function (Blueprint $table) {
            $table->id();
            $table->string('code', 24)->unique();
            $table->foreignId('petty_cash_fund_id')->constrained('petty_cash_funds')->restrictOnDelete();
            $table->decimal('amount', 15, 2)->unsigned();
            $table->enum('funding_method', ['Cash', 'Bank'])->default('Cash');
            $table->foreignId('cash_account_id')->nullable()->constrained('cash_accounts')->restrictOnDelete();
            $table->foreignId('bank_account_id')->nullable()->constrained('bank_accounts')->restrictOnDelete();
            $table->dateTime('paid_at');
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('status', ['Issued', 'Voided'])->default('Issued');
            $table->string('void_reason', 255)->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('voided_at')->nullable();
            // The GL posting this top-up created — see LedgerPoster.
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->timestamps();
            $table->index(['petty_cash_fund_id', 'paid_at']);
            $table->index('status');
        });

        DB::statement('ALTER TABLE `petty_cash_replenishments` ADD CONSTRAINT `chk_pcr_amount` CHECK (`amount` > 0)');
        DB::statement(<<<'SQL'
            ALTER TABLE `petty_cash_replenishments` ADD CONSTRAINT `chk_pcr_funding_source` CHECK (
                (`funding_method` = 'Cash' AND `cash_account_id` IS NOT NULL) OR
                (`funding_method` = 'Bank' AND `bank_account_id` IS NOT NULL)
            )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('petty_cash_replenishments');
    }
};
