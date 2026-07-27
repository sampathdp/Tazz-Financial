<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddClearanceStatusToLoanPaymentsTable extends Migration
{
    public function up()
    {
        Schema::table('loan_payments', function (Blueprint $table) {
            // Null for Cash/Bank (applied immediately, no clearance concept).
            // Cheque payments start 'Pending' and hold off applying to the
            // schedule until manually set to 'Realized' (or 'Bounced', which
            // voids the payment without ever having touched the balance).
            $table->string('clearance_status', 16)->nullable()->after('payment_method');
        });
    }

    public function down()
    {
        Schema::table('loan_payments', function (Blueprint $table) {
            $table->dropColumn('clearance_status');
        });
    }
}
