<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Populated only for Leasing-category loan products (see LoanType.category) —
 * the registration number of the vehicle being leased. Deliberately separate
 * from facility_collateral/facility_collateral_vehicle_details: that system
 * is an optional, multi-item security list attachable to any loan regardless
 * of category, whereas this is a single mandatory-for-Leasing reference field
 * on the loan itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE `loan_facility_details`
            ADD COLUMN `vehicle_no` VARCHAR(20) NULL AFTER `loan_type_id`
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE `loan_facility_details` DROP COLUMN `vehicle_no`');
    }
};
