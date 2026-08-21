<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * PawnedJewelry is deliberately distinct from the existing Jewelry value —
 * SpeedDraft's Jewelry collateral is descriptive backing info only
 * (facility_collateral_jewelry_details: weight/purity/appraised_value, one
 * flat snapshot). A pawned article is individually redeemable and
 * auctionable, needs gross vs net weight and declared vs tested karat, and
 * carries its own lifecycle (item_status) — see
 * facility_collateral_pawn_details. Routing it through a separate enum
 * value keeps that extra state from ever leaking onto a SpeedDraft row.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE `facility_collateral`
            MODIFY `collateral_type` ENUM('Vehicle', 'Jewelry', 'PawnedJewelry', 'Property', 'Other') NOT NULL
        SQL);
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE `facility_collateral`
            MODIFY `collateral_type` ENUM('Vehicle', 'Jewelry', 'Property', 'Other') NOT NULL
        SQL);
    }
};
