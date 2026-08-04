<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Split off customers — 1:1, populated when occupation_type is 'SelfEmployed' or 'BusinessOwner'. Same customer_id-as-primary-key shape as customer_employment_details. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_business_details', function (Blueprint $table) {
            $table->foreignId('customer_id')->primary()->constrained()->cascadeOnDelete();
            $table->string('business_name', 191)->nullable();
            $table->string('business_reg_no', 32)->nullable()->unique();
            $table->string('business_type', 128)->nullable();
            $table->string('business_address', 255)->nullable();
            $table->string('business_phone', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_business_details');
    }
};
