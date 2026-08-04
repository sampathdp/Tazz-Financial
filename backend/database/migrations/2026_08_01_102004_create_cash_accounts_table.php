<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Moved to run after chart_of_accounts (was originally created before it
 * existed). chart_of_account_id replaces the separate cash_till_links table
 * — same reasoning, and same nullable-then-immediately-backfilled shape, as
 * bank_accounts.chart_of_account_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('chart_of_account_id')->nullable()->unique()->constrained('chart_of_accounts')->restrictOnDelete();
            $table->string('name', 128);
            $table->decimal('opening_balance', 15, 2)->unsigned()->default(0);
            /** Maintained cache — source of truth is the latest cashbook_entries.balance_after for this account. */
            $table->decimal('current_balance', 15, 2)->default(0);
            $table->enum('status', ['Active', 'Inactive'])->default('Active');
            $table->timestamps();
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_accounts');
    }
};
