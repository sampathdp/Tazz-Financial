<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loans', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('loan_type_id')->constrained('loan_types')->restrictOnDelete();
            $table->decimal('principal_amount', 15, 2);
            $table->decimal('down_payment', 15, 2)->default(0); // paid upfront; schedule amortizes (principal_amount - down_payment)
            $table->decimal('interest_rate_percent', 6, 3); // defaults from loan_types, but negotiable per application
            $table->string('interest_method', 20);
            $table->string('rate_period', 16)->default('monthly'); // monthly | annual — snapshot from loan_types
            $table->unsignedSmallInteger('term_months');
            $table->string('installment_frequency', 16)->default('monthly'); // monthly | weekly | daily
            $table->string('purpose', 255)->nullable();
            $table->foreignId('loan_officer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('recovery_officer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('disbursed_amount', 15, 2)->nullable();
            $table->dateTime('disbursed_at')->nullable();
            $table->date('first_installment_date')->nullable();
            $table->string('status', 20)->default('Pending'); // Pending|Approved|Rejected|Active|Closed|WrittenOff|Restructured
            $table->foreignId('applied_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->string('rejected_reason', 255)->nullable();
            $table->dateTime('closed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index('status');
        });

        Schema::create('loan_guarantors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->constrained('loans')->cascadeOnDelete();
            // References `customers` directly — a guarantor is a customer acting
            // in a different capacity for this loan, not a separate person-type.
            $table->foreignId('guarantor_id')->constrained('customers')->restrictOnDelete();
            $table->decimal('guaranteed_amount', 15, 2);
            $table->timestamps();
            $table->unique(['loan_id', 'guarantor_id']);
        });

        Schema::create('loan_collateral', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->constrained('loans')->cascadeOnDelete();
            $table->string('type', 64); // Vehicle | Property | Equipment | Other
            $table->string('description', 255);
            $table->decimal('estimated_value', 15, 2)->nullable();
            $table->string('document_path', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_collateral');
        Schema::dropIfExists('loan_guarantors');
        Schema::dropIfExists('loans');
    }
};
