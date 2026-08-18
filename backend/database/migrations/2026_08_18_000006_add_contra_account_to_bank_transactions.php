<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * bank_transactions already had journal_entry_id (see create_bank_transactions_
 * table's own docblock: added ahead of time, never written by any controller)
 * but nothing to post FROM — a manual Deposit/Withdrawal/Transfer moves
 * current_balance with no record of the other side of the double entry.
 * Adds a contra account of one of three kinds so BankTransactionController can
 * post a real balanced journal entry: another till (Cash), another bank
 * account (Bank, which also moves that account's own current_balance — this
 * is what actually finishes a bank-to-bank transfer in one row instead of
 * relying on the operator to log two uncoordinated legs), or a GL account
 * directly (Ledger — bank charges, interest, an owner injection, etc.).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_transactions', function (Blueprint $table) {
            $table->enum('contra_kind', ['Cash', 'Bank', 'Ledger'])->nullable()->after('bank_account_id');
            $table->foreignId('contra_cash_account_id')->nullable()->after('contra_kind')->constrained('cash_accounts')->nullOnDelete();
            $table->foreignId('contra_bank_account_id')->nullable()->after('contra_cash_account_id')->constrained('bank_accounts')->nullOnDelete();
            $table->foreignId('contra_chart_of_account_id')->nullable()->after('contra_bank_account_id')->constrained('chart_of_accounts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bank_transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('contra_cash_account_id');
            $table->dropConstrainedForeignId('contra_bank_account_id');
            $table->dropConstrainedForeignId('contra_chart_of_account_id');
            $table->dropColumn('contra_kind');
        });
    }
};
