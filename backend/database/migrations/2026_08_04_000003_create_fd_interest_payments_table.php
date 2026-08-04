<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Replaces the pre-redesign fd_interest_payments migration (Pending|Paid
 * only). 'Partial' is added to the status vocabulary for consistency with
 * every other schedule-row status enum in this schema (facility_schedules,
 * etc.) — FdInterestPaymentController::pay() still only ever pays the full
 * scheduled amount in one shot today (no partial-payment UI exists), so this
 * is reserved headroom, not a behavior change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fd_interest_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fixed_deposit_id')->constrained('fixed_deposits')->cascadeOnDelete();
            $table->smallInteger('installment_no')->unsigned();
            $table->date('due_date');
            $table->decimal('amount', 15, 2)->unsigned();
            $table->enum('status', ['Pending', 'Partial', 'Paid'])->default('Pending');
            $table->decimal('paid_amount', 15, 2)->unsigned()->default(0);
            $table->date('paid_at')->nullable();
            $table->enum('payment_method', ['Cash', 'Bank', 'Cheque'])->nullable();
            $table->string('reference_no', 64)->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['fixed_deposit_id', 'installment_no']);
            $table->index(['status', 'due_date']);
        });

        DB::statement('ALTER TABLE `fd_interest_payments` ADD CONSTRAINT `chk_fip_paid_amount` CHECK (`paid_amount` <= `amount`)');
    }

    public function down(): void
    {
        Schema::dropIfExists('fd_interest_payments');
    }
};
