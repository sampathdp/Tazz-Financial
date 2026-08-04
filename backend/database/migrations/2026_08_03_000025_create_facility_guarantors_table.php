<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Renamed from loan_guarantors — guarantor_id renamed guarantor_customer_id for clarity (it's a Customer, and "guarantor" alone reads ambiguously next to credit_facility_id). Speed Drafts have no guarantors today, but the table is shared for whenever that changes. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('facility_guarantors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('credit_facility_id')->constrained('credit_facilities')->cascadeOnDelete();
            $table->foreignId('guarantor_customer_id')->constrained('customers');
            $table->decimal('guaranteed_amount', 15, 2)->unsigned();
            $table->timestamps();
            // Explicit short name — the auto-generated one exceeds MySQL's 64-char identifier limit.
            $table->unique(['credit_facility_id', 'guarantor_customer_id'], 'uq_fg_facility_guarantor');
            $table->index('guarantor_customer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facility_guarantors');
    }
};
