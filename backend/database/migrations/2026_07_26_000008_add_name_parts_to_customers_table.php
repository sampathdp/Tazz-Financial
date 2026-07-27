<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddNamePartsToCustomersTable extends Migration
{
    public function up()
    {
        Schema::table('customers', function (Blueprint $table) {
            // Split name capture, as used on KYC/loan documents: Title + Initials +
            // Surname form the short display name; name_in_full is the expanded
            // given name(s), kept only for formal documents. full_name itself stays
            // as the derived "Mr. J.K. Perera" value (see Customer::booted()).
            $table->string('title', 10)->nullable()->after('full_name');
            $table->string('surname', 100)->nullable()->after('title');
            $table->string('name_in_full', 191)->nullable()->after('surname');
            $table->string('initials', 32)->nullable()->after('name_in_full');
        });
    }

    public function down()
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['title', 'surname', 'name_in_full', 'initials']);
        });
    }
}
