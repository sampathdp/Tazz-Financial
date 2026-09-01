<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Finance module — Phase 4. Links a payment voucher to the immutable
 * finance_transactions row that actually moved the money for it, so a void
 * can go straight through FinancePostingService::reverse() (which restores
 * the balance and mirrors the GL) instead of the voucher having to unwind
 * the two by hand.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_vouchers', function (Blueprint $t) {
            $t->unsignedBigInteger('finance_transaction_id')->nullable()->after('journal_entry_id');
            $t->foreign('finance_transaction_id', 'fk_pv_finance_txn')
                ->references('id')->on('finance_transactions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payment_vouchers', function (Blueprint $t) {
            $t->dropForeign('fk_pv_finance_txn');
            $t->dropColumn('finance_transaction_id');
        });
    }
};
