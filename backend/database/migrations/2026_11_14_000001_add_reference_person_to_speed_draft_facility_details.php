<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Speed Draft parity with loans: optional reference person (name / NIC or other ID / address).
 * Guarantors and co-applicants reuse the shared facility_guarantors / facility_co_applicants tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE `speed_draft_facility_details`
            ADD COLUMN `reference_name` VARCHAR(150) NULL AFTER `document_charge_amount`,
            ADD COLUMN `reference_id_no` VARCHAR(64) NULL AFTER `reference_name`,
            ADD COLUMN `reference_address` VARCHAR(255) NULL AFTER `reference_id_no`
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE `speed_draft_facility_details` DROP COLUMN `reference_address`, DROP COLUMN `reference_id_no`, DROP COLUMN `reference_name`');
    }
};
