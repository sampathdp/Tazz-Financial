<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Maintained cache of a loan's NPL/impairment classification — source of
 * truth is the live slab calc in LoanProvisioning::evaluate() (mirrors
 * outstanding_balance_cache's own "cache, not a second ledger" rationale),
 * refreshed by the daily loans:recompute-provisioning command and inline
 * after every payment (LoanPaymentAllocator::finalizeApplied()).
 * provision_posted_amount is the one exception — it's authoritative, not a
 * cache: it tracks what's actually been booked to 1190 via
 * LoanProvisioning::post(), which only ever moves when a user posts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credit_facilities', function (Blueprint $table) {
            $table->boolean('npl_flag')->default(false)->after('written_off_by');
            $table->unsignedTinyInteger('provision_required_pct')->default(0)->after('npl_flag');
            $table->decimal('provision_required_amount', 14, 2)->default(0)->after('provision_required_pct');
            $table->decimal('provision_posted_amount', 14, 2)->default(0)->after('provision_required_amount');
            $table->timestamp('provision_last_evaluated_at')->nullable()->after('provision_posted_amount');
        });
    }

    public function down(): void
    {
        Schema::table('credit_facilities', function (Blueprint $table) {
            $table->dropColumn(['npl_flag', 'provision_required_pct', 'provision_required_amount', 'provision_posted_amount', 'provision_last_evaluated_at']);
        });
    }
};
