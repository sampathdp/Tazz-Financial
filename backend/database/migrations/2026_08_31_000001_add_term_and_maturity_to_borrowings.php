<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A borrowing is taken at a rate for a fixed tenure. `term_months` /
 * `maturity_date` carry that tenure; `on_maturity` records the standing
 * intent for when it expires — Renew (roll it over for another term, logged
 * in borrowing_renewals) or Settle (repay and close). `contract_no` is the
 * lender's own agreement / facility reference, kept apart from the generic
 * `reference_no` (a cheque / transfer ref on the receipt).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('borrowings', function (Blueprint $table) {
            $table->string('contract_no', 80)->nullable()->after('reference_no');
            $table->unsignedSmallInteger('term_months')->nullable()->after('interest_rate_percent');
            $table->date('maturity_date')->nullable()->after('borrowing_date');
            $table->enum('on_maturity', ['Renew', 'Settle'])->nullable()->after('maturity_date');
        });
    }

    public function down(): void
    {
        Schema::table('borrowings', function (Blueprint $table) {
            $table->dropColumn(['contract_no', 'term_months', 'maturity_date', 'on_maturity']);
        });
    }
};
