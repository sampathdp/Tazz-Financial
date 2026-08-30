<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Registers the approval point a full-settlement loan payment needs — see
 * LoanPaymentController::approveSettlement()/rejectSettlement(). Settlement
 * payments were already held at approval_status='PendingApproval' but were
 * approved through a bespoke perm-gated single-approver path, unlike their
 * sibling ManualAllocation payments which already go through the shared
 * approval_requests table (see seed_payment_manual_allocation_approval_type).
 * This brings settlement onto the same maker-checker engine as loan_cancel.
 *
 * Backfills a PendingApproval approval_requests row for every settlement
 * payment already sitting at approval_status='PendingApproval' so the approve
 * endpoint can resolve one.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $typeId = DB::table('approval_types')->insertGetId([
            'code' => 'payment_settlement',
            'name' => 'Loan Payment Settlement',
            'description' => 'Approving a full-settlement loan payment before it is applied to the schedule.',
            'is_system' => true,
            'required_approvals' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $pending = DB::table('facility_payments')
            ->where('payment_type', 'Settlement')
            ->where('approval_status', 'PendingApproval')
            ->get(['id', 'received_by', 'notes', 'created_at']);
        foreach ($pending as $payment) {
            DB::table('approval_requests')->insert([
                'approval_type_id' => $typeId,
                'subject_id' => $payment->id,
                'requested_by' => $payment->received_by,
                'requested_at' => $payment->created_at ?? $now,
                'reason' => $payment->notes,
                'required_approvals' => 1,
                'status' => 'PendingApproval',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        $typeId = DB::table('approval_types')->where('code', 'payment_settlement')->value('id');
        if ($typeId) {
            DB::table('approval_requests')->where('approval_type_id', $typeId)->delete();
            DB::table('approval_types')->where('id', $typeId)->delete();
        }
    }
};
