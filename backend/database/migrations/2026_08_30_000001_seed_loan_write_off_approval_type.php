<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Registers the approval point a bad-debt write-off now needs — see
 * LoanController::writeOffRequest()/approveWriteOffRequest(). Write-off used
 * to be a direct admin-only action; it is now maker-checker through the shared
 * approval_requests table, exactly like loan_cancel (see
 * create_approval_types_table and seed_application_approval_types). is_system
 * so it can't be deleted; the required_approvals count and approver pool are
 * configurable afterward via the Approval Types admin screen.
 *
 * No backfill: any loan already at status='WrittenOff' was written off under
 * the old direct flow and needs nothing here.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('approval_types')->insert([
            'code' => 'loan_write_off',
            'name' => 'Loan Write-off',
            'description' => 'Approving a request to write off an active loan as bad debt.',
            'is_system' => true,
            'required_approvals' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        $typeId = DB::table('approval_types')->where('code', 'loan_write_off')->value('id');
        if ($typeId) {
            DB::table('approval_requests')->where('approval_type_id', $typeId)->delete();
            DB::table('approval_types')->where('id', $typeId)->delete();
        }
    }
};
