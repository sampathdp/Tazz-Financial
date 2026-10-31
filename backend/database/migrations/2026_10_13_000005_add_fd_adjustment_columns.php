<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Settling a loan from its pledged FD (FdLienService::settle).
 *
 * facility_payments.payment_method += 'FdAdjustment': the repayment is real
 * (allocated to the schedule like any payment) but no cash was received - the
 * money came out of the customer's own FD. It is deliberately not Cash/Bank so
 * till reports and cheque flows never pick it up.
 *
 * fd_withdrawals.adjusted_amount: the part of the FD payout applied to the
 * loan; the rest (total_amount - adjusted_amount) is paid to the customer via
 * payment_method. Zero for an ordinary withdrawal.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE `facility_payments` MODIFY `payment_method` ENUM('Cash', 'Bank', 'Cheque', 'FdAdjustment') NOT NULL DEFAULT 'Cash'");

        Schema::table('fd_withdrawals', function (Blueprint $table) {
            $table->decimal('adjusted_amount', 15, 2)->unsigned()->default(0)->after('total_amount');
        });
        DB::statement('ALTER TABLE `fd_withdrawals` ADD CONSTRAINT `chk_fdw_adjusted` CHECK (`adjusted_amount` <= `total_amount`)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE `fd_withdrawals` DROP CONSTRAINT `chk_fdw_adjusted`');
        Schema::table('fd_withdrawals', function (Blueprint $table) {
            $table->dropColumn('adjusted_amount');
        });
        DB::statement("ALTER TABLE `facility_payments` MODIFY `payment_method` ENUM('Cash', 'Bank', 'Cheque') NOT NULL DEFAULT 'Cash'");
    }
};
