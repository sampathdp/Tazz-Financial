<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * loan_id renamed credit_facility_id (Speed Drafts don't earn commissions
 * today, but the column now points at the shared facility table). FK to
 * credit_facilities has no ON DELETE clause (RESTRICT) — a facility with
 * paid commissions must not be deletable out from under them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_executive_commissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('credit_facility_id')->constrained('credit_facilities');
            $table->foreignId('sales_executive_id')->constrained('users');
            $table->foreignId('loan_type_id')->constrained('loan_types');
            $table->unsignedSmallInteger('cycle_no')->default(1);
            $table->unsignedSmallInteger('from_installment')->default(0);
            $table->enum('commission_type', ['fixed', 'percentage']);
            $table->decimal('commission_value', 15, 2)->unsigned();
            $table->decimal('commission_amount', 15, 2)->unsigned();
            $table->unsignedSmallInteger('required_installments');
            $table->dateTime('paid_at')->nullable();
            $table->decimal('payment_amount', 15, 2)->unsigned()->nullable();
            $table->enum('payment_method', ['Cash', 'Bank', 'Cheque'])->nullable();
            $table->string('reference_no', 64)->nullable();
            $table->string('notes', 255)->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->index(['credit_facility_id', 'cycle_no']);
            $table->index('sales_executive_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_executive_commissions');
    }
};
