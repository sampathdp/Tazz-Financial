<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * fixed_deposits.lien_amount - maintained cache of the Active lien in
 * facility_collateral_fd_details (0 = free). It lets withdraw / renew / part
 * payment / FD lists check "is this FD pledged?" from the row they already
 * loaded, with no join. Same cache-of-a-source-of-truth convention as
 * credit_facilities.outstanding_balance_cache: the lien table is the truth and
 * both must be written in one transaction.
 *
 * fd_withdrawals.settled_credit_facility_id marks a withdrawal that paid a
 * loan off (FD adjusted against its loan) instead of paying the customer
 * out, so reports can tell the two apart.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fixed_deposits', function (Blueprint $table) {
            $table->decimal('lien_amount', 15, 2)->unsigned()->default(0)->after('maturity_amount');
            $table->index('lien_amount');
        });
        DB::statement('ALTER TABLE `fixed_deposits` ADD CONSTRAINT `chk_fd_lien` CHECK (`lien_amount` <= `principal_amount`)');

        Schema::table('fd_withdrawals', function (Blueprint $table) {
            $table->foreignId('settled_credit_facility_id')->nullable()->after('closed_at')
                ->constrained('credit_facilities')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('fd_withdrawals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('settled_credit_facility_id');
        });
        DB::statement('ALTER TABLE `fixed_deposits` DROP CHECK `chk_fd_lien`');
        Schema::table('fixed_deposits', function (Blueprint $table) {
            $table->dropIndex(['lien_amount']);
            $table->dropColumn('lien_amount');
        });
    }
};
