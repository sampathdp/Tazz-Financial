<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fixed_deposits', function (Blueprint $table) {
            $table->id();
            $table->string('fd_number', 64)->unique();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('funding_product_id')->constrained()->restrictOnDelete();
            // Copied from the funding product at creation time so later product edits never
            // retroactively change an already-issued FD.
            $table->decimal('principal_amount', 15, 2);
            $table->decimal('interest_rate_percent', 6, 3);
            $table->string('interest_payment_method', 16); // monthly | maturity
            $table->unsignedSmallInteger('term_months');
            $table->date('start_date');
            $table->date('maturity_date');
            // Monthly method only.
            $table->decimal('monthly_interest_amount', 15, 2)->nullable();
            $table->decimal('total_interest_amount', 15, 2);
            $table->decimal('maturity_amount', 15, 2);
            $table->date('next_interest_date')->nullable();
            // Active | Closed | Renewed — "Matured" is derived (see FixedDeposit::isMatured()), not stored.
            $table->string('status', 16)->default('Active');
            $table->foreignId('renewed_from_id')->nullable()->constrained('fixed_deposits')->nullOnDelete();
            $table->foreignId('renewed_to_id')->nullable()->constrained('fixed_deposits')->nullOnDelete();
            $table->date('closed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('status');
            $table->index('maturity_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fixed_deposits');
    }
};
