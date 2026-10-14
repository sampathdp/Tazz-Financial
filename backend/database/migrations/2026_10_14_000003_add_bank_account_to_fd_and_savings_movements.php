<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FD and Savings payments made by Bank / Cheque had no company bank account on the record, so they
 * were never posted: no ledger entry and no bank balance change. Capture the account so they post
 * like a cash movement does (see MoneyLeg).
 */
return new class extends Migration
{
    private const TABLES = ['fd_deposits', 'fd_interest_payments', 'fd_withdrawals', 'fd_part_payments', 'savings_transactions'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (Schema::hasColumn($table, 'bank_account_id')) {
                continue;
            }
            Schema::table($table, function (Blueprint $t) {
                $t->unsignedBigInteger('bank_account_id')->nullable();
                $t->foreign('bank_account_id')->references('id')->on('bank_accounts')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (!Schema::hasColumn($table, 'bank_account_id')) {
                continue;
            }
            Schema::table($table, function (Blueprint $t) {
                $t->dropForeign(['bank_account_id']);
                $t->dropColumn('bank_account_id');
            });
        }
    }
};
