<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A Daily Savings product — the template a savings_accounts row snapshots its
 * rate / method / limits from at open time (later product edits never
 * retroactively change an already-open account, same convention as
 * funding_products -> fixed_deposits).
 *
 * interest_calc_method decides which running balance SavingsInterestCalculator
 * uses over a posting period:
 *   min_monthly_balance : the lowest balance the account held during the month
 *                         (the classic Sri Lankan pass-book savings basis)
 *   daily_balance       : sum of each day's closing balance / days
 *   average_balance     : (opening + closing) / 2
 *
 * interest_posting_frequency drives how often the manual interest run covers
 * (a run still has to be created and approved — nothing posts automatically).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('savings_products', function (Blueprint $table) {
            $table->id();
            $table->string('name', 128);
            $table->string('code', 24)->unique();
            $table->decimal('interest_rate_percent', 6, 3)->unsigned();
            $table->enum('interest_calc_method', ['min_monthly_balance', 'daily_balance', 'average_balance'])->default('min_monthly_balance');
            $table->enum('interest_posting_frequency', ['monthly', 'quarterly', 'annually'])->default('monthly');
            $table->decimal('min_opening_deposit', 15, 2)->unsigned()->default(0);
            $table->decimal('min_balance', 15, 2)->unsigned()->default(0);
            // Charged on each withdrawal (0 = none). Booked Dr 2050 / Cr 4045.
            $table->decimal('withdrawal_fee', 15, 2)->unsigned()->default(0);
            // Charged once at account open (0 = none).
            $table->decimal('ledger_fee', 15, 2)->unsigned()->default(0);
            // An account with no customer-driven transaction for this many days
            // is flagged Dormant (0 = never auto-dormant).
            $table->smallInteger('dormancy_days')->unsigned()->default(0);
            // Template for this product's generated account numbers. Null = system default.
            $table->string('reference_format', 64)->nullable();
            $table->smallInteger('sort_order')->unsigned()->default(0);
            $table->enum('status', ['Active', 'Inactive'])->default('Active');
            $table->timestamps();
        });

        DB::statement('ALTER TABLE `savings_products` ADD CONSTRAINT `chk_savings_products_min_balance` CHECK (`min_balance` <= `min_opening_deposit` OR `min_opening_deposit` = 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('savings_products');
    }
};
