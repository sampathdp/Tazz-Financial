<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loan_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('loan_types')->restrictOnDelete();
            $table->string('name', 128);
            $table->string('code', 32)->unique();
            // Selectable when issuing a loan (leaf/product) vs an organizational category only.
            $table->boolean('is_product')->default(true);
            $table->string('frequency', 16)->nullable(); // daily | weekly | monthly
            $table->string('interest_method', 20)->nullable(); // flat | reducing_balance
            $table->decimal('interest_rate_percent', 6, 3)->nullable();
            $table->string('rate_period', 16)->nullable()->default('monthly'); // monthly | annual
            $table->decimal('min_amount', 15, 2)->nullable();
            $table->decimal('max_amount', 15, 2)->nullable();
            $table->unsignedSmallInteger('min_term_months')->nullable();
            $table->unsignedSmallInteger('max_term_months')->nullable();
            $table->decimal('penalty_rate_percent', 6, 3)->default(0);
            $table->string('penalty_method', 16)->default('flat'); // flat | daily
            $table->decimal('processing_fee_percent', 6, 3)->default(0);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('status', 16)->default('Active');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_types');
    }
};
