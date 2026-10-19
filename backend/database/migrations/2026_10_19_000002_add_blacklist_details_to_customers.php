<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A blacklisted customer (customers.status = 'Blacklisted') is barred from every
 * new credit facility in every branch. These columns record why and by whom, for
 * the Blacklisted Customers report.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('blacklist_reason', 500)->nullable();
            $table->timestamp('blacklisted_at')->nullable();
            $table->unsignedBigInteger('blacklisted_by')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('customers', fn (Blueprint $t) => $t->dropColumn(['blacklist_reason', 'blacklisted_at', 'blacklisted_by']));
    }
};
