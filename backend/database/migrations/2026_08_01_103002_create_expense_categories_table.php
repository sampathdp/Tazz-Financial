<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Moved to run after chart_of_accounts (was originally created before it
 * existed, with account_id added unconstrained and wired to a real FK only
 * once chart_of_accounts existed — both steps fold into one here). Renamed
 * account_id -> chart_of_account_id: a bare "account_id" is ambiguous
 * schema-wide (cash/bank accounts, GL accounts, and now expense categories'
 * link are all "accounts" of different kinds) — every other FK to
 * chart_of_accounts in this schema is already named chart_of_account_id.
 *
 * Deliberately NOT one GL account per category (see
 * 2026_08_01_000012_seed_chart_of_accounts's docblock): 5010 stays a single
 * control account and this column is nullable — most categories point at it
 * only implicitly through that shared default, not a row here.
 *
 * No longer backfilled from master_categories(type=expense_category) — that
 * table and its banks/expense_categories migration-time backfills were
 * historical, one-time data migrations with nothing left to carry forward on
 * a fresh install (see the deleted 2026_07_28_000001/2026_08_01_000005
 * master_categories migrations).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 128);
            $table->string('code', 24)->unique();
            $table->string('description', 255)->nullable();
            $table->foreignId('chart_of_account_id')->nullable()->constrained('chart_of_accounts')->nullOnDelete();
            $table->enum('status', ['Active', 'Inactive'])->default('Active');
            $table->smallInteger('sort_order')->unsigned()->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_categories');
    }
};
