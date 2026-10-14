<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Chart-of-accounts guards (audit C-4, C-12).
 *
 *  - chart_of_accounts.is_postable: header accounts that only group per-till / per-bank / per-fund child
 *    accounts (1010, 1015, 1020) must never receive a posting themselves, or the till-vs-ledger drift check
 *    and the cash-flow statement would miss it. LedgerPoster refuses a non-postable account.
 *  - cash_accounts.code: the original migration declared a unique index, but restored databases lost it and
 *    carry duplicates (e.g. two HO-CASH). Duplicates get their id appended, then the index is (re)created.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('chart_of_accounts', 'is_postable')) {
            Schema::table('chart_of_accounts', function (Blueprint $table) {
                $table->boolean('is_postable')->default(true)->after('is_system');
            });
        }
        DB::table('chart_of_accounts')->whereIn('code', ['1010', '1015', '1020'])->update(['is_postable' => false]);

        $dupes = DB::table('cash_accounts')->select('code')->whereNotNull('code')->groupBy('code')->havingRaw('COUNT(*) > 1')->pluck('code');
        foreach ($dupes as $code) {
            $ids = DB::table('cash_accounts')->where('code', $code)->orderBy('id')->pluck('id')->all();
            array_shift($ids); // the oldest keeps its code
            foreach ($ids as $id) {
                DB::table('cash_accounts')->where('id', $id)->update(['code' => substr($code . '-' . $id, 0, 24)]);
            }
        }

        $hasIndex = DB::select("SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'cash_accounts' AND index_name = 'uq_cash_accounts_code' LIMIT 1");
        if (!$hasIndex) {
            Schema::table('cash_accounts', function (Blueprint $table) {
                $table->unique('code', 'uq_cash_accounts_code');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('chart_of_accounts', 'is_postable')) {
            Schema::table('chart_of_accounts', function (Blueprint $table) {
                $table->dropColumn('is_postable');
            });
        }
    }
};
