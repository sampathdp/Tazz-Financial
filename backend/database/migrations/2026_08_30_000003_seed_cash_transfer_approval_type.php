<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Registers the approval point a cash transfer between tills needs — see
 * CashTransferController::approve()/reject(). Was a bespoke perm-gated
 * single-approver path (perm:Cash Transfers,Approve); this brings it onto the
 * shared approval_requests maker-checker engine, same as loan_cancel.
 *
 * Backfills a PendingApproval approval_requests row for every transfer already
 * sitting at status='Pending'.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $typeId = DB::table('approval_types')->insertGetId([
            'code' => 'cash_transfer',
            'name' => 'Cash Transfer',
            'description' => 'Approving a cash transfer between two cash accounts / tills.',
            'is_system' => true,
            'required_approvals' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $pending = DB::table('cash_transfers')
            ->where('status', 'Pending')
            ->get(['id', 'requested_by', 'description', 'created_at']);
        foreach ($pending as $transfer) {
            DB::table('approval_requests')->insert([
                'approval_type_id' => $typeId,
                'subject_id' => $transfer->id,
                'requested_by' => $transfer->requested_by,
                'requested_at' => $transfer->created_at ?? $now,
                'reason' => $transfer->description,
                'required_approvals' => 1,
                'status' => 'PendingApproval',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        $typeId = DB::table('approval_types')->where('code', 'cash_transfer')->value('id');
        if ($typeId) {
            DB::table('approval_requests')->where('approval_type_id', $typeId)->delete();
            DB::table('approval_types')->where('id', $typeId)->delete();
        }
    }
};
