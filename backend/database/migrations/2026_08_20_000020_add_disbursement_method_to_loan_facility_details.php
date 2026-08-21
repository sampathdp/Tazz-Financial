<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Same column speed_draft_facility_details already has — Loan disbursement
 * never captured how the customer was actually paid, which is exactly why
 * LoanLedgerPoster never posted a disbursement entry at all (see its
 * docblock: "no payment method is even captured yet"). This is that missing
 * data; LoanController::disburse() and LoanLedgerPoster::postDisbursement
 * are the other two pieces.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loan_facility_details', function (Blueprint $table) {
            $table->enum('disbursement_method', ['Cash', 'Bank', 'Cheque'])->nullable()->after('down_payment');
        });
    }

    public function down(): void
    {
        Schema::table('loan_facility_details', function (Blueprint $table) {
            $table->dropColumn('disbursement_method');
        });
    }
};
