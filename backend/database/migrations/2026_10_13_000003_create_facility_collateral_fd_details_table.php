<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 1:1 with a facility_collateral row of collateral_type='FixedDeposit' - this
 * IS the lien. One row = one FD pledged to one loan.
 *
 * Lien lifecycle (lien_status):
 *   Active   - FD frozen: withdraw / renew / part-payment must refuse.
 *   Released - loan closed or cancelled; FD free again.
 *   Settled  - loan defaulted / FD matured and FD proceeds were applied to the
 *              loan (settlement_withdrawal_id points at the fd_withdrawals row).
 *
 * `active_fd_id` is a generated column that equals fixed_deposit_id only while
 * the lien is Active (NULL otherwise). Its UNIQUE index makes it impossible,
 * at DB level and race-free, to pledge the same FD to two loans - no
 * application-side locking needed for that rule. Released/Settled rows keep
 * history because their active_fd_id is NULL (MySQL allows many NULLs).
 *
 * fd_*_snapshot columns freeze the FD's terms at pledge time for audit and
 * for the print documents; credit_facility_id is denormalized from
 * facility_collateral so "which loan holds this FD" is a single indexed
 * lookup with no join.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('facility_collateral_fd_details', function (Blueprint $table) {
            // Short explicit names - auto-generated ones exceed MySQL's 64-char limit.
            $table->foreignId('facility_collateral_id');
            $table->primary('facility_collateral_id', 'pk_fcfd');
            $table->foreign('facility_collateral_id', 'fk_fcfd_collateral')->references('id')->on('facility_collateral')->cascadeOnDelete();
            $table->foreignId('credit_facility_id')->constrained('credit_facilities')->cascadeOnDelete();
            $table->foreignId('fixed_deposit_id')->constrained('fixed_deposits');
            $table->foreignId('fd_loan_policy_id')->nullable()->constrained('fd_loan_policies')->nullOnDelete();

            $table->decimal('fd_principal_snapshot', 15, 2)->unsigned();
            $table->decimal('fd_rate_snapshot', 6, 3)->unsigned();
            $table->date('fd_maturity_snapshot');
            $table->decimal('ltv_percent', 5, 2)->unsigned();
            $table->decimal('lien_amount', 15, 2)->unsigned();
            $table->decimal('max_loan_value', 15, 2)->unsigned();

            $table->enum('lien_status', ['Active', 'Released', 'Settled'])->default('Active');
            $table->dateTime('marked_at');
            $table->foreignId('marked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('released_at')->nullable();
            $table->foreignId('released_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('release_reason', 255)->nullable();
            $table->decimal('settled_amount', 15, 2)->unsigned()->nullable();
            $table->foreignId('settlement_withdrawal_id')->nullable()->constrained('fd_withdrawals')->nullOnDelete();
            $table->timestamps();

            $table->index('credit_facility_id');
            $table->index(['fixed_deposit_id', 'lien_status'], 'idx_fcfd_fd_status');
        });

        DB::statement("ALTER TABLE `facility_collateral_fd_details` ADD COLUMN `active_fd_id` BIGINT UNSIGNED GENERATED ALWAYS AS (IF(`lien_status` = 'Active', `fixed_deposit_id`, NULL)) VIRTUAL");
        DB::statement('ALTER TABLE `facility_collateral_fd_details` ADD UNIQUE INDEX `uq_fcfd_active_fd` (`active_fd_id`)');
        DB::statement('ALTER TABLE `facility_collateral_fd_details` ADD CONSTRAINT `chk_fcfd_lien` CHECK (`lien_amount` > 0 AND `lien_amount` <= `fd_principal_snapshot`)');
        DB::statement('ALTER TABLE `facility_collateral_fd_details` ADD CONSTRAINT `chk_fcfd_ltv` CHECK (`ltv_percent` > 0 AND `ltv_percent` <= 100)');
        DB::statement("ALTER TABLE `facility_collateral_fd_details` ADD CONSTRAINT `chk_fcfd_closed` CHECK (`lien_status` = 'Active' OR `released_at` IS NOT NULL)");
    }

    public function down(): void
    {
        Schema::dropIfExists('facility_collateral_fd_details');
    }
};
