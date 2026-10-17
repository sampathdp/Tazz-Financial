<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Document charge amount keyed in on the loan application. Null means "use the
 * product's processing_fee_percent of the financed amount"; a value overrides it
 * (the Document charge is still only created and paid at disbursement time).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE `loan_facility_details`
            ADD COLUMN `document_charge_amount` DECIMAL(15,2) NULL AFTER `down_payment`
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE `loan_facility_details` DROP COLUMN `document_charge_amount`');
    }
};
