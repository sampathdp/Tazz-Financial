<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per loan, created at disbursal time (see LoanController::disburse()) —
        // never backfilled for loans disbursed before this feature shipped.
        Schema::create('sales_executive_commissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->unique()->constrained('loans')->restrictOnDelete();
            $table->foreignId('sales_executive_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('loan_type_id')->constrained('loan_types')->restrictOnDelete();
            // Snapshot of the loan type's commission config at disbursal time, so a
            // later edit to the loan type never retroactively changes this record.
            $table->string('commission_type', 16);
            $table->decimal('commission_value', 15, 2);
            $table->decimal('commission_amount', 15, 2);
            $table->unsignedSmallInteger('required_installments');
            // "Pending" vs "Eligible" is derived live from paid installments, never
            // stored — only the terminal "Paid" state is persisted, via paid_at.
            $table->dateTime('paid_at')->nullable();
            $table->decimal('payment_amount', 15, 2)->nullable();
            $table->string('payment_method', 16)->nullable(); // Cash | Bank | Cheque
            $table->string('reference_no', 64)->nullable();
            $table->string('notes', 255)->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('sales_executive_id');
            $table->index('loan_type_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_executive_commissions');
    }
};
