<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `expense_categories.account_id` has existed since the table was first
 * created (2026_08_01_000004) as an unconstrained placeholder for exactly
 * this — see that migration's docblock. chart_of_accounts now exists, so
 * this wires the FK it was always meant to have.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expense_categories', function (Blueprint $table) {
            $table->foreign('account_id')->references('id')->on('chart_of_accounts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('expense_categories', function (Blueprint $table) {
            $table->dropForeign(['account_id']);
        });
    }
};
