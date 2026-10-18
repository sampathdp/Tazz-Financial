<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Interest on company borrowings is now accrued monthly instead of being booked
 * only when it is paid (mirrors branch funding's interest_accrued_through /
 * accrued_interest_unpaid):
 *
 *  - borrowings.interest_accrued_through   : the date interest has been booked up to
 *  - borrowings.accrued_interest_unpaid    : accrued (Cr 2220) not yet paid out
 *  - borrowing_transactions.accrued_interest_cleared : the part of an InterestPayment
 *                                             that cleared 2220 rather than hitting 5110
 *                                             (so a void can restore it)
 *  - chart 2220 Accrued Interest Payable - Borrowings (Liability, credit-normal)
 *  - journal_entries.source_type += 'BorrowingAccrual' (appended to the live enum list)
 *
 * Existing active, interest-bearing, non-overdraft borrowings start accruing from
 * today: whatever was earned before is not back-booked, because it was (or will
 * be) expensed when paid.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('borrowings', function (Blueprint $table) {
            $table->date('interest_accrued_through')->nullable()->after('maturity_date');
            $table->decimal('accrued_interest_unpaid', 15, 2)->default(0)->after('interest_accrued_through');
        });
        Schema::table('borrowing_transactions', function (Blueprint $table) {
            $table->decimal('accrued_interest_cleared', 15, 2)->default(0)->after('outstanding_before');
        });

        DB::table('borrowings')
            ->where('status', 'Active')->where('type', '!=', 'Overdraft')
            ->update(['interest_accrued_through' => now()->toDateString()]);

        if (!DB::table('chart_of_accounts')->where('code', '2220')->exists()) {
            DB::table('chart_of_accounts')->insert([
                'code' => '2220', 'name' => 'Accrued Interest Payable - Borrowings', 'account_type' => 'Liability',
                'normal_balance' => 'Credit', 'parent_id' => null, 'is_system' => true, 'status' => 'Active',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $type = DB::selectOne("SELECT column_type AS t FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'journal_entries' AND column_name = 'source_type'")->t;
        if (!str_contains($type, "'BorrowingAccrual'")) {
            DB::statement('ALTER TABLE `journal_entries` MODIFY `source_type` ' . substr($type, 0, -1) . ",'BorrowingAccrual') DEFAULT NULL");
        }
    }

    public function down(): void
    {
        Schema::table('borrowing_transactions', fn (Blueprint $t) => $t->dropColumn('accrued_interest_cleared'));
        Schema::table('borrowings', fn (Blueprint $t) => $t->dropColumn(['interest_accrued_through', 'accrued_interest_unpaid']));
        // 2220 and the enum value are left in place — journal lines may reference them.
    }
};
