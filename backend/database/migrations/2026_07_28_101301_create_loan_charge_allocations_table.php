<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateLoanChargeAllocationsTable extends Migration
{
    /**
     * Mirrors loan_payment_allocations, but for loan_charges — needed so voiding
     * a payment can precisely reverse how much of it went to which charge.
     */
    public function up()
    {
        Schema::create('loan_charge_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_payment_id')->constrained('loan_payments')->cascadeOnDelete();
            $table->foreignId('loan_charge_id')->constrained('loan_charges')->restrictOnDelete();
            $table->decimal('amount_applied', 15, 2)->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('loan_charge_allocations');
    }
}
