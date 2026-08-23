<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The reduced annual rate applied to a premature part-payment (see
 * FixedDepositController::partPayment()) — Sri Lankan FD/CP terms
 * conventionally pay "an interest rate lesser than the rate initially
 * agreed" on early withdrawal, at the institution's discretion. Null means
 * this product hasn't had one configured yet, in which case part-payment is
 * blocked until an admin sets it on Funding Products.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('funding_products', function (Blueprint $table) {
            $table->decimal('premature_withdrawal_rate_percent', 6, 3)->nullable()->after('annual_rate_percent');
        });
    }

    public function down(): void
    {
        Schema::table('funding_products', function (Blueprint $table) {
            $table->dropColumn('premature_withdrawal_rate_percent');
        });
    }
};
