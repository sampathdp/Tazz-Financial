<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Merges loan_collateral + speed_draft_collateral. The two source tables
 * had genuinely different attribute sets (loan collateral is vehicle-
 * oriented: make/model/registration/chassis/engine; speed draft collateral
 * is jewelry-oriented: weight/purity/appraised value) — cramming both sets
 * onto one wide table would leave roughly half of every row's columns
 * always null depending on which type it is. Instead this table holds only
 * what's common to any collateral item, and collateral_type routes to
 * whichever of facility_collateral_vehicle_details /
 * facility_collateral_jewelry_details actually applies.
 *
 * collateral_type is new — neither source table constrained its `type`
 * column at the code level (verified: LoanController and
 * SpeedDraftController both just validate it as an unconstrained string).
 * It's the structural discriminator that decides which extension table gets
 * a row; collateral_category takes over the old free-text `type` column's
 * actual job (an open-ended descriptive label), so nothing that used to be
 * expressible is lost.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('facility_collateral', function (Blueprint $table) {
            $table->id();
            $table->foreignId('credit_facility_id')->constrained('credit_facilities')->cascadeOnDelete();
            $table->enum('collateral_type', ['Vehicle', 'Jewelry', 'Property', 'Other']);
            $table->string('collateral_category', 64)->nullable();
            $table->string('description', 255);
            $table->decimal('estimated_value', 15, 2)->unsigned()->nullable();
            $table->timestamps();
            $table->index('credit_facility_id');
            $table->index('collateral_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facility_collateral');
    }
};
