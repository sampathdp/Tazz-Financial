<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Replaces the pre-redesign fd_deposits migration. bank_id replaces the old
 * free-text bank_name — same rationale as facility_payments.bank_id: a real
 * reference to the banks lookup table instead of a repeated, unconstrained
 * string. A deposit is recorded (cash physically received) before it's
 * necessarily allocated to a fixed_deposits row — status Available means
 * "recorded but not yet applied to an FD"; Allocated means fixed_deposit_id
 * is set (see FixedDepositController::store()/renew()).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fd_deposits', function (Blueprint $table) {
            $table->id();
            $table->string('deposit_number', 24)->unique();
            $table->foreignId('branch_id')->constrained('branches');
            $table->foreignId('customer_id')->constrained('customers');
            $table->date('deposit_date');
            $table->decimal('amount', 15, 2)->unsigned();
            $table->enum('payment_method', ['Cash', 'Bank', 'Cheque']);
            $table->string('reference_no', 64)->nullable();
            $table->foreignId('bank_id')->nullable()->constrained('banks')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->enum('status', ['Available', 'Allocated'])->default('Available');
            $table->foreignId('fixed_deposit_id')->nullable()->constrained('fixed_deposits')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('branch_id');
            $table->index('customer_id');
            $table->index('fixed_deposit_id');
            $table->index('bank_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fd_deposits');
    }
};
