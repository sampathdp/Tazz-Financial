<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Daily Savings — registers the maker-checker points the module uses, on the
 * shared approval engine (approval_types + approval_requests), same as
 * expense_approval / payment_settlement:
 *
 *   savings_withdrawal   : a withdrawal at or above the finance-settings
 *                          threshold is held until approved
 *   savings_closure      : closing an account and paying out its balance
 *   savings_interest_run : posting a batch interest run to the ledger
 *   savings_txn_void     : voiding a posted savings transaction
 *
 * Approve/reject are gated inline by ApprovalType::isAuthorized(<code>) — who
 * may approve is admin-manageable data, not the module permission grid.
 */
return new class extends Migration
{
    private const TYPES = [
        ['savings_withdrawal', 'Savings Withdrawal', 'Approving a large savings withdrawal before the cash is paid out.'],
        ['savings_closure', 'Savings Account Closure', 'Approving the closure of a savings account and payout of its balance.'],
        ['savings_interest_run', 'Savings Interest Run', 'Approving a batch savings interest posting before it hits the ledger.'],
        ['savings_txn_void', 'Savings Transaction Void', 'Approving the reversal of a posted savings transaction.'],
    ];

    public function up(): void
    {
        $now = now();

        // Threshold (LKR) at or above which a withdrawal needs a
        // savings_withdrawal approval before the cash is paid out. 0 = off.
        if (!DB::table('finance_settings')->where('key', 'savings_large_withdrawal_threshold')->exists()) {
            DB::table('finance_settings')->insert([
                'key' => 'savings_large_withdrawal_threshold', 'value' => '0',
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        foreach (self::TYPES as [$code, $name, $description]) {
            if (DB::table('approval_types')->where('code', $code)->exists()) {
                continue;
            }
            DB::table('approval_types')->insert([
                'code' => $code,
                'name' => $name,
                'description' => $description,
                'is_system' => true,
                'required_approvals' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('finance_settings')->where('key', 'savings_large_withdrawal_threshold')->delete();

        $codes = array_column(self::TYPES, 0);
        $ids = DB::table('approval_types')->whereIn('code', $codes)->pluck('id');
        if ($ids->isNotEmpty()) {
            DB::table('approval_requests')->whereIn('approval_type_id', $ids)->delete();
            DB::table('approval_types')->whereIn('id', $ids)->delete();
        }
    }
};
