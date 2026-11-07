<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * customers.registered_as: the capacity a customer was registered in. A label,
 * not a restriction — any customer can still be picked in any role on a
 * facility. Replaces the old is_guarantor checkbox (kept in sync from this
 * column so existing queries keep working).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->enum('registered_as', ['Customer', 'Guarantor', 'Co-Borrower', 'Joint Applicant', 'Introducer'])
                ->default('Customer')->after('is_guarantor');
        });

        DB::table('customers')->where('is_guarantor', 1)->update(['registered_as' => 'Guarantor']);
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('registered_as');
        });
    }
};
