<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loan_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->constrained('loans')->cascadeOnDelete();
            $table->unsignedSmallInteger('installment_no');
            $table->date('due_date');
            $table->decimal('principal_due', 15, 2);
            $table->decimal('interest_due', 15, 2);
            $table->decimal('penalty_due', 15, 2)->default(0);
            $table->decimal('total_due', 15, 2);
            $table->decimal('paid_amount', 15, 2)->default(0);
            $table->dateTime('paid_at')->nullable();
            $table->string('status', 16)->default('Pending'); // Pending | Paid | Partial | Overdue
            $table->timestamps();
            $table->unique(['loan_id', 'installment_no']);
            $table->index('due_date');
            $table->index('status');
        });

        Schema::create('loan_payments', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->foreignId('loan_id')->constrained('loans')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->decimal('amount', 15, 2);
            $table->string('payment_method', 16)->default('Cash'); // Cash | Bank | Cheque
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('paid_at');
            $table->string('receipt_no', 32)->nullable();
            $table->string('notes', 255)->nullable();
            $table->timestamps();
        });

        // Splits one payment across the installment(s) it settles — a single
        // receipt can cover part of one due, all of one due, or several dues.
        Schema::create('loan_payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_payment_id')->constrained('loan_payments')->cascadeOnDelete();
            $table->foreignId('loan_schedule_id')->constrained('loan_schedules')->restrictOnDelete();
            $table->decimal('principal_applied', 15, 2)->default(0);
            $table->decimal('interest_applied', 15, 2)->default(0);
            $table->decimal('penalty_applied', 15, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_payment_allocations');
        Schema::dropIfExists('loan_payments');
        Schema::dropIfExists('loan_schedules');
    }
};
