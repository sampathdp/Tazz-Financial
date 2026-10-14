<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Audit B-9: bank_transactions, savings_transactions, payment_vouchers and sales_executive_commissions had no
 * branch of their own, so every branch report needed a join through a parent. Adds a nullable, indexed
 * branch_id to each and backfills it from the parent record. New rows get it from a model hook.
 */
return new class extends Migration
{
    /** table => [parent table, local FK column] */
    private const TABLES = [
        'bank_transactions' => ['bank_accounts', 'bank_account_id'],
        'savings_transactions' => ['savings_accounts', 'savings_account_id'],
        'payment_vouchers' => ['expenses', 'expense_id'],
        'sales_executive_commissions' => ['credit_facilities', 'credit_facility_id'],
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table => [$parent, $fk]) {
            if (!Schema::hasColumn($table, 'branch_id')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->unsignedBigInteger('branch_id')->nullable()->index();
                });
            }
            DB::statement("UPDATE {$table} t JOIN {$parent} p ON p.id = t.{$fk} SET t.branch_id = p.branch_id WHERE t.branch_id IS NULL");
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::TABLES) as $table) {
            if (Schema::hasColumn($table, 'branch_id')) {
                Schema::table($table, function (Blueprint $t) use ($table) {
                    $t->dropIndex([ 'branch_id' ]);
                    $t->dropColumn('branch_id');
                });
            }
        }
    }
};
