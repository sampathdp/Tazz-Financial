<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bridges the existing `cash_accounts` / `bank_accounts` tables to a
 * chart_of_accounts leaf row, without touching either table or the code that
 * already depends on them (CashbookPoster, CashAccountController, ...).
 *
 * This is a deliberately transitional shape: cash_accounts/bank_accounts
 * remain the system of record for today's Daily Cash Report, till balances
 * and reconciliation, while chart_of_accounts/journal_lines becomes the
 * system of record for Trial Balance/P&L/Balance Sheet/General Ledger. A
 * later migration can fold each link table's metadata directly into
 * chart_of_accounts and retire cash_accounts/bank_accounts once the cashbook
 * and bank screens are re-pointed at the ledger — see the "Migrating without
 * a big-bang cutover" guide. Until then, one `unique` FK each way keeps the
 * mapping strictly 1:1 in both directions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_till_links', function (Blueprint $table) {
            $table->foreignId('account_id')->primary()->constrained('chart_of_accounts')->cascadeOnDelete();
            $table->foreignId('cash_account_id')->unique()->constrained('cash_accounts')->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('bank_account_links', function (Blueprint $table) {
            $table->foreignId('account_id')->primary()->constrained('chart_of_accounts')->cascadeOnDelete();
            $table->foreignId('bank_account_id')->unique()->constrained('bank_accounts')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_account_links');
        Schema::dropIfExists('cash_till_links');
    }
};
