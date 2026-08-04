<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The insurance-policy half of a Leasing or Mortgage loan (see
 * LoanType.category) — 1:1 extension of credit_facilities, same pattern as
 * loan_facility_details/customer_employment_details: credit_facility_id is
 * both the primary key and the foreign key, making "at most one row per
 * loan" a structural guarantee. Not applicable to other loan categories or
 * to Speed Drafts, so this stays off the shared credit_facilities row rather
 * than bloating it with columns most facilities never use.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loan_insurance_details', function (Blueprint $table) {
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
        Schema::dropIfExists('loan_insurance_details');
    }
};
