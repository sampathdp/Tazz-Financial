<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Speed Draft parity with loans:
 *  - interest_method: 'reducing_balance' prices each month's interest on the capital still outstanding
 *    (so it falls after capital payments); 'flat' always prices it on the original capital.
 *  - document_charge_amount: keyed on the application; becomes a Document charge that must be paid
 *    before the draft can be disbursed.
 *  - payment_type 'Charges': a receipt that pays outstanding charges (Document / Visit / Legal ...).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE `speed_draft_facility_details`
            ADD COLUMN `interest_method` ENUM('reducing_balance', 'flat') NOT NULL DEFAULT 'reducing_balance' AFTER `payment_frequency`,
            ADD COLUMN `document_charge_amount` DECIMAL(15,2) NULL AFTER `interest_method`
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE `facility_payments`
            MODIFY `payment_type` ENUM('Regular', 'Interest', 'CapitalReduction', 'Settlement', 'ManualAllocation', 'Charges') DEFAULT 'Regular'
        SQL);
    }

    public function down(): void
    {
        DB::table('facility_payments')->where('payment_type', 'Charges')->update(['payment_type' => 'Regular']);

        DB::statement(<<<'SQL'
            ALTER TABLE `facility_payments`
            MODIFY `payment_type` ENUM('Regular', 'Interest', 'CapitalReduction', 'Settlement', 'ManualAllocation') DEFAULT 'Regular'
        SQL);

        DB::statement('ALTER TABLE `speed_draft_facility_details` DROP COLUMN `document_charge_amount`, DROP COLUMN `interest_method`');
    }
};
