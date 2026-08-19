<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Registers the approval point a pending manual-allocation payment request
 * needs — see LoanPaymentController::approveAllocationRequest(). Seeded the
 * same way loan_cancel/payment_void were (see create_approval_types_table),
 * is_system so it can't be deleted; the required_approvals count and approver
 * pool are configurable afterward via the ApprovalTypes admin screen.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('approval_types')->insert([
            'code' => 'payment_manual_allocation',
            'name' => 'Payment Manual Allocation',
            'description' => 'Approving a cashier-requested manual interest/capital/OD/other-charges split for a loan payment.',
            'is_system' => true,
            'required_approvals' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('approval_types')->where('code', 'payment_manual_allocation')->delete();
    }
};
