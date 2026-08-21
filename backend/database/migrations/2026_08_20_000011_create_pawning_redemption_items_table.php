<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Pure link table: which articles a given pawning_redemptions row released. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pawning_redemption_items', function (Blueprint $table) {
            $table->foreignId('pawning_redemption_id')->constrained('pawning_redemptions')->cascadeOnDelete();
            $table->foreignId('facility_collateral_id')->constrained('facility_collateral')->restrictOnDelete();
            $table->primary(['pawning_redemption_id', 'facility_collateral_id'], 'pk_pri');
            $table->index('facility_collateral_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pawning_redemption_items');
    }
};
