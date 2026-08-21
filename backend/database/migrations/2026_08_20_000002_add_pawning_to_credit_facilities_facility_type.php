<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds Pawning as a third facility_type on the shared credit_facilities
 * header, alongside Loan/SpeedDraft — see that table's migration for the
 * shared-header rationale. Pawning's product-specific columns live on the
 * new pawning_facility_details 1:1 extension, same pattern as
 * loan_facility_details/speed_draft_facility_details.
 *
 * term_months stays as-is (no term_days added) — a pawning ticket's real
 * cycle length lives on pawning_types.cycle_days / pawning_facility_details.
 * repay_due_date; term_months is only ever a coarse month-equivalent
 * (ceil(cycle_days/30)) kept populated purely to satisfy chk_cf_term, which
 * every facility_type must pass.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE `credit_facilities`
            MODIFY `facility_type` ENUM('Loan', 'SpeedDraft', 'Pawning') NOT NULL
        SQL);
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE `credit_facilities`
            MODIFY `facility_type` ENUM('Loan', 'SpeedDraft') NOT NULL
        SQL);
    }
};
