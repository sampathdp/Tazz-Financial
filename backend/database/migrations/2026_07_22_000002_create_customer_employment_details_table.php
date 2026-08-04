<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Split off customers — 1:1, populated only when occupation_type='Employed'.
 * customer_id is both the primary key and the foreign key (not a separate
 * surrogate id): the correct pattern for a true 1:1 extension table, since
 * it makes "at most one row per customer" a structural guarantee rather
 * than something a unique index has to separately enforce.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_employment_details', function (Blueprint $table) {
            $table->foreignId('customer_id')->primary()->constrained()->cascadeOnDelete();
            $table->string('employer_name', 191)->nullable();
            $table->string('job_title', 128)->nullable();
            $table->string('employer_address', 255)->nullable();
            $table->string('employer_phone', 20)->nullable();
            $table->date('employment_start_date')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_employment_details');
    }
};
