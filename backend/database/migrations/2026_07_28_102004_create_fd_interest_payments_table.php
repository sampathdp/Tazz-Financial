<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fd_interest_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fixed_deposit_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('installment_no');
            $table->date('due_date');
            $table->decimal('amount', 15, 2);
            $table->string('status', 16)->default('Pending'); // Pending | Paid
            $table->decimal('paid_amount', 15, 2)->default(0);
            $table->date('paid_at')->nullable();
            $table->string('payment_method', 16)->nullable(); // Cash | Cheque | BankTransfer
            $table->string('reference_no', 64)->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['fixed_deposit_id', 'status']);
            $table->index('due_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fd_interest_payments');
    }
};
