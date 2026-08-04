<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Replaces the pre-redesign fixed_deposits migration (plain string columns,
 * no public_uuid, no CHECK constraints). Every rate/method/amount field here
 * is a snapshot copied from the funding product at issue/renewal time — later
 * product edits never retroactively change an already-issued FD (see
 * FixedDepositController::createFixedDeposit()).
 *
 * status is Active|Matured|Renewed|Closed, but 'Matured' is never actually
 * written — it's a live-computed state (maturity_date <= today while still
 * Active), same "never persisted" convention as Loan's OD/penalty figures.
 * It's kept as a real enum value (not dropped) because the column needs to
 * express it somewhere for API responses, and reads more honestly as part of
 * the status vocabulary than as a magic client-side computed flag.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fixed_deposits', function (Blueprint $table) {
            $table->id();
            $table->char('public_uuid', 36);
            $table->string('fd_number', 24)->unique();
            $table->foreignId('branch_id')->constrained('branches');
            $table->foreignId('customer_id')->constrained('customers');
            $table->foreignId('funding_product_id')->constrained('funding_products');
            $table->decimal('principal_amount', 15, 2)->unsigned();
            $table->decimal('interest_rate_percent', 6, 3)->unsigned();
            $table->enum('interest_payment_method', ['monthly', 'maturity']);
            $table->smallInteger('term_months')->unsigned();
            $table->date('start_date');
            $table->date('maturity_date');
            $table->decimal('monthly_interest_amount', 15, 2)->unsigned()->nullable();
            $table->decimal('total_interest_amount', 15, 2)->unsigned();
            $table->decimal('maturity_amount', 15, 2)->unsigned();
            $table->date('next_interest_date')->nullable();
            $table->enum('status', ['Active', 'Matured', 'Renewed', 'Closed'])->default('Active');
            $table->foreignId('renewed_from_id')->nullable()->constrained('fixed_deposits')->nullOnDelete();
            $table->foreignId('renewed_to_id')->nullable()->constrained('fixed_deposits')->nullOnDelete();
            $table->date('closed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique('public_uuid');
            $table->index('branch_id');
            $table->index('customer_id');
            $table->index('funding_product_id');
            $table->index(['status', 'maturity_date']);
        });

        DB::statement('ALTER TABLE `fixed_deposits` ADD CONSTRAINT `chk_fd_dates` CHECK (`maturity_date` > `start_date`)');
        DB::statement('ALTER TABLE `fixed_deposits` ADD CONSTRAINT `chk_fd_principal` CHECK (`principal_amount` > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('fixed_deposits');
    }
};
