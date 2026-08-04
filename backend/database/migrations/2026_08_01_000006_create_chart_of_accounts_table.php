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
 * row (see bank_accounts.chart_of_account_id / cash_accounts.chart_of_account_id),
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
            $table->enum('account_type', ['Asset', 'Liability', 'Equity', 'Income', 'Expense']);
            // Which side of the account increases it — Assets/Expenses = Debit,
            // Liabilities/Equity/Income = Credit, EXCEPT contra accounts (e.g.
            // 1190 Provision for Loan Losses: Asset-type but Credit balance,
            // netting against its parent asset) — a real, intentional pattern
            // in this chart of accounts, not an edge case, so it's stored
            // explicitly rather than inferred from account_type, and NOT
            // constrained by a CHECK: the correlation has legitimate
            // exceptions a simple two-column rule can't express.
            $table->enum('normal_balance', ['Debit', 'Credit']);
            $table->foreignId('parent_id')->nullable()->constrained('chart_of_accounts')->restrictOnDelete();
            $table->boolean('is_system')->default(false); // seeded control account — protected from deletion in the UI
            $table->enum('status', ['Active', 'Inactive'])->default('Active');
            $table->timestamps();
            $table->index('account_type');
            $table->index('parent_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chart_of_accounts');
    }
};
