<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per auction batch/event (typically one branch, one sale date,
 * covering every article that ran past its grace + auction-eligible
 * window). notice_published_date records when the statutory public notice
 * ran — required before sale under the CBSL pawning directions. Individual
 * lots are pawning_auction_items.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pawning_auctions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->string('auction_no', 24)->unique();
            $table->date('scheduled_date');
            $table->date('notice_published_date')->nullable();
            $table->string('auctioneer_name', 128)->nullable();
            $table->string('venue', 255)->nullable();
            $table->enum('status', ['Scheduled', 'Completed', 'Cancelled'])->default('Scheduled');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['branch_id', 'scheduled_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pawning_auctions');
    }
};
