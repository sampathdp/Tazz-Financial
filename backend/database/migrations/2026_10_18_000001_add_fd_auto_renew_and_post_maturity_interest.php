<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FD auto-renew + post-maturity interest.
 *
 * funding_products.post_maturity_rate_percent: default annual rate paid for the days an
 *   FD is held past its maturity date before it is withdrawn (null/0 = none). The
 *   Withdraw screen pre-fills from it and lets the operator override the amount.
 * fixed_deposits.auto_renew*: customer instruction to roll the FD over at maturity -
 *   principal_only (interest paid out) or principal_and_interest (interest capitalised).
 * fd_withdrawals.post_maturity_*: how much of interest_amount was for days past maturity.
 * fd_renewals: records interest settled at renewal; the principal check now allows the
 *   capitalised interest to be added to the new principal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('funding_products', function (Blueprint $table) {
            $table->decimal('post_maturity_rate_percent', 6, 3)->nullable()->after('premature_withdrawal_rate_percent');
        });

        Schema::table('fixed_deposits', function (Blueprint $table) {
            $table->boolean('auto_renew')->default(false)->after('lien_amount');
            $table->enum('auto_renew_mode', ['principal_only', 'principal_and_interest'])->nullable()->after('auto_renew');
            $table->smallInteger('auto_renew_term_months')->unsigned()->nullable()->after('auto_renew_mode');
        });

        Schema::table('fd_withdrawals', function (Blueprint $table) {
            $table->smallInteger('post_maturity_days')->unsigned()->default(0)->after('adjusted_amount');
            $table->decimal('post_maturity_rate_percent', 6, 3)->nullable()->after('post_maturity_days');
            $table->decimal('post_maturity_interest', 15, 2)->unsigned()->default(0)->after('post_maturity_rate_percent');
        });

        // The constraint is missing on databases built from the schema dump, so only drop it when it exists.
        if (DB::selectOne("SELECT 1 AS x FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'fd_renewals' AND CONSTRAINT_NAME = 'chk_fdr_new_principal' AND CONSTRAINT_TYPE = 'CHECK'")) {
            DB::statement('ALTER TABLE `fd_renewals` DROP CONSTRAINT `chk_fdr_new_principal`');
        }
        Schema::table('fd_renewals', function (Blueprint $table) {
            $table->decimal('interest_amount', 15, 2)->unsigned()->default(0)->after('new_principal');
            $table->decimal('capitalised_interest', 15, 2)->unsigned()->default(0)->after('interest_amount');
            $table->string('interest_payment_method', 16)->nullable()->after('capitalised_interest');
            $table->boolean('is_auto')->default(false)->after('interest_payment_method');
        });
        DB::statement('ALTER TABLE `fd_renewals` ADD CONSTRAINT `chk_fdr_new_principal` CHECK (`new_principal` = `old_principal` + `capitalised_interest` + `additional_deposit_amount`)');

        // The cash payout of maturity interest at renewal is a cash leg keyed to the renewal.
        $isTable = DB::selectOne("SELECT TABLE_TYPE AS t FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cashbook_entries'");
        if ($isTable && $isTable->t === 'BASE TABLE') {
            $type = DB::selectOne("SHOW COLUMNS FROM `cashbook_entries` WHERE Field = 'source_type'")->Type;
            if (!str_contains($type, "'FdRenewal'")) {
                DB::statement('ALTER TABLE `cashbook_entries` MODIFY `source_type` ' . substr($type, 0, -1) . ",'FdRenewal') DEFAULT NULL");
            }
        }
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE `fd_renewals` DROP CONSTRAINT `chk_fdr_new_principal`');
        Schema::table('fd_renewals', function (Blueprint $table) {
            $table->dropColumn(['interest_amount', 'capitalised_interest', 'interest_payment_method', 'is_auto']);
        });
        DB::statement('ALTER TABLE `fd_renewals` ADD CONSTRAINT `chk_fdr_new_principal` CHECK (`new_principal` = `old_principal` + `additional_deposit_amount`)');

        Schema::table('fd_withdrawals', function (Blueprint $table) {
            $table->dropColumn(['post_maturity_days', 'post_maturity_rate_percent', 'post_maturity_interest']);
        });
        Schema::table('fixed_deposits', function (Blueprint $table) {
            $table->dropColumn(['auto_renew', 'auto_renew_mode', 'auto_renew_term_months']);
        });
        Schema::table('funding_products', function (Blueprint $table) {
            $table->dropColumn('post_maturity_rate_percent');
        });
    }
};
