<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mirrors expense_categories' chart_of_account_id (2026_08_01_103002):
 * charge_categories had no GL link at all, so every loan charge — whatever
 * category the user picked — silently posted to the single 4020 control
 * account in LoanLedgerPoster::splitLines(). Nullable + no backfill here,
 * same reasoning as expense_categories: existing categories keep falling
 * back to 4020 until someone links them in Masters > Charge Categories.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('charge_categories', function (Blueprint $table) {
            $table->foreignId('chart_of_account_id')->nullable()->after('name')->constrained('chart_of_accounts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('charge_categories', function (Blueprint $table) {
            $table->dropConstrainedForeignId('chart_of_account_id');
        });
    }
};
