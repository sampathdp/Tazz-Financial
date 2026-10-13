<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * FixedDeposit is its own collateral_type (not 'Other') so a lien row in
 * facility_collateral_fd_details is guaranteed to exist for it, same
 * discriminator pattern as PawnedJewelry.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE `facility_collateral`
            MODIFY `collateral_type` ENUM('Vehicle', 'Jewelry', 'PawnedJewelry', 'FixedDeposit', 'Property', 'Other') NOT NULL
        SQL);
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE `facility_collateral`
            MODIFY `collateral_type` ENUM('Vehicle', 'Jewelry', 'PawnedJewelry', 'Property', 'Other') NOT NULL
        SQL);
    }
};
