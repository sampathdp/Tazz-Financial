<?php

use App\Models\Role;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Finance module — Phase 0. Seeds the three Finance roles the module's
 * roles/permissions matrix is built around. Additive and idempotent
 * (firstOrCreate by slug) so it is safe on an already-seeded production
 * install as well as a fresh one; the permission grids are (re)applied on
 * every run via Permissions::saveForRole, which also auto-creates any
 * (module, action) rows the new Finance modules need.
 *
 *  - finance_officer   branch-scoped. Raises and executes routine finance
 *                      requests, runs cash sessions, submits reconciliation.
 *  - finance_manager   all branches. Adds approval authority, transfers,
 *                      float issue/settlement, variance sign-off.
 *  - finance_admin     all branches. Full finance control incl. settings
 *                      (approval tiers, thresholds) plus read access to the
 *                      accounting side it needs to see.
 *
 * These are starter grids — everything is adjustable afterward in Role
 * Management, exactly like the seedExampleRoles() roles in DatabaseSeeder.
 */
return new class extends Migration
{
    private const ALL = ['View', 'Create', 'Edit', 'Delete', 'Approve', 'Export'];

    public function up(): void
    {
        $officer = Role::firstOrCreate(['slug' => 'finance_officer'], [
            'name' => 'Finance Officer',
            'description' => 'Raises and executes routine finance requests and runs daily cash sessions for a single branch.',
            'is_system' => false,
            'can_view_all_branches' => false,
        ]);
        Permissions::saveForRole($officer, [
            'Dashboard' => ['View' => true],
            'Finance Dashboard' => ['View' => true],
            'Finance Requests' => ['View' => true, 'Create' => true, 'Edit' => true],
            'Cash Accounts' => ['View' => true],
            'Cash Transactions' => ['View' => true],
            'Finance Transfers' => ['View' => true, 'Create' => true],
            'Cash Floats' => ['View' => true],
            'Cash Sessions' => ['View' => true, 'Create' => true, 'Edit' => true],
            'Cash Reconciliation' => ['View' => true, 'Create' => true],
            'Finance Reports' => ['View' => true],
            'Customer Management' => ['View' => true],
            'Loan Applications' => ['View' => true],
            'Expenses' => ['View' => true],
        ]);

        $manager = Role::firstOrCreate(['slug' => 'finance_manager'], [
            'name' => 'Finance Manager',
            'description' => 'Company-wide finance authority: approves requests and transfers, issues and settles floats, signs off cash variances.',
            'is_system' => false,
            'can_view_all_branches' => true,
        ]);
        Permissions::saveForRole($manager, [
            'Dashboard' => ['View' => true],
            'Finance Dashboard' => ['View' => true],
            'Finance Requests' => ['View' => true, 'Create' => true, 'Edit' => true, 'Approve' => true],
            'Cash Accounts' => ['View' => true, 'Create' => true, 'Edit' => true],
            'Cash Transactions' => ['View' => true],
            'Finance Transfers' => ['View' => true, 'Create' => true, 'Edit' => true, 'Approve' => true],
            'Cash Floats' => ['View' => true, 'Create' => true, 'Edit' => true],
            'Cash Sessions' => ['View' => true, 'Create' => true, 'Edit' => true],
            'Cash Reconciliation' => ['View' => true, 'Create' => true, 'Edit' => true],
            'Finance Reports' => ['View' => true, 'Export' => true],
            'Customer Management' => ['View' => true],
            'Loan Applications' => ['View' => true],
            'Expenses' => ['View' => true],
            'Bank Accounts' => ['View' => true],
            'Cashbook' => ['View' => true],
        ]);

        $admin = Role::firstOrCreate(['slug' => 'finance_admin'], [
            'name' => 'Finance Administrator',
            'description' => 'Full Finance module control including approval-tier and threshold settings, plus read access to the accounting side.',
            'is_system' => false,
            'can_view_all_branches' => true,
        ]);
        $allFinance = fn () => array_fill_keys(self::ALL, true);
        Permissions::saveForRole($admin, [
            'Dashboard' => ['View' => true],
            'Finance Dashboard' => $allFinance(),
            'Finance Requests' => $allFinance(),
            'Cash Accounts' => $allFinance(),
            'Cash Transactions' => $allFinance(),
            'Finance Transfers' => $allFinance(),
            'Cash Floats' => $allFinance(),
            'Cash Sessions' => $allFinance(),
            'Cash Reconciliation' => $allFinance(),
            'Finance Reports' => $allFinance(),
            'Finance Settings' => ['View' => true, 'Edit' => true],
            'Chart of Accounts' => ['View' => true],
            'Manual Journal Entries' => ['View' => true, 'Create' => true],
            'Accounting Reports' => ['View' => true],
            'Cash & Bank Reports' => ['View' => true],
            'Customer Management' => ['View' => true],
        ]);
    }

    public function down(): void
    {
        $slugs = ['finance_officer', 'finance_manager', 'finance_admin'];
        $roleIds = Role::whereIn('slug', $slugs)->pluck('id');
        if ($roleIds->isEmpty()) {
            return;
        }
        User::whereIn('role_id', $roleIds)->update(['role_id' => null]);
        DB::table('role_permissions')->whereIn('role_id', $roleIds)->delete();
        Role::whereIn('id', $roleIds)->delete();
    }
};
