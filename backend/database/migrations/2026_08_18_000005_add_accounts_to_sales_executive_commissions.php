<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SalesExecutiveCommissionController::pay() records payment_method/
 * payment_amount but never moved a till/bank balance or posted a journal
 * entry — real cash going out the door with zero ledger trail, the same
 * disconnect class as charge_categories (2026_08_18_000004) just one step
 * further downstream: here the account to move wasn't even captured. Mirrors
 * expenses' cash_account_id/bank_account_id (create_expenses_table).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_executive_commissions', function (Blueprint $table) {
            $table->foreignId('cash_account_id')->nullable()->after('payment_method')->constrained('cash_accounts')->nullOnDelete();
            $table->foreignId('bank_account_id')->nullable()->after('cash_account_id')->constrained('bank_accounts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sales_executive_commissions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cash_account_id');
            $table->dropConstrainedForeignId('bank_account_id');
        });
    }
};
