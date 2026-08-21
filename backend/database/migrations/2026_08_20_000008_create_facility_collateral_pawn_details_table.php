<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-article appraisal for a PawnedJewelry facility_collateral row — one
 * row per article pledged under a pawning ticket. declared_karat/
 * tested_karat are kept separate on purpose: the customer's stated karat
 * and the assay result can differ, and the loan is always based on the
 * tested figure, never the declared one. gross_weight_grams (as worn) vs
 * net_weight_grams (gold content only, stones/solder deducted) drive
 * valuation together with rate_per_gram, a snapshot from
 * pawning_gold_rates at appraisal time. item_status tracks this specific
 * article through partial redemption / auction independently of the
 * ticket as a whole, since one ticket can hold several articles redeemed
 * or auctioned separately.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('facility_collateral_pawn_details', function (Blueprint $table) {
            // Explicit short PK/FK names — the auto-generated ones exceed
            // MySQL's 64-char identifier limit, same reason as
            // facility_collateral_jewelry_details.
            $table->foreignId('facility_collateral_id');
            $table->primary('facility_collateral_id', 'pk_fcpd');
            $table->foreign('facility_collateral_id', 'fk_fcpd_collateral')->references('id')->on('facility_collateral')->cascadeOnDelete();
            $table->smallInteger('item_no')->unsigned();
            $table->foreignId('article_type_id')->constrained('pawning_article_types');
            $table->decimal('declared_karat', 4, 2)->unsigned()->nullable();
            $table->decimal('tested_karat', 4, 2)->unsigned();
            $table->decimal('gross_weight_grams', 10, 3)->unsigned();
            $table->decimal('net_weight_grams', 10, 3)->unsigned();
            $table->decimal('rate_per_gram', 10, 2)->unsigned();
            $table->decimal('appraised_value', 15, 2)->unsigned();
            $table->decimal('max_loan_value', 15, 2)->unsigned();
            $table->enum('item_status', ['Pledged', 'Released', 'Auctioned', 'Forfeited'])->default('Pledged');
            $table->foreignId('appraised_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('remark', 255)->nullable();
            $table->index('article_type_id');
            $table->index('item_status');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE `facility_collateral_pawn_details` ADD CONSTRAINT `chk_fcpd_weight`
            CHECK (`net_weight_grams` <= `gross_weight_grams`)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('facility_collateral_pawn_details');
    }
};
