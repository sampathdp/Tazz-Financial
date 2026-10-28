<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fixed Deposit additions:
 *  - fd_joint_holders: a second (or more) customer sharing the FD with the primary holder.
 *  - fd_nominees: nominee details for the FD.
 *  - fd_commissions: commission set by hand on an FD (or its renewal), paid manually afterwards
 *    to a staff member or an outsider. The payout account on fixed_deposits was already nullable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fd_joint_holders', function (Blueprint $t) {
            $t->id();
            $t->foreignId('fixed_deposit_id')->constrained('fixed_deposits')->cascadeOnDelete();
            $t->foreignId('customer_id')->constrained('customers');
            $t->string('relationship', 64)->nullable();
            $t->enum('operating_instruction', ['either_or_survivor', 'both_to_sign'])->default('either_or_survivor');
            $t->timestamps();
            $t->unique(['fixed_deposit_id', 'customer_id']);
        });

        Schema::create('fd_nominees', function (Blueprint $t) {
            $t->id();
            $t->foreignId('fixed_deposit_id')->constrained('fixed_deposits')->cascadeOnDelete();
            $t->string('full_name', 150);
            $t->string('relationship', 64)->nullable();
            $t->string('nic_no', 32)->nullable();
            $t->date('date_of_birth')->nullable();
            $t->string('address', 255)->nullable();
            $t->string('phone', 32)->nullable();
            $t->decimal('share_percent', 5, 2)->default(100);
            $t->string('guardian_name', 150)->nullable();
            $t->string('guardian_nic_no', 32)->nullable();
            $t->timestamps();
        });

        Schema::create('fd_commissions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('fixed_deposit_id')->constrained('fixed_deposits')->cascadeOnDelete();
            $t->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $t->enum('recipient_type', ['staff', 'outsider']);
            $t->foreignId('staff_id')->nullable()->constrained('staff')->nullOnDelete();
            $t->string('recipient_name', 150);
            $t->string('recipient_phone', 32)->nullable();
            $t->enum('commission_type', ['percentage', 'fixed']);
            $t->decimal('commission_value', 12, 2);
            $t->decimal('commission_amount', 14, 2);
            $t->string('notes', 255)->nullable();
            $t->dateTime('paid_at')->nullable();
            $t->decimal('payment_amount', 14, 2)->nullable();
            $t->string('payment_method', 16)->nullable();
            $t->foreignId('cash_account_id')->nullable()->constrained('cash_accounts')->nullOnDelete();
            $t->foreignId('bank_account_id')->nullable()->constrained('bank_accounts')->nullOnDelete();
            $t->string('reference_no', 64)->nullable();
            $t->string('payment_notes', 255)->nullable();
            $t->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fd_commissions');
        Schema::dropIfExists('fd_nominees');
        Schema::dropIfExists('fd_joint_holders');
    }
};
