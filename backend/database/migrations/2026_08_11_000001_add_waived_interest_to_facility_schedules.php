<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit trail for interest written off a specific installment at early
 * settlement (see LoanPaymentAllocator::applySettlementWaiver()) — kept
 * per-row rather than as a lump-sum FacilityWaiver so it can only ever
 * offset the row it belongs to, never bleed into an already-recognized
 * past period the way FacilityWaiver's oldest-first netting does.
 * Defaults to 0 and is never written for a loan that doesn't settle
 * early, so existing schedules are unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('facility_schedules', function (Blueprint $table) {
            $table->decimal('waived_interest', 15, 2)->unsigned()->default(0)->after('interest_due');
        });
    }

    public function down(): void
    {
        Schema::table('facility_schedules', function (Blueprint $table) {
            $table->dropColumn('waived_interest');
        });
    }
};
