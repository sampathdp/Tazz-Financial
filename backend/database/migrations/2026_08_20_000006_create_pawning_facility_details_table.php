<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The pawning-specific half of a credit_facilities row — see
 * loan_facility_details for the extension-table pattern this follows.
 * applied_rate_percent/is_special_rate snapshot the rate actually charged
 * (pawning_types' own rate can change later without touching a live
 * ticket). total_*_cache columns mirror credit_facilities.
 * outstanding_balance_cache — maintained sums over this ticket's
 * facility_collateral_pawn_details rows, kept for list-view performance
 * rather than summing every article on every row of a ticket list.
 * previous_facility_id is the "old pawning no" a renewal keeps as a
 * reference back to the ticket it rolled forward from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pawning_facility_details', function (Blueprint $table) {
            $table->foreignId('credit_facility_id')->primary()->constrained('credit_facilities')->cascadeOnDelete();
            $table->foreignId('pawning_type_id')->constrained('pawning_types');
            $table->decimal('applied_rate_percent', 6, 3)->unsigned();
            $table->boolean('is_special_rate')->default(false);
            $table->decimal('total_gross_weight_grams', 10, 3)->unsigned()->default(0);
            $table->decimal('total_net_weight_grams', 10, 3)->unsigned()->default(0);
            $table->decimal('total_appraised_value', 15, 2)->unsigned()->default(0);
            $table->date('repay_due_date');
            $table->smallInteger('renewal_count')->unsigned()->default(0);
            $table->foreignId('previous_facility_id')->nullable()->constrained('credit_facilities')->nullOnDelete();
            $table->string('purpose', 128)->nullable();
            $table->index('previous_facility_id');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE `pawning_facility_details` ADD CONSTRAINT `chk_pfd_weight`
            CHECK (`total_net_weight_grams` <= `total_gross_weight_grams`)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('pawning_facility_details');
    }
};
