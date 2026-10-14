<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Inter-branch accounting (audit B-2), the standard "due from / due to" clearing-account method.
 *
 * When one branch's cash pays or receives money for a transaction that belongs to another branch, the
 * ledger writes one entry per branch, each balanced on its own, and joins them with these two accounts:
 *   1400 Inter-Branch Receivable (Due from Branches)  - the other branch owes this branch
 *   2400 Inter-Branch Payable (Due to Branches)       - this branch owes the other branch
 * Company-wide the two net to zero. journal_entries.counterparty_branch_id says who the other side is and
 * related_entry_id links the companion entries so a reversal undoes all of them together.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_entries', function (Blueprint $t) {
            if (!Schema::hasColumn('journal_entries', 'counterparty_branch_id')) {
                $t->unsignedBigInteger('counterparty_branch_id')->nullable()->after('branch_id');
            }
            if (!Schema::hasColumn('journal_entries', 'related_entry_id')) {
                $t->unsignedBigInteger('related_entry_id')->nullable()->index()->after('reversal_of_id');
            }
        });

        foreach ([
            ['1400', 'Inter-Branch Receivable (Due from Branches)', 'Asset', 'Debit'],
            ['2400', 'Inter-Branch Payable (Due to Branches)', 'Liability', 'Credit'],
        ] as [$code, $name, $type, $normal]) {
            if (!DB::table('chart_of_accounts')->where('code', $code)->exists()) {
                DB::table('chart_of_accounts')->insert([
                    'code' => $code, 'name' => $name, 'account_type' => $type, 'normal_balance' => $normal,
                    'parent_id' => null, 'is_system' => true, 'status' => 'Active', 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('journal_entries', function (Blueprint $t) {
            if (Schema::hasColumn('journal_entries', 'related_entry_id')) {
                $t->dropColumn('related_entry_id');
            }
            if (Schema::hasColumn('journal_entries', 'counterparty_branch_id')) {
                $t->dropColumn('counterparty_branch_id');
            }
        });
    }
};
