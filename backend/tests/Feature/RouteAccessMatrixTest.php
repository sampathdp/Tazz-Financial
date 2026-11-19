<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckPermission;
use App\Http\Middleware\EnsureAdmin;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Auth/permission matrix generated from the live route table (security audit Phase 2, item 1).
 *  - every API route outside the public allowlist rejects an anonymous caller (401);
 *  - every `perm:Module,Action` route refuses a user holding every permission EXCEPT that one (403)
 *    and admits a user holding only that one;
 *  - every `admin` route refuses a non-admin (403);
 *  - the set of routes gated by nothing but login is pinned, so a new ungated route fails here until reviewed.
 */
class RouteAccessMatrixTest extends TestCase
{
    use DatabaseTransactions;

    private const PUBLIC = [
        'POST api/login', 'GET api/ping', 'GET api/company-profile/branding', 'GET api/company-profile/logo',
    ];

    /** Routes open to every signed-in user (no perm/admin gate). Review before adding. */
    private const LOGIN_ONLY = [
        // Own-profile and picklist endpoints, plus approve/reject actions that authorise inside the controller
        // (approver eligibility, no self-approval) instead of by a static permission.
        'DELETE api/profile/achievements/{achievement}',
        'DELETE api/profile/photo',
        'GET api/customers/{customer}/bank-details',
        'GET api/me',
        'GET api/profile/achievements',
        'GET api/profile/photo',
        'GET api/profile/staff',
        'GET api/staff/options',
        'GET api/users/active',
        'GET api/users/marketing-executives',
        'PUT api/customers/{customer}/bank-details/{bankDetail}',
        'PUT api/profile/staff',
        'PUT api/loans/{loan}/payout-account',
        'POST api/customers/{customer}/bank-details',
        'POST api/finance-requests/{financeRequest}/approve',
        'POST api/finance-requests/{financeRequest}/reject',
        'POST api/finance-transaction-reverse-requests/{approvalRequest}/approve',
        'POST api/finance-transaction-reverse-requests/{approvalRequest}/reject',
        'POST api/loan-cancel-requests/{cancelRequest}/approve',
        'POST api/loan-cancel-requests/{cancelRequest}/reject',
        'POST api/loan-payment-allocation-requests/{allocationRequest}/approve',
        'POST api/loan-payment-allocation-requests/{allocationRequest}/reject',
        'POST api/loan-payment-void-requests/{voidRequest}/approve',
        'POST api/loan-payment-void-requests/{voidRequest}/reject',
        'POST api/loan-payments/{payment}/approve-settlement',
        'POST api/loan-payments/{payment}/reject-settlement',
        'POST api/loan-write-off-requests/{writeOffRequest}/approve',
        'POST api/loan-write-off-requests/{writeOffRequest}/reject',
        'POST api/loans/{loan}/approve',
        'POST api/loans/{loan}/reject',
        'POST api/logout',
        'POST api/profile/achievements',
        'POST api/profile/photo',
        'POST api/speed-drafts/{speedDraft}/approve',
        'POST api/speed-drafts/{speedDraft}/reject',
        'PUT api/expenses/{expense}/approve',
        'PUT api/expenses/{expense}/reject',
        'PUT api/manual-journal-entries/{manualJournalEntry}/approve',
        'PUT api/manual-journal-entries/{manualJournalEntry}/reject',
        'PUT api/profile/achievements/{achievement}',
    ];

    /** @return array<int, array{key:string, route:Route, mw:array}> */
    private function apiRoutes(): array
    {
        $router = app('router');
        $out = [];
        foreach ($router->getRoutes() as $route) {
            if (!str_starts_with($route->uri(), 'api/')) {
                continue;
            }
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $out[] = ['key' => "{$method} {$route->uri()}", 'route' => $route, 'mw' => $router->gatherRouteMiddleware($route)];
            }
        }

        return $out;
    }

    private function permissionIds(): array
    {
        $map = [];
        foreach (DB::table('permissions')->get(['id', 'module', 'action']) as $p) {
            $map[$p->module][$p->action] = $p->id;
        }

        return $map;
    }

    private function gate(array $mw): ?array
    {
        foreach ($mw as $m) {
            if (!is_string($m)) {
                continue;
            }
            if (str_starts_with($m, CheckPermission::class . ':')) {
                [$module, $action] = explode(',', substr($m, strlen(CheckPermission::class) + 1), 2);

                return ['perm', $module, $action];
            }
            if ($m === EnsureAdmin::class) {
                return ['admin'];
            }
        }

        return null;
    }

    private function user(array $perms, bool $admin = false): User
    {
        $branchId = DB::table('branches')->value('id') ?? DB::table('branches')->insertGetId([
            'name' => 'Mx ' . Str::random(5), 'code' => 'M' . Str::random(4), 'branch_type' => 'Branch', 'status' => 'Active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $roleId = $admin
            ? (DB::table('roles')->where('slug', 'admin')->where('is_system', 1)->value('id') ?? DB::table('roles')->insertGetId([
                'name' => 'Administrator', 'slug' => 'admin', 'is_system' => 1, 'can_view_all_branches' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]))
            : DB::table('roles')->insertGetId([
                'name' => 'Mx' . Str::random(4), 'slug' => 'mx_' . Str::random(6), 'is_system' => 0, 'can_view_all_branches' => 0,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        if (!$admin) {
            foreach ($perms as [$module, $action]) {
                DB::table('role_permissions')->insert([
                    'role_id' => $roleId, 'permission_id' => $this->permissionIds()[$module][$action], 'created_at' => now(),
                ]);
            }
        }

        return User::forceCreate([
            'name' => 'Mx', 'email' => 'mx' . Str::random(8) . '@example.test', 'password' => Hash::make('x'),
            'role_id' => $roleId, 'branch_id' => $branchId, 'status' => 'Active',
        ])->fresh();
    }

    private function runGate(string $mwString, ?User $user): int
    {
        $request = Request::create('/api/x', 'GET');
        $request->setUserResolver(fn () => $user);
        $parts = explode(':', $mwString, 2);
        $args = isset($parts[1]) ? explode(',', $parts[1], 2) : [];
        $resp = app($parts[0])->handle($request, fn () => response('ok', 200), ...$args);

        return $resp->getStatusCode();
    }

    public function test_anonymous_requests_are_rejected_on_every_non_public_route(): void
    {
        $bad = [];
        foreach ($this->apiRoutes() as $r) {
            if (in_array($r['key'], self::PUBLIC, true)) {
                continue;
            }
            [$method, $uri] = explode(' ', $r['key'], 2);
            $uri = preg_replace('/\{[^}]+\??\}/', '1', $uri);
            $status = $this->json($method, '/' . $uri)->getStatusCode();
            if ($status !== 401) {
                $bad[] = "{$r['key']} -> {$status}";
            }
        }
        $this->assertSame([], $bad, "Routes reachable without a token:\n" . implode("\n", $bad));
    }

    public function test_every_permission_gated_route_refuses_a_user_missing_exactly_that_permission(): void
    {
        $map = $this->permissionIds();
        $all = [];
        foreach ($map as $module => $actions) {
            foreach ($actions as $action => $_) {
                $all[] = [$module, $action];
            }
        }
        $users = [];
        $bad = [];
        $checked = 0;
        foreach ($this->apiRoutes() as $r) {
            $g = $this->gate($r['mw']);
            if (!$g || $g[0] !== 'perm') {
                continue;
            }
            [, $module, $action] = $g;
            if (!isset($map[$module][$action])) {
                $bad[] = "{$r['key']} gates on unknown permission {$module},{$action}";
                continue;
            }
            $mwString = collect($r['mw'])->first(fn ($m) => is_string($m) && str_starts_with($m, CheckPermission::class . ':'));
            $users["without:{$module},{$action}"] ??= $this->user(array_values(array_filter($all, fn ($p) => $p !== [$module, $action])));
            $users["only:{$module},{$action}"] ??= $this->user([[$module, $action]]);
            if ($this->runGate($mwString, $users["without:{$module},{$action}"]) !== 403) {
                $bad[] = "{$r['key']} admitted a user without {$module},{$action}";
            }
            if ($this->runGate($mwString, $users["only:{$module},{$action}"]) !== 200) {
                $bad[] = "{$r['key']} refused a user holding {$module},{$action}";
            }
            $checked++;
        }
        $this->assertGreaterThan(300, $checked);
        $this->assertSame([], $bad, implode("\n", $bad));
    }

    public function test_admin_routes_refuse_non_admins_and_admit_admins(): void
    {
        $plain = $this->user([]);
        $admin = $this->user([], true);
        $bad = [];
        $checked = 0;
        foreach ($this->apiRoutes() as $r) {
            $g = $this->gate($r['mw']);
            if (!$g || $g[0] !== 'admin') {
                continue;
            }
            if ($this->runGate(EnsureAdmin::class, $plain) !== 403) {
                $bad[] = "{$r['key']} admitted a non-admin";
            }
            if ($this->runGate(EnsureAdmin::class, $admin) !== 200) {
                $bad[] = "{$r['key']} refused an admin";
            }
            $checked++;
        }
        $this->assertGreaterThan(0, $checked);
        $this->assertSame([], $bad, implode("\n", $bad));
    }

    public function test_routes_gated_only_by_login_are_the_reviewed_set(): void
    {
        $open = [];
        foreach ($this->apiRoutes() as $r) {
            if (in_array($r['key'], self::PUBLIC, true) || $this->gate($r['mw'])) {
                continue;
            }
            $open[] = $r['key'];
        }
        sort($open);
        $expected = self::LOGIN_ONLY;
        sort($expected);
        $this->assertSame($expected, $open, "Login-only route set changed. Review, then update LOGIN_ONLY.\n" . var_export($open, true));
    }
}
