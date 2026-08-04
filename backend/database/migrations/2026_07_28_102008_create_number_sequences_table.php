<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Atomic per-key counters behind every reference number NumberGenerator
 * issues (loan codes, receipts, FD numbers, journal codes, ...).
 *
 * The original table used branch_id/loan_type_id/funding_product_id/year
 * `NOT NULL DEFAULT 0` as a "not applicable" sentinel for whichever
 * dimensions a given module's format doesn't use — which is exactly why none
 * of the three ever had a real foreign key: 0 is never a real branches.id,
 * so an FK would reject every "global" counter row outright (see the
 * dropped 2026_07_30_000001_globalize_number_sequences migration, which
 * hard-codes branch_id=0 as the permanent, company-wide series key).
 *
 * Fixed here with real nullable FK columns plus four generated "shadow"
 * columns that COALESCE each one to 0 purely for the composite unique key.
 * Making the columns merely nullable on their own would have broken
 * uniqueness instead (MySQL treats every NULL in a unique index as distinct
 * from every other NULL, so two "global" counters for the same key could
 * both slip in) — the generated columns give real FK integrity and correct
 * uniqueness at the same time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('number_sequences', function (Blueprint $table) {
            $table->id();
            $table->enum('module', [
                'loan', 'customer', 'payment', 'fd_deposit', 'fixed_deposit', 'fd_withdrawal',
                'journal', 'expense', 'payment_voucher', 'speed_draft', 'speed_draft_payment',
            ]);
            // Reserved, not currently part of any key (NumberGenerator's {SEQ}
            // is always one company-wide series) — kept nullable/FK'd rather
            // than dropped so a future module that does need a per-branch
            // series doesn't need a schema change to get one.
            $table->foreignId('branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->foreignId('loan_type_id')->nullable()->constrained('loan_types')->restrictOnDelete();
            $table->foreignId('funding_product_id')->nullable()->constrained('funding_products')->restrictOnDelete();
            $table->unsignedSmallInteger('year')->nullable();
            $table->unsignedInteger('last_sequence')->default(0);
            $table->timestamps();
        });

        DB::statement('ALTER TABLE `number_sequences` ADD COLUMN `branch_key` BIGINT UNSIGNED GENERATED ALWAYS AS (COALESCE(`branch_id`, 0)) STORED');
        DB::statement('ALTER TABLE `number_sequences` ADD COLUMN `loan_type_key` BIGINT UNSIGNED GENERATED ALWAYS AS (COALESCE(`loan_type_id`, 0)) STORED');
        DB::statement('ALTER TABLE `number_sequences` ADD COLUMN `funding_product_key` BIGINT UNSIGNED GENERATED ALWAYS AS (COALESCE(`funding_product_id`, 0)) STORED');
        DB::statement('ALTER TABLE `number_sequences` ADD COLUMN `year_key` SMALLINT UNSIGNED GENERATED ALWAYS AS (COALESCE(`year`, 0)) STORED');

        Schema::table('number_sequences', function (Blueprint $table) {
            $table->unique(['module', 'branch_key', 'loan_type_key', 'funding_product_key', 'year_key'], 'uq_number_sequences_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('number_sequences');
    }
};
