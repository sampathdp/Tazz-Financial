<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The pawning scheme/product master — same role loan_types plays for Loan.
 * Holds the numbers CBSL and branch policy actually govern (rate, LTV,
 * grace/notice periods) as data rather than code constants, since those
 * change independently of any deploy. normal_rate_percent/special_rate_percent
 * mirror the two-tier pricing every pawning scheme offers: the standard rate,
 * and a discounted rate for settling within special_rate_days of the draw
 * date (an incentive to turn the book over quickly).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pawning_types', function (Blueprint $table) {
            $table->id();
            $table->string('name', 128);
            $table->string('code', 24)->unique();
            $table->decimal('normal_rate_percent', 6, 3)->unsigned();
            $table->decimal('special_rate_percent', 6, 3)->unsigned()->nullable();
            $table->smallInteger('special_rate_days')->unsigned()->nullable();
            $table->smallInteger('cycle_days')->unsigned();
            $table->decimal('ltv_percent', 5, 2)->unsigned();
            $table->smallInteger('max_renewal_count')->unsigned()->nullable();
            $table->smallInteger('grace_period_days')->unsigned()->default(5);
            $table->smallInteger('auction_eligible_days')->unsigned();
            $table->smallInteger('auction_notice_days')->unsigned();
            $table->enum('status', ['Active', 'Inactive'])->default('Active');
            $table->smallInteger('sort_order')->unsigned()->default(0);
            $table->timestamps();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE `pawning_types` ADD CONSTRAINT `chk_pt_special_rate`
            CHECK (`special_rate_percent` IS NULL OR `special_rate_percent` <= `normal_rate_percent`)
        SQL);
        DB::statement('ALTER TABLE `pawning_types` ADD CONSTRAINT `chk_pt_cycle_days` CHECK (`cycle_days` > 0)');
        DB::statement(<<<'SQL'
            ALTER TABLE `pawning_types` ADD CONSTRAINT `chk_pt_ltv`
            CHECK (`ltv_percent` > 0 AND `ltv_percent` <= 100)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('pawning_types');
    }
};
