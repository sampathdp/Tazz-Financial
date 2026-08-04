<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Moved to run after chart_of_accounts/journal_entries (was originally
 * created before either existed) — the GL leaf link and journal_entry_id
 * below both need those tables to already be there.
 *
 * chart_of_account_id replaces the separate bank_account_links table: a bank
 * account's GL leaf row is a 1:1 fact about the account, not a many-to-many
 * relationship that needs its own table (bank_account_links' own unique
 * constraints already enforced 1:1 in both directions — this is that same
 * constraint expressed as a plain foreign key instead of a whole extra table).
 * Nullable, not NOT NULL: the leaf account's own code embeds this row's id
 * (see ChartOfAccount::forBankAccount()), which doesn't exist until this row
 * is first inserted — nullable-then-immediately-backfilled-in-the-same-
 * transaction is the standard resolution for that kind of mutual reference,
 * still guaranteed non-null in practice by BankAccountController::store()
 * never committing a row without it.
 *
 * bank_name (free text, repeated on every bank account) is replaced by
 * bank_id, a real reference to the banks lookup table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('chart_of_account_id')->nullable()->unique()->constrained('chart_of_accounts')->restrictOnDelete();
            $table->foreignId('bank_id')->constrained('banks')->restrictOnDelete();
            $table->string('name', 128);
            // The bank's own branch name, e.g. "Commercial Bank - Colombo" —
            // an external bank's branch, not one of this company's own
            // `branches` rows, so it stays free text rather than an FK.
            $table->string('bank_branch_name', 128)->nullable();
            $table->string('account_number', 34)->unique();
            $table->enum('account_type', ['Savings', 'Current', 'Fixed Deposit'])->default('Savings');
            $table->char('currency', 3)->default('LKR');
            $table->decimal('opening_balance', 15, 2)->unsigned()->default(0);
            // Maintained cache, not derived on read: source of truth is the
            // latest bank_transactions.balance_after for this account (kept
            // for the same performance reason as cash_accounts.current_balance).
            $table->decimal('current_balance', 15, 2)->default(0);
            $table->enum('status', ['Active', 'Inactive'])->default('Active');
            $table->string('notes', 255)->nullable();
            $table->timestamps();
            $table->index('branch_id', 'ix_bank_accounts_branch_id');
            $table->index('bank_id', 'ix_bank_accounts_bank_id');
            $table->index('status', 'ix_bank_accounts_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_accounts');
    }
};
