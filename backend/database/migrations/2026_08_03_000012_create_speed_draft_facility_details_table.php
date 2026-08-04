<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The speed-draft-specific half of a credit_facilities row — see that migration's docblock. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('speed_draft_facility_details', function (Blueprint $table) {
            $table->foreignId('credit_facility_id')->primary()->constrained('credit_facilities')->cascadeOnDelete();
            $table->foreignId('speed_draft_type_id')->constrained('speed_draft_types');
            $table->enum('payment_frequency', ['daily', 'weekly', 'monthly'])->default('monthly');
            // Cheque is a new addition here: the current SpeedDraftController
            // only ever validates/accepts Cash|Bank at disbursal — Cheque is
            // reserved schema-wide for consistency with every other
            // payment_method enum in this system, not a behavior this
            // migration is claiming already works.
            $table->enum('disbursement_method', ['Cash', 'Bank', 'Cheque'])->nullable();
            $table->index('speed_draft_type_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('speed_draft_facility_details');
    }
};
