<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Finance module — Phase 2. Registers the approval points the Finance module
 * needs on the shared approval engine (approval_types / approval_requests),
 * same pattern as cash_transfer / expense_approval.
 *
 *  - finance_request_payment    outbound cash/bank against a finance_request
 *                               (disbursement, expense, supplier, refund,
 *                               advance, branch funding, commission, FD
 *                               payout).
 *  - finance_transfer           a cash<->cash / cash<->bank / branch<->branch
 *                               transfer (supersedes cash_transfer for the
 *                               Finance flows; cash_transfer stays for the
 *                               legacy CashTransferController until it is
 *                               retired).
 *  - finance_float_issue        issuing / topping up a cashier float.
 *  - finance_float_settlement   closing a float and posting its variance.
 *  - finance_reversal           reversing a completed finance transaction.
 *
 * required_approvals here is the fallback the ApprovalRouter uses when no
 * approval_tiers row matches. Approvers are configured afterwards on the
 * Approval Types admin screen; with none set, each is admin-only (see
 * ApprovalType::isAuthorized()).
 */
return new class extends Migration
{
    private const TYPES = [
        ['finance_request_payment', 'Finance Request Payment', 'Approving an outbound payment raised as a finance request.', 1],
        ['finance_transfer', 'Finance Transfer', 'Approving a cash or bank transfer executed through the Finance module.', 1],
        ['finance_float_issue', 'Cash Float Issue', 'Approving the issue or top-up of a cashier float.', 1],
        ['finance_float_settlement', 'Cash Float Settlement', 'Approving a float settlement and its shortage / excess posting.', 1],
        ['finance_reversal', 'Finance Transaction Reversal', 'Approving the reversal of a completed finance transaction.', 2],
    ];

    public function up(): void
    {
        $now = now();
        foreach (self::TYPES as [$code, $name, $description, $required]) {
            if (DB::table('approval_types')->where('code', $code)->exists()) {
                continue;
            }
            DB::table('approval_types')->insert([
                'code' => $code, 'name' => $name, 'description' => $description,
                'is_system' => true, 'required_approvals' => $required,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        $codes = array_column(self::TYPES, 0);
        $typeIds = DB::table('approval_types')->whereIn('code', $codes)->pluck('id');
        if ($typeIds->isEmpty()) {
            return;
        }
        DB::table('approval_tiers')->whereIn('approval_type_id', $typeIds)->delete();
        DB::table('approval_requests')->whereIn('approval_type_id', $typeIds)->delete();
        DB::table('approval_types')->whereIn('id', $typeIds)->delete();
    }
};
