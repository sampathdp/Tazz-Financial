<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Periodic gold valuation rate history, by karat and (optionally) branch.
 * This is the audit trail an appraiser reads from when pricing an article —
 * facility_collateral_pawn_details.rate_per_gram is a snapshot copied from
 * here at appraisal time, not a live FK, so a later rate change never
 * retroactively alters a ticket that already exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pawning_gold_rates', function (Blueprint $table) {
            $table->id();
            $table->date('effective_date');
            $table->decimal('karat', 4, 2)->unsigned();
            $table->decimal('rate_per_gram', 10, 2)->unsigned();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['effective_date', 'karat', 'branch_id'], 'uq_pgr_date_karat_branch');
        });

        DB::statement('ALTER TABLE `pawning_gold_rates` ADD CONSTRAINT `chk_pgr_rate` CHECK (`rate_per_gram` > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('pawning_gold_rates');
    }
};
