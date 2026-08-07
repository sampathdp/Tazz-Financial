<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Informational-only effective-rate figures for Flat Rate loans (the
 * equivalent reducing-balance rate implied by the flat EMI, expressed as a
 * monthly and an annual percentage — see AerCalculator). Left NULL for
 * Reducing Rate loans, where interest_rate_percent on credit_facilities
 * already IS the effective annual rate. Nullable/additive: nothing existing
 * reads or writes these columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loan_facility_details', function (Blueprint $table) {
            $table->decimal('effective_monthly_rate', 10, 8)->nullable()->after('interest_method');
            $table->decimal('effective_annual_rate', 10, 8)->nullable()->after('effective_monthly_rate');
        });
    }

    public function down(): void
    {
        Schema::table('loan_facility_details', function (Blueprint $table) {
            $table->dropColumn(['effective_monthly_rate', 'effective_annual_rate']);
        });
    }
};
