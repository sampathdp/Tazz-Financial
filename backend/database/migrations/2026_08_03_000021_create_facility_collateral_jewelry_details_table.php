<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Split off facility_collateral — 1:1, populated when collateral_type='Jewelry'. `weight` renamed `weight_grams` (units were never stated on the original column). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('facility_collateral_jewelry_details', function (Blueprint $table) {
            // Explicit short PK/FK names — the auto-generated ones (table name +
            // column + "_primary"/"_foreign") exceed MySQL's 64-char identifier limit.
            $table->foreignId('facility_collateral_id');
            $table->primary('facility_collateral_id', 'pk_fcjd');
            $table->foreign('facility_collateral_id', 'fk_fcjd_collateral')->references('id')->on('facility_collateral')->cascadeOnDelete();
            $table->decimal('weight_grams', 10, 3)->unsigned()->nullable();
            $table->string('purity', 16)->nullable();
            $table->decimal('appraised_value', 15, 2)->unsigned()->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facility_collateral_jewelry_details');
    }
};
