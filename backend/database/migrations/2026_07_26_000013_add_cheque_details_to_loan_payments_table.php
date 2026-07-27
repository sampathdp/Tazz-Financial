<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddChequeDetailsToLoanPaymentsTable extends Migration
{
    public function up()
    {
        Schema::table('loan_payments', function (Blueprint $table) {
            // Cheque No. reuses receipt_no; these two are cheque-specific and only
            // populated when payment_method = Cheque.
            $table->string('bank_name', 191)->nullable()->after('receipt_no');
            $table->date('cheque_date')->nullable()->after('bank_name');
        });
    }

    public function down()
    {
        Schema::table('loan_payments', function (Blueprint $table) {
            $table->dropColumn(['bank_name', 'cheque_date']);
        });
    }
}
