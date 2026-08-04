<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_vouchers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 24)->unique();
            $table->foreignId('expense_id')->nullable()->constrained('expenses')->nullOnDelete();
            $table->foreignId('vendor_id')->nullable()->constrained('vendors')->nullOnDelete();
            $table->decimal('amount', 15, 2)->unsigned();
            $table->enum('payment_method', ['Cash', 'Bank', 'Cheque'])->default('Cash');
            $table->dateTime('paid_at');
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            // Which till/bank account the money actually left — exactly one of
            // these is set, matching payment_method (Cash -> cash_account_id,
            // Bank/Cheque -> bank_account_id) — enforced by the CHECK below.
            $table->foreignId('cash_account_id')->nullable()->constrained('cash_accounts')->restrictOnDelete();
            $table->foreignId('bank_account_id')->nullable()->constrained('bank_accounts')->restrictOnDelete();
            $table->string('cheque_no', 20)->nullable();
            // 'Voided' is reserved — nothing in the app sets it yet (an
            // issued voucher is never currently reversed), kept for when
            // that workflow exists rather than requiring a later migration.
            $table->enum('status', ['Issued', 'Voided'])->default('Issued');
            // The GL posting this voucher created — see LedgerPoster.
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->timestamps();
            $table->index('paid_at');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE `payment_vouchers` ADD CONSTRAINT `chk_pv_funding_source` CHECK (
                (`payment_method` = 'Cash' AND `cash_account_id` IS NOT NULL) OR
                (`payment_method` IN ('Bank', 'Cheque') AND `bank_account_id` IS NOT NULL)
            )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_vouchers');
    }
};
