<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Marketing executive + follow-up/recovery officer on a Speed Draft — mirrors loan_facility_details. */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE `speed_draft_facility_details`
            ADD COLUMN `loan_officer_id` BIGINT UNSIGNED NULL,
            ADD COLUMN `recovery_officer_id` BIGINT UNSIGNED NULL,
            ADD CONSTRAINT `sdfd_loan_officer_fk` FOREIGN KEY (`loan_officer_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
            ADD CONSTRAINT `sdfd_recovery_officer_fk` FOREIGN KEY (`recovery_officer_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE `speed_draft_facility_details` DROP FOREIGN KEY `sdfd_loan_officer_fk`, DROP FOREIGN KEY `sdfd_recovery_officer_fk`, DROP COLUMN `loan_officer_id`, DROP COLUMN `recovery_officer_id`');
    }
};
