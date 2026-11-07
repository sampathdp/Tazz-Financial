<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Lapsed: a loan whose last installment due date has passed with a balance
 * still outstanding. Set nightly by loans:mark-lapsed. A lapsed loan still
 * takes payments, and can be closed, rescheduled or written off.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE `credit_facilities`
            MODIFY `status` ENUM('Pending', 'Approved', 'Rejected', 'Cancelled', 'Active', 'Lapsed', 'Closed', 'Restructured', 'WrittenOff') DEFAULT 'Pending'
        SQL);
    }

    public function down(): void
    {
        DB::table('credit_facilities')->where('status', 'Lapsed')->update(['status' => 'Active']);

        DB::statement(<<<'SQL'
            ALTER TABLE `credit_facilities`
            MODIFY `status` ENUM('Pending', 'Approved', 'Rejected', 'Cancelled', 'Active', 'Closed', 'Restructured', 'WrittenOff') DEFAULT 'Pending'
        SQL);
    }
};
