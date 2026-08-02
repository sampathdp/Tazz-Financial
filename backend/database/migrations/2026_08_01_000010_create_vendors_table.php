<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// spec 3.9 Expense Management (schema.sql SECTION 8).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendors', function (Blueprint $table) {
            $table->id();
            $table->string('name', 191);
            $table->string('code', 32)->unique();
            $table->string('contact_person', 191)->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('email', 190)->nullable();
            $table->text('address')->nullable();
            $table->string('tax_no', 64)->nullable();
            $table->string('status', 16)->default('Active');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendors');
    }
};
