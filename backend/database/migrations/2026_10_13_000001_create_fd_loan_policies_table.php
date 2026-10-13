<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lending rules for "loan against FD", one row per funding product (the FD
 * product decides what its holders may borrow). Lives apart from
 * funding_products so the FD product table stays a pure deposit definition.
 *
 * Loan rate is derived, not typed per loan: rate_mode='spread' charges
 * (fixed_deposits.interest_rate_percent + rate_spread_percent), the usual
 * "FD rate + 2%" rule; 'fixed' charges fixed_rate_percent regardless of the
 * FD. Both are annual (rate_period on the facility records it). The result
 * is snapshotted onto credit_facilities at application time, so editing a
 * policy never changes loans already issued.
 *
 * max_ltv_percent caps loan as a share of the FD's lien base (principal).
 * Defaults to 90 - interest accrues on the FD, so principal-only LTV leaves
 * headroom for accrued interest on the loan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fd_loan_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('funding_product_id')->unique()->constrained('funding_products');
            $table->decimal('max_ltv_percent', 5, 2)->unsigned()->default(90);
            $table->enum('rate_mode', ['spread', 'fixed'])->default('spread');
            $table->decimal('rate_spread_percent', 6, 3)->unsigned()->default(2);
            $table->decimal('fixed_rate_percent', 6, 3)->unsigned()->nullable();
            $table->decimal('min_loan_amount', 15, 2)->unsigned()->default(0);
            $table->decimal('max_loan_amount', 15, 2)->unsigned()->nullable();
            $table->decimal('processing_fee_percent', 5, 2)->unsigned()->default(0);
            // FD must still have at least this many days to run when the loan starts.
            $table->smallInteger('min_days_to_maturity')->unsigned()->default(30);
            // true: loan term may not run past the FD's maturity date.
            $table->boolean('cap_term_to_maturity')->default(true);
            // true: on FD maturity / loan default, FD proceeds are applied to the loan automatically.
            $table->boolean('auto_adjust_on_maturity')->default(false);
            $table->enum('status', ['Active', 'Inactive'])->default('Active');
            $table->timestamps();
        });

        DB::statement('ALTER TABLE `fd_loan_policies` ADD CONSTRAINT `chk_fdlp_ltv` CHECK (`max_ltv_percent` > 0 AND `max_ltv_percent` <= 100)');
        DB::statement("ALTER TABLE `fd_loan_policies` ADD CONSTRAINT `chk_fdlp_rate` CHECK (`rate_mode` <> 'fixed' OR `fixed_rate_percent` IS NOT NULL)");
        DB::statement('ALTER TABLE `fd_loan_policies` ADD CONSTRAINT `chk_fdlp_amounts` CHECK (`max_loan_amount` IS NULL OR `max_loan_amount` >= `min_loan_amount`)');
    }

    public function down(): void
    {
        Schema::dropIfExists('fd_loan_policies');
    }
};
