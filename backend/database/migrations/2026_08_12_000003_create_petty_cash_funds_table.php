<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The imprest float itself — a branch-level petty cash "till" with a
 * custodian and a ceiling (float_limit) it's topped back up to. Structurally
 * mirrors cash_accounts (branch_id, chart_of_account_id, name,
 * current_balance, status) since a fund IS a small cash account in every
 * way that matters to the ledger; it's a separate table rather than a row in
 * cash_accounts because a fund additionally carries a custodian and a float
 * ceiling that a till doesn't have, and its spend goes through
 * petty_cash_vouchers (small, uncontrolled, receipt-level spend) rather than
 * the approval-gated Expense flow.
 *
 * current_balance starts at 0 and only ever moves via petty_cash_replenishments
 * (up) and petty_cash_vouchers (down) — see those controllers. chart_of_account_id
 * is set lazily on first use via ChartOfAccount::forPettyCashFund(), same as
 * cash_accounts/bank_accounts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('petty_cash_funds', function (Blueprint $table) {
            $table->id();
            $table->string('code', 24)->unique();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('custodian_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('chart_of_account_id')->nullable()->unique()->constrained('chart_of_accounts')->restrictOnDelete();
            $table->string('name', 128);
            $table->decimal('float_limit', 15, 2)->unsigned();
            /** Maintained cache — source of truth is the linked chart_of_accounts leaf's posted balance (see ChartOfAccount::balancesAsOf). */
            $table->decimal('current_balance', 15, 2)->default(0);
            $table->enum('status', ['Active', 'Inactive'])->default('Active');
            $table->timestamps();
            $table->index(['branch_id', 'status']);
        });

        DB::statement('ALTER TABLE `petty_cash_funds` ADD CONSTRAINT `chk_pcf_float_limit` CHECK (`float_limit` > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('petty_cash_funds');
    }
};
