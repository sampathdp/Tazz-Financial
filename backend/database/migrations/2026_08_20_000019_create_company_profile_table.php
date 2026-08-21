<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Single-row settings table (id=1, enforced by the controller via
 * firstOrCreate) for company branding/identity — the frontend
 * (Login screen, Admin > Company Profile, api.ts's `company` client) has
 * called a /company-profile API since before this migration existed; there
 * was never a backend for it, so every page load 404'd on
 * /company-profile/branding. This is that backend, not a new feature.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_profile', function (Blueprint $table) {
            $table->id();
            $table->string('name', 191)->default('Tazz Investment & Credit Pvt Ltd');
            $table->string('tagline', 191)->nullable();
            $table->string('address', 255)->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('email', 191)->nullable();
            $table->string('tax_no', 64)->nullable();
            $table->string('currency', 32)->default('LKR — Sri Lankan Rupee');
            $table->string('logo_path', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_profile');
    }
};
