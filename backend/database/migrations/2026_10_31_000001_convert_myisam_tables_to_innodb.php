<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * MyISAM has no transactions, so DB::transaction() could not roll these tables
 * back: a failure after the facility_payments insert left the payment saved
 * without its approval_requests row (an orphaned, un-approvable payment).
 * Every table must be InnoDB so a failed request saves nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $tables = DB::select(
            "SELECT table_name AS t FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE' AND engine <> 'InnoDB'"
        );
        foreach ($tables as $row) {
            DB::statement('ALTER TABLE `' . $row->t . '` ENGINE=InnoDB');
        }
    }

    public function down(): void
    {
        // Intentionally empty: never go back to a non-transactional engine.
    }
};
