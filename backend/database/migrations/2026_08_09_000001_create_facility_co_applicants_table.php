<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Joint applicants on a loan — credit_facilities.customer_id stays the primary
 *  applicant (untouched, still required everywhere it's used today), this table
 *  adds any additional co-applicants (2nd/3rd customer on the same loan) without
 *  disturbing that. Mirrors facility_guarantors' shape/naming. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('facility_co_applicants', function (Blueprint $table) {
            $table->id();
            // credit_facilities is MyISAM (no FK support) — plain indexed column,
            // same as every other facility_* child table's credit_facility_id.
            $table->unsignedBigInteger('credit_facility_id');
            $table->foreignId('co_applicant_customer_id')->constrained('customers');
            $table->decimal('share_percent', 5, 2)->unsigned()->nullable();
            $table->timestamps();
            // Explicit short name — the auto-generated one exceeds MySQL's 64-char identifier limit.
            $table->unique(['credit_facility_id', 'co_applicant_customer_id'], 'uq_fca_facility_co_applicant');
            $table->index('co_applicant_customer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facility_co_applicants');
    }
};
