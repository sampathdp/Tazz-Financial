<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One-to-one with fixed_deposits — the closing payout voucher. interest_amount
 * is only ever nonzero for a maturity-method FD (a monthly-method FD already
 * paid all its interest out via fd_interest_payments over the term — see
 * FixedDepositController::withdraw()).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fd_withdrawals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fixed_deposit_id')->unique()->constrained('fixed_deposits');
            $table->string('voucher_no', 24)->unique();
            $table->decimal('principal_amount', 15, 2)->unsigned();
            $table->decimal('interest_amount', 15, 2)->unsigned()->default(0);
            $table->decimal('total_amount', 15, 2)->unsigned();
            $table->enum('payment_method', ['Cash', 'Bank', 'Cheque']);
            $table->string('reference_no', 64)->nullable();
            $table->text('notes')->nullable();
            $table->date('closed_at');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        DB::statement('ALTER TABLE `fd_withdrawals` ADD CONSTRAINT `chk_fdw_total` CHECK (`total_amount` = `principal_amount` + `interest_amount`)');
    }

    public function down(): void
    {
        Schema::dropIfExists('fd_withdrawals');
    }
};
