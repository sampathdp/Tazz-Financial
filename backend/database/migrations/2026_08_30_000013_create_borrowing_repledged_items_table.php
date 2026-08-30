<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * For a type=RePledgedPawning borrowing: the specific in-house pawning tickets
 * (credit_facilities rows, facility_type = 'Pawning') the company took and
 * re-pledged to an outside financier to raise the funds. Records exactly which
 * collateral left the vault and where it went, so the source of the money is
 * fully traceable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('borrowing_repledged_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('borrowing_id')->constrained('borrowings')->cascadeOnDelete();
            $table->foreignId('pawning_id')->constrained('credit_facilities');
            $table->decimal('appraised_value_snapshot', 15, 2)->unsigned()->nullable();
            // The financier the ticket was re-pledged to, and their own
            // reference for it — free text, an external party not in our tables.
            $table->string('financier_name', 160)->nullable();
            $table->string('external_reference', 120)->nullable();
            $table->timestamps();
            $table->unique(['borrowing_id', 'pawning_id']);
            $table->index('pawning_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('borrowing_repledged_items');
    }
};
