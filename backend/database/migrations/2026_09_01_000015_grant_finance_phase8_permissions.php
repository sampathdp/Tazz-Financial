<?php

use App\Models\Role;
use App\Support\Permissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Finance module — Phase 8. Grants the transaction-ledger and audit-log
 * screens to the Finance roles (the Phase 0 grids pre-dated them), and
 * settles finance_reversal at one approval — same as every other finance
 * code; it is already maker-checker (requester != approver).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('approval_types')->where('code', 'finance_reversal')->update(['required_approvals' => 1]);

        $grants = [
            'finance_officer' => [
                'Cash Transactions' => ['View' => true],
                'Finance Audit' => ['View' => true],
            ],
            'finance_manager' => [
                'Cash Transactions' => ['View' => true, 'Delete' => true],
                'Finance Audit' => ['View' => true],
            ],
            'finance_admin' => [
                'Cash Transactions' => ['View' => true, 'Create' => true, 'Edit' => true, 'Delete' => true, 'Approve' => true, 'Export' => true],
                'Finance Audit' => ['View' => true, 'Export' => true],
            ],
        ];

        foreach ($grants as $slug => $modules) {
            $role = Role::where('slug', $slug)->first();
            if (!$role) {
                continue;
            }
            // Merge onto the role's existing grid rather than replacing it.
            $grid = Permissions::gridForRole($role);
            foreach ($modules as $module => $actions) {
                foreach ($actions as $action => $on) {
                    $grid[$module][$action] = $on;
                }
            }
            Permissions::saveForRole($role, $grid);
        }
    }

    public function down(): void
    {
        // Non-destructive; leave the grants in place.
    }
};
