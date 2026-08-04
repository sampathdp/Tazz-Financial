<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Populated only for Lease-category Speed Draft products (see
 * SpeedDraftType.category) — the registration number of the leased vehicle.
 * Mirrors loan_facility_details.vehicle_no; kept on the product-specific
 * detail table (not shared) since speed_draft_facility_details and
 * loan_facility_details are already separate 1:1 extensions by design.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE `speed_draft_facility_details`
            ADD COLUMN `vehicle_no` VARCHAR(20) NULL AFTER `speed_draft_type_id`
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE `speed_draft_facility_details` DROP COLUMN `vehicle_no`');
    }
};
