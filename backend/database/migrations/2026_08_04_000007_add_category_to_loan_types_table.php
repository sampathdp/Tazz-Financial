<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Flat business-line classification for a loan product, independent of where
 * it sits in the parent_id tree — lets other code (reports, dashboards,
 * badges) identify "is this a Consumer/SME/... loan" without walking the
 * parent chain. Null for non-product (category/folder) nodes, same rule as
 * the other product-only columns on this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE `loan_types`
            ADD COLUMN `category` ENUM('Consumer','Leasing','Mortgage','RealEstate','SME') DEFAULT NULL AFTER `is_product`
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE `loan_types` DROP COLUMN `category`');
    }
};
