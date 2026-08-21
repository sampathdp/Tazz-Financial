<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Full or partial settlement event. redemption_type=Partial exists because
 * one pawning ticket can hold several articles and a customer can redeem a
 * subset of them, paying the proportional capital+interest for just those —
 * see pawning_redemption_items for which articles a given row released.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pawning_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('credit_facility_id')->constrained('credit_facilities')->cascadeOnDelete();
            $table->enum('redemption_type', ['Full', 'Partial']);
            $table->decimal('capital_settled', 15, 2)->unsigned();
            $table->decimal('interest_settled', 15, 2)->unsigned();
            $table->decimal('total_settled', 15, 2)->unsigned();
            $table->string('receipt_no', 24)->unique()->nullable();
            $table->foreignId('facility_payment_id')->nullable()->constrained('facility_payments')->nullOnDelete();
            $table->foreignId('settled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('settled_at');
            $table->index(['credit_facility_id', 'settled_at']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE `pawning_redemptions` ADD CONSTRAINT `chk_pr_total`
            CHECK (`total_settled` = `capital_settled` + `interest_settled`)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('pawning_redemptions');
    }
};
