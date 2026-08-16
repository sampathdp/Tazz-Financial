<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds a 'daily' rate_period so Speed Draft Types (and drafts issued from
 * them) can express a rate that accrues per day rather than per month/year —
 * see SpeedDraftScheduler, which switches its per-installment interest math
 * on this column.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE `speed_draft_types`
            MODIFY `rate_period` ENUM('monthly', 'annual', 'daily') DEFAULT 'monthly'
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE `credit_facilities`
            MODIFY `rate_period` ENUM('monthly', 'annual', 'daily') DEFAULT 'monthly'
        SQL);
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE `speed_draft_types`
            MODIFY `rate_period` ENUM('monthly', 'annual') DEFAULT 'monthly'
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE `credit_facilities`
            MODIFY `rate_period` ENUM('monthly', 'annual') DEFAULT 'monthly'
        SQL);
    }
};
