<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks a bank account as having a sanctioned overdraft facility. A non-null
 * overdraft_limit is what makes an account "an OD account": its current_balance
 * (already a signed column) is then allowed to go negative, but no further than
 * -overdraft_limit (enforced in BankTransactionController). A negative bank
 * balance is the company's overdraft utilisation — presented on the Balance
 * Sheet under liabilities rather than as a negative asset (see
 * AccountingReportController::balanceSheet()).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_accounts', function (Blueprint $table) {
            $table->decimal('overdraft_limit', 15, 2)->unsigned()->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('bank_accounts', function (Blueprint $table) {
            $table->dropColumn('overdraft_limit');
        });
    }
};
