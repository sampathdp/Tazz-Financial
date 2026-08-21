<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One row per article listed in an auction batch. outstanding_capital/
 * outstanding_interest snapshot what was owed at listing time — the debt
 * that triggered the sale, not a live balance. surplus_amount (hammer_price
 * minus outstanding + auction cost, when positive) legally belongs to the
 * pledger, not the lender — settlement_status tracks whether it has
 * actually been paid out or is still held pending a claim.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pawning_auction_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pawning_auction_id')->constrained('pawning_auctions')->cascadeOnDelete();
            $table->foreignId('facility_collateral_id')->constrained('facility_collateral')->restrictOnDelete();
            $table->foreignId('credit_facility_id')->constrained('credit_facilities')->restrictOnDelete();
            $table->decimal('outstanding_capital', 15, 2)->unsigned();
            $table->decimal('outstanding_interest', 15, 2)->unsigned();
            $table->decimal('reserve_price', 15, 2)->unsigned()->nullable();
            $table->decimal('hammer_price', 15, 2)->unsigned()->nullable();
            $table->string('buyer_name', 128)->nullable();
            $table->string('buyer_nic', 15)->nullable();
            $table->decimal('surplus_amount', 15, 2)->unsigned()->default(0);
            $table->enum('settlement_status', ['Pending', 'Refunded', 'Forfeited'])->default('Pending');
            $table->enum('status', ['Listed', 'Sold', 'Unsold', 'Cancelled'])->default('Listed');
            $table->dateTime('sold_at')->nullable();
            $table->timestamps();
            $table->unique(['pawning_auction_id', 'facility_collateral_id'], 'uq_pai_auction_collateral');
            $table->index('credit_facility_id');
        });

        DB::statement('ALTER TABLE `pawning_auction_items` ADD CONSTRAINT `chk_pai_outstanding` CHECK (`outstanding_capital` + `outstanding_interest` > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('pawning_auction_items');
    }
};
