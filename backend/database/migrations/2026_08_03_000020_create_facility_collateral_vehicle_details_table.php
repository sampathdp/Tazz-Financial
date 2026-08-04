<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Split off facility_collateral — 1:1, populated when collateral_type='Vehicle'.
 * registration_no is now UNIQUE — the original loan_collateral had no such
 * constraint, meaning the same vehicle could be entered as collateral for
 * two different loans by mistake (or fraud) with nothing to catch it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('facility_collateral_vehicle_details', function (Blueprint $table) {
            // Explicit short PK/FK names — the auto-generated ones (table name +
            // column + "_primary"/"_foreign") exceed MySQL's 64-char identifier limit.
            $table->foreignId('facility_collateral_id');
            $table->primary('facility_collateral_id', 'pk_fcvd');
            $table->foreign('facility_collateral_id', 'fk_fcvd_collateral')->references('id')->on('facility_collateral')->cascadeOnDelete();
            $table->string('vehicle_type', 30)->nullable();
            $table->string('make_model', 150)->nullable();
            $table->string('registration_no', 20)->nullable()->unique();
            $table->string('chassis_no', 50)->nullable();
            $table->string('engine_no', 50)->nullable();
            $table->smallInteger('year_of_manufacture')->unsigned()->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facility_collateral_vehicle_details');
    }
};
