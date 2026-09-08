<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A customer's Daily Savings account — a running balance held as a demand
 * liability (2050). current_balance is a maintained cache kept in step by
 * SavingsAccountService (the one writer); the authoritative figure is the
 * sum of savings_transactions running deltas, and the GL 2050 balance for
 * that customer set is the accounting cross-check.
 *
 * Rate / method / limit columns are a snapshot from savings_products at open
 * time. status:
 *   Active  : normal
 *   Dormant : no customer-driven transaction for product.dormancy_days
 *             (interest posting still applies; a fresh deposit reactivates)
 *   Frozen  : administratively blocked — no deposit or withdrawal
 *   Closed  : balance paid out, account settled
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('savings_accounts', function (Blueprint $table) {
            $table->id();
            $table->char('public_uuid', 36);
            $table->string('account_number', 24)->unique();
            $table->foreignId('branch_id')->constrained('branches');
            $table->foreignId('customer_id')->constrained('customers');
            $table->foreignId('savings_product_id')->constrained('savings_products');
            $table->decimal('interest_rate_percent', 6, 3)->unsigned();
            $table->enum('interest_calc_method', ['min_monthly_balance', 'daily_balance', 'average_balance']);
            $table->enum('interest_posting_frequency', ['monthly', 'quarterly', 'annually']);
            $table->decimal('min_balance', 15, 2)->unsigned()->default(0);
            $table->decimal('withdrawal_fee', 15, 2)->unsigned()->default(0);
            $table->date('open_date');
            $table->decimal('current_balance', 15, 2)->default(0);
            // Interest accrued but not yet capitalised — informational only
            // until an interest run posts it (then reset to 0).
            $table->decimal('interest_accrued', 15, 2)->default(0);
            $table->date('last_interest_posted_on')->nullable();
            $table->date('last_activity_on')->nullable();
            $table->enum('status', ['Active', 'Dormant', 'Frozen', 'Closed'])->default('Active');
            $table->date('closed_on')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique('public_uuid');
            $table->index('branch_id');
            $table->index('customer_id');
            $table->index('savings_product_id');
            $table->index(['status', 'branch_id']);
        });

        DB::statement('ALTER TABLE `savings_accounts` ADD CONSTRAINT `chk_savings_accounts_rate` CHECK (`interest_rate_percent` >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('savings_accounts');
    }
};
