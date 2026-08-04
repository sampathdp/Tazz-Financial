<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Renamed from loan_documents — Speed Drafts have no document upload today, but the table is shared for whenever that changes. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('facility_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('credit_facility_id')->constrained('credit_facilities')->cascadeOnDelete();
            $table->enum('doc_type', ['ContractImage', 'Agreement', 'IdCopy', 'Other']);
            $table->string('file_path', 255);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('credit_facility_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facility_documents');
    }
};
