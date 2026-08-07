<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Some FD products offer the customer both interest_method options (e.g.
 * monthly payout or lump sum at maturity) rather than being locked to one.
 * interest_method stays as the product's default/only method when this flag
 * is false; the FD create form only shows a chooser when it's true (see
 * FixedDepositController::store()).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE `funding_products`
            ADD COLUMN `allow_method_choice` TINYINT(1) NOT NULL DEFAULT 0 AFTER `interest_method`
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE `funding_products` DROP COLUMN `allow_method_choice`');
    }
};
