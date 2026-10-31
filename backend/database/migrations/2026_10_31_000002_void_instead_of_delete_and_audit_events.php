<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Financial history is voided, never deleted. These six tables used to lose the row when a posting was
 * undone (the ledger side was reversed, the business row was DELETEd). They get voided_at / voided_by /
 * void_reason instead, and the "one live row" unique keys now ignore voided rows (a generated column that
 * is NULL once voided - MySQL unique indexes allow many NULLs), so a withdrawal or renewal can be redone
 * after a void. Also adds the waiver -> journal link and a database audit table.
 * Idempotent: every step checks before it changes anything.
 */
return new class extends Migration
{
    private const TABLES = ['fd_withdrawals', 'fd_part_payments', 'pawning_renewals', 'pawning_redemptions', 'borrowing_transactions', 'facility_waivers'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                if (!Schema::hasColumn($table, 'voided_at')) {
                    $t->timestamp('voided_at')->nullable();
                }
                if (!Schema::hasColumn($table, 'voided_by')) {
                    $t->unsignedBigInteger('voided_by')->nullable();
                }
                if (!Schema::hasColumn($table, 'void_reason')) {
                    $t->string('void_reason', 255)->nullable();
                }
            });
        }

        Schema::table('facility_waivers', function (Blueprint $t) {
            if (!Schema::hasColumn('facility_waivers', 'journal_entry_id')) {
                $t->unsignedBigInteger('journal_entry_id')->nullable();
            }
            if (!Schema::hasColumn('facility_waivers', 'posted_amount')) {
                $t->decimal('posted_amount', 15, 2)->nullable();
            }
        });

        if (DB::getDriverName() === 'mysql') {
            // fd_withdrawals: one LIVE withdrawal per FD.
            if (!Schema::hasColumn('fd_withdrawals', 'live_fd_id')) {
                DB::statement('ALTER TABLE `fd_withdrawals` ADD INDEX `fd_withdrawals_fd_idx` (`fixed_deposit_id`)');
                DB::statement('ALTER TABLE `fd_withdrawals` ADD COLUMN `live_fd_id` BIGINT UNSIGNED GENERATED ALWAYS AS (IF(`voided_at` IS NULL, `fixed_deposit_id`, NULL)) STORED');
                DB::statement('ALTER TABLE `fd_withdrawals` ADD UNIQUE INDEX `fd_withdrawals_live_fd_unique` (`live_fd_id`)');
                DB::statement('ALTER TABLE `fd_withdrawals` DROP INDEX `fd_withdrawals_fixed_deposit_id_unique`');
            }
            // pawning_renewals: renewal numbers are unique among LIVE renewals.
            if (!Schema::hasColumn('pawning_renewals', 'live_renewal_no')) {
                DB::statement('ALTER TABLE `pawning_renewals` ADD INDEX `pawning_renewals_facility_idx` (`credit_facility_id`)');
                DB::statement('ALTER TABLE `pawning_renewals` ADD COLUMN `live_renewal_no` INT GENERATED ALWAYS AS (IF(`voided_at` IS NULL, `renewal_no`, NULL)) STORED');
                DB::statement('ALTER TABLE `pawning_renewals` ADD UNIQUE INDEX `pawning_renewals_live_unique` (`credit_facility_id`, `live_renewal_no`)');
                DB::statement('ALTER TABLE `pawning_renewals` DROP INDEX `pawning_renewals_credit_facility_id_renewal_no_unique`');
            }
        }

        if (!Schema::hasTable('audit_events')) {
            Schema::create('audit_events', function (Blueprint $t) {
                $t->id();
                $t->string('request_id', 64)->nullable()->index();
                $t->unsignedBigInteger('user_id')->nullable()->index();
                $t->string('method', 8);
                $t->string('path', 255)->index();
                $t->unsignedSmallInteger('status');
                $t->string('ip', 45)->nullable();
                $t->json('payload')->nullable();
                $t->timestamp('created_at')->useCurrent()->index();
            });
        }
    }

    public function down(): void
    {
        // Intentionally empty: dropping these would destroy audit history.
    }
};
