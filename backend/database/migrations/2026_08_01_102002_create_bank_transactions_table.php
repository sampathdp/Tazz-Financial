<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_account_id')->constrained('bank_accounts')->restrictOnDelete();
            // Added — bank transactions previously had no link at all to the
            // general ledger, despite existing to record the same money
            // movement journal_entries/journal_lines track independently.
            // Nullable and not yet written by any controller (see
            // BankTransactionController) — the column exists so a future
            // posting pass doesn't need a schema change, not because
            // anything populates it today.
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->date('txn_date');
            $table->enum('transaction_type', ['Deposit', 'Withdrawal', 'TransferIn', 'TransferOut']);
            $table->string('reference_no', 64)->nullable();
            $table->string('description', 255)->nullable();
            $table->decimal('amount', 15, 2)->unsigned();
            /** Snapshot of the account's running balance right after this entry posted — not retroactively recalculated if earlier entries change. */
            $table->decimal('balance_after', 15, 2);
            $table->enum('status', ['Cleared', 'Pending'])->default('Cleared');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['bank_account_id', 'txn_date']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_transactions');
    }
};
