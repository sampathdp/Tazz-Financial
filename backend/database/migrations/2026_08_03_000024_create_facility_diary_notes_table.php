<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Renamed from loan_diary_notes — Speed Drafts have no diary notes today, but the table is shared for whenever that changes. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('facility_diary_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('credit_facility_id')->constrained('credit_facilities')->cascadeOnDelete();
            $table->string('note', 500);
            $table->date('follow_up_date')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('credit_facility_id');
            $table->index('follow_up_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facility_diary_notes');
    }
};
