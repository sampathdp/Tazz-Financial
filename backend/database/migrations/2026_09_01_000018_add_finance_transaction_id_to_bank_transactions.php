<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 9d prep — bank_transactions gets a finance_transaction_id link, the
 * same shape cashbook_entries already has. Since Phase 9 every manual bank
 * movement is booked through FinancePostingService (balance + GL + the
 * immutable finance_transactions row), and the bank_transactions row is
 * recorded alongside it carrying the bank-only fields the ledger doesn't
 * model (reference_no, contra kind, Cleared/Pending status). This link is
 * what a later cutover turns the table into a view on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_transactions', function (Blueprint $table) {
            $table->foreignId('finance_transaction_id')->nullable()->after('journal_entry_id')
                ->constrained('finance_transactions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bank_transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('finance_transaction_id');
        });
    }
};
