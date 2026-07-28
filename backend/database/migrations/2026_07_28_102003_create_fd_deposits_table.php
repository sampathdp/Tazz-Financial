<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fd_deposits', function (Blueprint $table) {
            $table->id();
            $table->string('deposit_number', 64)->unique();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->date('deposit_date');
            $table->decimal('amount', 15, 2);
            $table->string('payment_method', 16); // Cash | Cheque | BankTransfer
            $table->string('reference_no', 64)->nullable();
            // Bank/cheque details — populated for Cheque/BankTransfer only.
            $table->string('bank_name', 128)->nullable();
            $table->text('notes')->nullable();
            // Available (unallocated) | Allocated (funding an FD, see fixed_deposit_id)
            $table->string('status', 16)->default('Available');
            $table->foreignId('fixed_deposit_id')->nullable()->constrained('fixed_deposits')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fd_deposits');
    }
};
