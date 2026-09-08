<?php

use App\Models\Role;
use App\Support\Permissions;
use Illuminate\Database\Migrations\Migration;

/**
 * Daily Savings — grants the five new permission modules (added to
 * Permissions::MODULES) to the company-wide finance roles so the module is
 * usable out of the box. The system admin role already has every permission
 * implicitly; any other role picks these up through Role Management.
 *
 * Non-destructive: merges onto each role's existing grid, same pattern as
 * 2026_09_01_000015_grant_finance_phase8_permissions.
 */
return new class extends Migration
{
    public function up(): void
    {
        $grants = [
            'finance_officer' => [
                'Savings Products' => ['View' => true],
                'Savings Accounts' => ['View' => true, 'Create' => true, 'Edit' => true],
                'Savings Collection' => ['View' => true, 'Create' => true, 'Edit' => true],
                'Savings Interest' => ['View' => true],
                'Savings Reports' => ['View' => true],
            ],
            'finance_manager' => [
                'Savings Products' => ['View' => true, 'Create' => true, 'Edit' => true, 'Delete' => true],
                'Savings Accounts' => ['View' => true, 'Create' => true, 'Edit' => true, 'Delete' => true, 'Approve' => true],
                'Savings Collection' => ['View' => true, 'Create' => true, 'Edit' => true, 'Delete' => true],
                'Savings Interest' => ['View' => true, 'Create' => true, 'Edit' => true, 'Approve' => true],
                'Savings Reports' => ['View' => true, 'Export' => true],
            ],
            'finance_admin' => [
                'Savings Products' => ['View' => true, 'Create' => true, 'Edit' => true, 'Delete' => true, 'Export' => true],
                'Savings Accounts' => ['View' => true, 'Create' => true, 'Edit' => true, 'Delete' => true, 'Approve' => true, 'Export' => true],
                'Savings Collection' => ['View' => true, 'Create' => true, 'Edit' => true, 'Delete' => true, 'Export' => true],
                'Savings Interest' => ['View' => true, 'Create' => true, 'Edit' => true, 'Delete' => true, 'Approve' => true, 'Export' => true],
                'Savings Reports' => ['View' => true, 'Export' => true],
            ],
        ];

        foreach ($grants as $slug => $modules) {
            $role = Role::where('slug', $slug)->first();
            if (!$role) {
                continue;
            }
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
