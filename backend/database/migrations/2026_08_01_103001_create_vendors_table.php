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
            $table->string('code', 24)->unique();
            $table->string('contact_person', 150)->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('email', 255)->nullable();
            // Was TEXT with no length limit — a vendor's postal address
            // realistically never needs more than a couple of lines, and
            // TEXT vs VARCHAR costs an off-page storage lookup for values
            // that would fit inline. VendorController's validation gets a
            // matching max:255.
            $table->string('address', 255)->nullable();
            $table->string('tax_no', 32)->nullable();
            $table->enum('status', ['Active', 'Inactive'])->default('Active');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendors');
    }
};
