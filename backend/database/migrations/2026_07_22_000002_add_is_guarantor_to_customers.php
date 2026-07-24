<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddIsGuarantorToCustomers extends Migration
{
    public function up()
    {
        Schema::table('customers', function (Blueprint $table) {
            // A label, not a restriction — any customer can be picked as a loan
            // guarantor regardless of this flag. It just tags people who were
            // registered primarily to guarantee someone else's loan, so staff
            // can spot/filter them in the list.
            $table->boolean('is_guarantor')->default(false)->after('status');
        });
    }

    public function down()
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('is_guarantor');
        });
    }
}
