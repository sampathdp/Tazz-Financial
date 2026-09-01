<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Finance module — Phase 0. Gives cash_accounts an identity beyond "a till
 * that belongs to a branch" so the new Finance module can model cashier
 * floats, petty cash, collection cash and cash-in-transit as ordinary cash
 * accounts (each with its own 1010-{id} GL leaf via
 * ChartOfAccount::forCashTill) rather than needing parallel tables.
 *
 *  - account_type          what this account is for (drives branchPrimary()
 *                          selection, the cashier view, reconciliation, and
 *                          the "who is holding company cash" register).
 *  - responsible_user_id   the person accountable for the cash — the float
 *                          holder / custodian. Null for branch/HO tills.
 *  - parent_cash_account_id a float's home account: where it was issued from
 *                          and where it settles back to.
 *  - allow_negative        per-account escape hatch from the no-negative-cash
 *                          rule the FinancePostingService will enforce.
 *  - code                  human account code (BR03-CASH, FLT-KAUSHALYA).
 *  - last_reconciled_at    surfaced as an "unreconciled for N days" flag.
 *
 * Backfill: every existing row is a branch/head-office till today, so it is
 * typed HeadOfficeMain (branches.is_head_office) or BranchMain and given a
 * {branch code}-CASH code (suffixed with the id if a branch has more than
 * one). No InTransit accounts are created here — that is Phase 5 (transfers).
 */
return new class extends Migration
{
    private const TYPES = [
        'HeadOfficeMain', 'BranchMain', 'PettyCash',
        'CashierFloat', 'CollectionCash', 'InTransit', 'Other',
    ];

    public function up(): void
    {
        Schema::table('cash_accounts', function (Blueprint $t) {
            $t->string('code', 24)->nullable()->after('id');
            $t->enum('account_type', self::TYPES)->default('BranchMain')->after('branch_id');
            $t->unsignedBigInteger('responsible_user_id')->nullable()->after('account_type');
            $t->unsignedBigInteger('parent_cash_account_id')->nullable()->after('responsible_user_id');
            $t->boolean('allow_negative')->default(false)->after('current_balance');
            $t->dateTime('last_reconciled_at')->nullable()->after('allow_negative');

            $t->foreign('responsible_user_id', 'fk_cash_accounts_responsible_user')
                ->references('id')->on('users')->nullOnDelete();
            $t->foreign('parent_cash_account_id', 'fk_cash_accounts_parent')
                ->references('id')->on('cash_accounts')->nullOnDelete();
            $t->index(['account_type', 'branch_id'], 'ix_cash_accounts_type_branch');
        });

        $rows = DB::table('cash_accounts')
            ->join('branches', 'branches.id', '=', 'cash_accounts.branch_id')
            ->orderBy('cash_accounts.id')
            ->get(['cash_accounts.id', 'cash_accounts.branch_id', 'branches.code as branch_code', 'branches.is_head_office']);

        $seenPerBranch = [];
        foreach ($rows as $row) {
            $n = $seenPerBranch[$row->branch_id] ?? 0;
            $seenPerBranch[$row->branch_id] = $n + 1;
            $code = $n === 0 ? "{$row->branch_code}-CASH" : "{$row->branch_code}-CASH-{$row->id}";

            DB::table('cash_accounts')->where('id', $row->id)->update([
                'account_type' => $row->is_head_office ? 'HeadOfficeMain' : 'BranchMain',
                'code' => $code,
            ]);
        }

        Schema::table('cash_accounts', function (Blueprint $t) {
            $t->unique('code', 'uq_cash_accounts_code');
        });
    }

    public function down(): void
    {
        Schema::table('cash_accounts', function (Blueprint $t) {
            $t->dropForeign('fk_cash_accounts_responsible_user');
            $t->dropForeign('fk_cash_accounts_parent');
            $t->dropUnique('uq_cash_accounts_code');
            $t->dropIndex('ix_cash_accounts_type_branch');
            $t->dropColumn([
                'code', 'account_type', 'responsible_user_id',
                'parent_cash_account_id', 'allow_negative', 'last_reconciled_at',
            ]);
        });
    }
};
