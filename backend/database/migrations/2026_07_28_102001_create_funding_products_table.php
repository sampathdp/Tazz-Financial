<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('funding_products', function (Blueprint $table) {
            $table->id();
            $table->string('name', 128);
            $table->string('code', 32)->unique();
            $table->string('interest_method', 16); // monthly | maturity
            $table->decimal('annual_rate_percent', 6, 3);
            // Auto-derived (annual/12) unless rate_is_manual overrides it.
            $table->decimal('monthly_rate_percent', 6, 3)->nullable();
            $table->boolean('rate_is_manual')->default(false);
            // e.g. [3,6,9,12] — months a customer may choose at FD creation.
            $table->json('allowed_terms_months');
            $table->boolean('allow_custom_term')->default(false);
            $table->decimal('min_deposit_amount', 15, 2);
            $table->decimal('max_deposit_amount', 15, 2)->nullable();
            // Template for this product's generated FD numbers, e.g. "{BRANCH}-FD-{CODE}-{YEAR:2}-{SEQ:6}". Null = system default.
            $table->string('reference_format', 191)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('status', 16)->default('Active');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('funding_products');
    }
};
