<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Consolidates what was originally six migrations (create, then separately
 * add reference_format / collection_days / grace_period_days / commission
 * fields / default_charge_rate_percent) into the final shape directly —
 * there's no data to preserve across those steps on a fresh install.
 * penalty_rate_percent/penalty_method never make it in at all: they were
 * later confirmed dead (fully superseded by default_charge_rate_percent, see
 * 2026_08_03_000007) and are just omitted here rather than added then dropped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loan_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('loan_types');
            $table->string('name', 128);
            $table->string('code', 24)->unique();
            // Template for this type's generated loan codes, e.g.
            // "{BRANCH}-{CODE}-{YEAR:2}-{SEQ:6}". Null = system default.
            $table->string('reference_format', 64)->nullable();
            // A category node (e.g. "SME", "Micro") groups product nodes
            // under it in the picker tree but is never itself borrowed
            // against — every column below is meaningless for one and is
            // left null, which is why they can't be NOT NULL despite every
            // real product needing them.
            $table->boolean('is_product')->default(true);
            $table->enum('frequency', ['daily', 'weekly', 'monthly'])->nullable();
            $table->enum('collection_days', ['all_days', 'week_days'])->default('all_days');
            $table->enum('interest_method', ['flat', 'reducing_balance'])->nullable();
            $table->decimal('interest_rate_percent', 6, 3)->unsigned()->nullable();
            $table->enum('rate_period', ['monthly', 'annual'])->default('monthly');
            $table->decimal('min_amount', 15, 2)->unsigned()->nullable();
            $table->decimal('max_amount', 15, 2)->unsigned()->nullable();
            $table->smallInteger('min_term_months')->unsigned()->nullable();
            $table->smallInteger('max_term_months')->unsigned()->nullable();
            $table->smallInteger('grace_period_days')->unsigned()->default(5);
            $table->decimal('default_charge_rate_percent', 6, 3)->unsigned()->default(0);
            $table->decimal('processing_fee_percent', 6, 3)->unsigned()->default(0);
            $table->enum('commission_type', ['fixed', 'percentage'])->default('fixed');
            $table->decimal('commission_value', 15, 2)->unsigned()->default(0);
            $table->smallInteger('min_paid_installments')->unsigned()->default(0);
            $table->smallInteger('sort_order')->unsigned()->default(0);
            $table->enum('status', ['Active', 'Inactive'])->default('Active');
            $table->timestamps();
            $table->index('parent_id');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE `loan_types` ADD CONSTRAINT `chk_loan_types_amount_range`
            CHECK (`max_amount` IS NULL OR `min_amount` IS NULL OR `max_amount` >= `min_amount`)
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE `loan_types` ADD CONSTRAINT `chk_loan_types_term_range`
            CHECK (`max_term_months` IS NULL OR `min_term_months` IS NULL OR `max_term_months` >= `min_term_months`)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_types');
    }
};
