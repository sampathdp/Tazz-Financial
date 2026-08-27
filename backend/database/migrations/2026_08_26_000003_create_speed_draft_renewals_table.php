<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Audit/link row for SpeedDraftController::renew() — mirrors fd_renewals
 * (new-account-close-old, not pawning_renewals' in-place extension). Unlike
 * fd_renewals (where the old principal always rolls forward with zero cash
 * involved), a Speed Draft renewal can mix a real cash payment with a
 * capitalized remainder, so this table records both: whatever of
 * interest_due/penalty_due wasn't collected in cash is what got capitalized
 * into capital_after (see SpeedDraftLedgerPoster::postCapitalizedRenewalIncome
 * for the non-cash income entry that keeps the books "as if collected").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('speed_draft_renewals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('old_credit_facility_id')->constrained('credit_facilities');
            $table->foreignId('new_credit_facility_id')->unique()->constrained('credit_facilities');
            $table->decimal('capital_before', 15, 2)->unsigned();
            $table->decimal('interest_due', 15, 2)->unsigned()->default(0);
            $table->decimal('interest_collected', 15, 2)->unsigned()->default(0);
            $table->decimal('penalty_due', 15, 2)->unsigned()->default(0);
            $table->decimal('penalty_collected', 15, 2)->unsigned()->default(0);
            $table->decimal('capital_payment', 15, 2)->unsigned()->default(0);
            $table->decimal('capital_after', 15, 2)->unsigned();
            $table->smallInteger('new_term_months')->unsigned();
            $table->foreignId('facility_payment_id')->nullable()->constrained('facility_payments')->nullOnDelete();
            $table->foreignId('renewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('renewed_at');
            $table->string('notes', 255)->nullable();
            $table->timestamps();
            $table->index('old_credit_facility_id');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE `speed_draft_renewals` ADD CONSTRAINT `chk_sdr_capital_after`
            CHECK (`capital_after` = `capital_before` - `capital_payment`
                + (`interest_due` - `interest_collected`) + (`penalty_due` - `penalty_collected`))
        SQL);
        DB::statement('ALTER TABLE `speed_draft_renewals` ADD CONSTRAINT `chk_sdr_interest_collected` CHECK (`interest_collected` <= `interest_due`)');
        DB::statement('ALTER TABLE `speed_draft_renewals` ADD CONSTRAINT `chk_sdr_penalty_collected` CHECK (`penalty_collected` <= `penalty_due`)');
    }

    public function down(): void
    {
        Schema::dropIfExists('speed_draft_renewals');
    }
};
