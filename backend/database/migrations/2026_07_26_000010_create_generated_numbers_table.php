<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generated_numbers', function (Blueprint $table) {
            $table->id();
            $table->string('module', 32); // loan | customer | payment | ...
            $table->string('number', 64)->unique();
            // Links to the Loan/Customer/LoanPayment row this number was issued
            // for — set right after that row is created (see NumberGenerator::attach).
            $table->nullableMorphs('reference');
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('loan_type_id')->nullable()->constrained('loan_types')->nullOnDelete();
            $table->unsignedSmallInteger('year')->nullable();
            $table->unsignedInteger('sequence');
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['module', 'branch_id', 'loan_type_id', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generated_numbers');
    }
};
