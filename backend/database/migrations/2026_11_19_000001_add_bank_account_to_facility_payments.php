<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A Bank-method loan receipt had no company bank account on it, so it was parked in 1030 Undeposited
 * Funds and never reached a bank balance. Capture the account the transfer landed in so the receipt
 * posts straight into it (see LoanLedgerPoster::postCashReceipt). Null keeps the old 1030 behaviour.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('facility_payments', 'bank_account_id')) {
            return;
        }
        Schema::table('facility_payments', function (Blueprint $t) {
            $t->unsignedBigInteger('bank_account_id')->nullable()->after('bank_id');
            $t->foreign('bank_account_id')->references('id')->on('bank_accounts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('facility_payments', 'bank_account_id')) {
            return;
        }
        Schema::table('facility_payments', function (Blueprint $t) {
            $t->dropForeign(['bank_account_id']);
            $t->dropColumn('bank_account_id');
        });
    }
};
