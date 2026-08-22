<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Registers the approval points a Pending Loan Application / Speed Draft need
 * to be approved or rejected — see LoanController::approve()/reject() and
 * SpeedDraftController::approve()/reject(). Seeded the same way loan_cancel/
 * payment_void/payment_manual_allocation were (see create_approval_types_table
 * and seed_payment_manual_allocation_approval_type), is_system so they can't
 * be deleted; the required_approvals count and approver pool are configurable
 * afterward via the ApprovalTypes admin screen. This replaces the old
 * "Loan Applications/Speed Drafts → Approve" role-permission-grid gate for
 * these two actions — authorization now flows entirely through
 * ApprovalType::isAuthorized() (admins always, plus whoever's added as an
 * approver here).
 *
 * Backfills a matching PendingApproval approval_requests row for every
 * credit_facilities row already sitting at status='Pending' — those were
 * created before approve()/reject() started requiring one, and would
 * otherwise 422 with "No pending approval request found" forever.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $types = [];
        foreach ([
            ['code' => 'loan_application', 'name' => 'Loan Application', 'description' => 'Approving a new Pending loan application.', 'facility_type' => 'Loan'],
            ['code' => 'speed_draft_application', 'name' => 'Speed Draft Application', 'description' => 'Approving a new Pending Speed Draft.', 'facility_type' => 'SpeedDraft'],
        ] as $row) {
            $facilityType = $row['facility_type'];
            unset($row['facility_type']);
            $typeId = DB::table('approval_types')->insertGetId(array_merge($row, [
                'is_system' => true,
                'required_approvals' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]));
            $types[$facilityType] = $typeId;

            $pending = DB::table('credit_facilities')
                ->where('facility_type', $facilityType)->where('status', 'Pending')->whereNull('deleted_at')
                ->get(['id', 'applied_by', 'created_by', 'created_at']);
            foreach ($pending as $facility) {
                DB::table('approval_requests')->insert([
                    'approval_type_id' => $typeId,
                    'subject_id' => $facility->id,
                    'requested_by' => $facility->applied_by ?? $facility->created_by,
                    'requested_at' => $facility->created_at ?? $now,
                    'required_approvals' => 1,
                    'status' => 'PendingApproval',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        $typeIds = DB::table('approval_types')->whereIn('code', ['loan_application', 'speed_draft_application'])->pluck('id');
        DB::table('approval_requests')->whereIn('approval_type_id', $typeIds)->delete();
        DB::table('approval_types')->whereIn('id', $typeIds)->delete();
    }
};
