<?php

namespace App\Support;

use App\Models\Permission;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Models\UserPermissionOverride;
use Illuminate\Support\Facades\Cache;

class Permissions
{
    /**
     * Canonical list of pages/screens the system exposes.
     * Add new entries here when new modules ship (Loan, Pawning, Cashbook, ...).
     */
    public const MODULES = [
        'Dashboard',
        'Branch Management', 'Role Management', 'User Management', 'User Permission',
        'Company Profile',
        'Customer Management',
        'Loan Types',
        'Loan Calculator',
        'Loan Applications',
        'Active Loans',
        'Loan Payments',
        'Loan Provisioning',
        'Sales Executive Commissions',
        'Overdue Loans',
        'Loan Reports',
        'Collection Reports',
        'Customer Reports',
        'Investor Reports',
        'Cash & Bank Reports',
        'Expense Reports',
        'Document Tracking',
        'Speed Draft Types',
        'Speed Drafts',
        'Speed Draft Payments',
        'Pawning Types',
        'Pawnings',
        'Pawning Payments',
        'Pawning Auctions',
        'Cashbook',
        'Cash Transfers',
        'Cash Reconciliation',
        'Bank Accounts',
        'Bank Transactions',
        'Bank Reconciliation',
        'Masters',
        'Funding Products',
        'Deposits',
        'Fixed Deposits',
        'Borrowings',
        'Funding Reports',
        'Chart of Accounts',
        'Accounting Reports',
        'Manual Journal Entries',
        'Vendors',
        'Expenses',
        'Payment Vouchers',
        'Petty Cash',
        'Subscriptions',
    ];

    public const ACTIONS = ['View', 'Create', 'Edit', 'Delete', 'Approve', 'Export'];

    /** Cache TTL (seconds) for a user's resolved permission list. */
    private const CACHE_TTL = 300;

    /** Empty {module: {action: false}} grid. */
    private static function emptyGrid(): array
    {
        $grid = [];
        foreach (self::MODULES as $m) {
            foreach (self::ACTIONS as $a) {
                $grid[$m][$a] = false;
            }
        }

        return $grid;
    }

    /**
     * {module: {action: permission_id}} for every cell in the grid, creating
     * any (module, action) rows that don't exist in `permissions` yet — e.g.
     * the first time a newly shipped module is granted to a role. One or two
     * queries regardless of grid size, not one firstOrCreate() per cell.
     */
    private static function permissionIdMap(): array
    {
        $mapFrom = fn ($rows) => $rows->reduce(function (array $map, Permission $p) {
            $map[$p->module][$p->action] = $p->id;

            return $map;
        }, []);

        $map = $mapFrom(Permission::all());

        $missing = [];
        foreach (self::MODULES as $m) {
            foreach (self::ACTIONS as $a) {
                if (!isset($map[$m][$a])) {
                    $missing[] = ['module' => $m, 'action' => $a, 'created_at' => now()];
                }
            }
        }
        if ($missing) {
            Permission::insert($missing);
            $map = $mapFrom(Permission::all());
        }

        return $map;
    }

    /** {module: {action: bool}} grid built from a role's stored role_permissions rows. */
    public static function gridForRole(Role $role): array
    {
        $grid = self::emptyGrid();
        if ($role->isAdmin()) {
            foreach (self::MODULES as $m) {
                foreach (self::ACTIONS as $a) {
                    $grid[$m][$a] = true;
                }
            }

            return $grid;
        }
        foreach (RolePermission::where('role_id', $role->id)->with('permission')->get() as $p) {
            $module = $p->permission->module;
            $action = $p->permission->action;
            if (isset($grid[$module][$action])) {
                $grid[$module][$action] = true;
            }
        }

        return $grid;
    }

    /**
     * Replace all permissions for a role with the given matrix slice:
     *   {module: {action: bool}}
     * No-op for the system admin role (its access is implicit and not stored).
     */
    public static function saveForRole(Role $role, array $modules): void
    {
        if ($role->isAdmin()) {
            return;
        }
        RolePermission::where('role_id', $role->id)->delete();
        $permissionIds = self::permissionIdMap();
        $rows = [];
        foreach ($modules as $module => $actions) {
            if (!in_array($module, self::MODULES, true)) {
                continue;
            }
            foreach ($actions as $action => $enabled) {
                if (!in_array($action, self::ACTIONS, true)) {
                    continue;
                }
                if ($enabled) {
                    $rows[] = [
                        'role_id' => $role->id, 'permission_id' => $permissionIds[$module][$action],
                        'created_at' => now(),
                    ];
                }
            }
        }
        if ($rows) {
            RolePermission::insert($rows);
        }
        // Role changes ripple to every member — bust each of their cached grids.
        foreach (User::where('role_id', $role->id)->pluck('id') as $userId) {
            Cache::forget("perm:user:{$userId}");
        }
    }

    /**
     * Effective {module: {action: bool}} matrix for a single user: the
     * user's role grid with per-user overrides (user_permissions) applied on
     * top. Admin users get every permission.
     */
    public static function gridForUser(User $user): array
    {
        if ($user->isAdmin()) {
            $grid = self::emptyGrid();
            foreach (self::MODULES as $m) {
                foreach (self::ACTIONS as $a) {
                    $grid[$m][$a] = true;
                }
            }

            return $grid;
        }

        $grid = $user->role ? self::gridForRole($user->role) : self::emptyGrid();

        foreach (UserPermissionOverride::where('user_id', $user->id)->with('permission')->get() as $p) {
            $module = $p->permission->module;
            $action = $p->permission->action;
            if (isset($grid[$module][$action])) {
                $grid[$module][$action] = (bool) $p->allowed;
            }
        }

        return $grid;
    }

    /**
     * Which cells in a user's effective grid are explicit overrides (as
     * opposed to inherited from their role) — {module: {action: bool}}.
     * Used by the UI to visually distinguish overridden cells.
     */
    public static function overridesForUser(User $user): array
    {
        $overrides = [];
        if ($user->isAdmin()) {
            return $overrides;
        }
        foreach (UserPermissionOverride::where('user_id', $user->id)->with('permission')->get() as $p) {
            $overrides[$p->permission->module][$p->permission->action] = true;
        }

        return $overrides;
    }

    /**
     * Replace a user's permission overrides with the given slice:
     *   {module: {action: true|false|null}}
     * true/false upserts an explicit override, null/missing clears it back
     * to "inherit from role". No-op for admin users.
     */
    public static function saveForUser(User $user, array $modules): void
    {
        if ($user->isAdmin()) {
            return;
        }
        $permissionIds = self::permissionIdMap();
        foreach ($modules as $module => $actions) {
            if (!in_array($module, self::MODULES, true)) {
                continue;
            }
            foreach ($actions as $action => $value) {
                if (!in_array($action, self::ACTIONS, true)) {
                    continue;
                }
                $permissionId = $permissionIds[$module][$action];
                if ($value === null) {
                    UserPermissionOverride::where('user_id', $user->id)
                        ->where('permission_id', $permissionId)->delete();
                } else {
                    UserPermissionOverride::updateOrCreate(
                        ['user_id' => $user->id, 'permission_id' => $permissionId],
                        ['allowed' => (bool) $value]
                    );
                }
            }
        }
        Cache::forget("perm:user:{$user->id}");
    }

    /**
     * Flat list of "Module:Action" strings the user has access to.
     */
    public static function forUser(?User $user): array
    {
        if (!$user) {
            return [];
        }

        return Cache::remember("perm:user:{$user->id}", self::CACHE_TTL, function () use ($user) {
            $flat = [];
            foreach (self::gridForUser($user) as $module => $actions) {
                foreach ($actions as $action => $enabled) {
                    if ($enabled) {
                        $flat[] = "{$module}:{$action}";
                    }
                }
            }

            return $flat;
        });
    }

    public static function userCan(?User $user, string $module, string $action): bool
    {
        if (!$user) {
            return false;
        }

        return in_array("{$module}:{$action}", self::forUser($user), true);
    }
}
