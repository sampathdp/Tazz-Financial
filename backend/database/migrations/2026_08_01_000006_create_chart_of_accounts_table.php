<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The general ledger's account list (spec 3.8, schema.sql SECTION 7). Every
 * account's balance is a derived SUM(debit)-SUM(credit) over journal_lines,
 * computed on read — there is no cached balance column here, so there is
 * never a second copy of a balance to fall out of sync with the ledger.
 *
 * A branch cash till or bank account is not a separate kind of thing from a
 * GL account that happens to reference one — it IS a leaf chart_of_accounts
 * row (see cash_till_links / bank_account_links in the next migration),
 * child of the 1010/1020 control accounts seeded below.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chart_of_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('code', 16)->unique();
            $table->string('name', 128);
            $table->string('type', 16); // Asset | Liability | Equity | Income | Expense
            // Which side of the account increases it — Assets/Expenses = Debit,
            // Liabilities/Equity/Income = Credit. Stored explicitly (not inferred
            // from `type` in report code) so every report agrees on sign with zero
            // room for two implementations to disagree.
            $table->string('normal_balance', 8); // Debit | Credit
            $table->foreignId('parent_id')->nullable()->constrained('chart_of_accounts')->restrictOnDelete();
            $table->boolean('is_system')->default(false); // seeded control account — protected from deletion in the UI
            $table->string('status', 16)->default('Active');
            $table->timestamps();
            $table->index('type');
            $table->index('parent_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chart_of_accounts');
    }
};
