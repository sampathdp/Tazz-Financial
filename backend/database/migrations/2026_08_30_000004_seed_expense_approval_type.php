<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Registers the approval point an expense needs before it can be paid — see
 * ExpenseController::approve()/reject(). Was a bespoke perm-gated
 * single-approver path (perm:Expenses,Approve); this brings it onto the shared
 * approval_requests maker-checker engine, same as loan_cancel. The separate
 * pay() step (ExpenseController::pay) is unchanged.
 *
 * Backfills a PendingApproval approval_requests row for every expense already
 * sitting at status='Pending'.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $typeId = DB::table('approval_types')->insertGetId([
            'code' => 'expense_approval',
            'name' => 'Expense Approval',
            'description' => 'Approving a pending expense before it can be paid.',
            'is_system' => true,
            'required_approvals' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $pending = DB::table('expenses')
            ->where('status', 'Pending')
            ->get(['id', 'requested_by', 'description', 'created_at']);
        foreach ($pending as $expense) {
            DB::table('approval_requests')->insert([
                'approval_type_id' => $typeId,
                'subject_id' => $expense->id,
                'requested_by' => $expense->requested_by,
                'requested_at' => $expense->created_at ?? $now,
                'reason' => $expense->description,
                'required_approvals' => 1,
                'status' => 'PendingApproval',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        $typeId = DB::table('approval_types')->where('code', 'expense_approval')->value('id');
        if ($typeId) {
            DB::table('approval_requests')->where('approval_type_id', $typeId)->delete();
            DB::table('approval_types')->where('id', $typeId)->delete();
        }
    }
};
