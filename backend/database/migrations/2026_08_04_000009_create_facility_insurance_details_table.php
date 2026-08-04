<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The insurance-policy half of a facility whose category requires one — Loan
 * categories Leasing/Mortgage, SpeedDraft categories Lease/Mortgage (see
 * LoanType.category / SpeedDraftType.category). 1:1 extension of
 * credit_facilities directly (not of loan_facility_details/
 * speed_draft_facility_details), same "facility_" naming as
 * facility_collateral/facility_charges/etc — shared by both products rather
 * than duplicated per product, since the fields needed are identical either
 * way. credit_facility_id is both the primary key and the foreign key, same
 * 1:1 pattern as every other *_details table in this schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('facility_insurance_details', function (Blueprint $table) {
            $table->foreignId('credit_facility_id')->primary()->constrained('credit_facilities')->cascadeOnDelete();
            $table->string('insurance_company', 150)->nullable();
            $table->string('policy_no', 64)->nullable();
            $table->decimal('amount', 15, 2)->unsigned()->nullable();
            $table->string('contact_person', 150)->nullable();
            $table->string('coverage', 255)->nullable();
            $table->text('remarks')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facility_insurance_details');
    }
};
