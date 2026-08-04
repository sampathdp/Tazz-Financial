<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Merges loan_collateral_documents + speed_draft_collateral_documents. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('facility_collateral_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('facility_collateral_id')->constrained('facility_collateral')->cascadeOnDelete();
            $table->string('file_path', 255);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('facility_collateral_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facility_collateral_documents');
    }
};
