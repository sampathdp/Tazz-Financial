<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A non-admin who holds Role Management,Edit / User Permission,Edit must not be able to
 * widen access beyond what they hold themselves (privilege escalation), and must not be
 * able to edit the admin role or admin users. Security audit Phase 2, item 2.
 */
class PermissionEscalationTest extends TestCase
{
    use DatabaseTransactions;

    private int $branchId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->branchId = DB::table('branches')->insertGetId([
            'name' => 'Esc ' . Str::random(6), 'code' => 'E' . Str::random(4), 'branch_type' => 'Branch', 'status' => 'Active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeRole(array $perms): int
    {
        $roleId = DB::table('roles')->insertGetId([
            'name' => 'EscRole' . Str::random(4), 'slug' => 'esc_' . Str::random(6), 'is_system' => 0, 'can_view_all_branches' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ($perms as [$module, $action]) {
            $pid = DB::table('permissions')->where('module', $module)->where('action', $action)->value('id');
            $this->assertNotNull($pid, "permission {$module},{$action} missing");
            DB::table('role_permissions')->insert(['role_id' => $roleId, 'permission_id' => $pid, 'created_at' => now()]);
        }

        return $roleId;
    }

    private function adminRoleId(): int
    {
        return (int) (DB::table('roles')->where('slug', 'admin')->where('is_system', 1)->value('id')
            ?? DB::table('roles')->insertGetId([
                'name' => 'Administrator', 'slug' => 'admin', 'is_system' => 1, 'can_view_all_branches' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]));
    }

    private function makeUser(int $roleId): User
    {
        return User::forceCreate([
            'name' => 'Esc User', 'email' => 'esc' . Str::random(6) . '@example.test', 'password' => Hash::make('x'),
            'role_id' => $roleId, 'branch_id' => $this->branchId, 'status' => 'Active',
        ]);
    }

    private function hasPerm(int $roleId, string $module, string $action): bool
    {
        return DB::table('role_permissions')->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->where('role_id', $roleId)->where('module', $module)->where('action', $action)->exists();
    }

    public function test_role_editor_cannot_grant_permission_they_do_not_hold(): void
    {
        $roleId = $this->makeRole([['Role Management', 'Edit']]);
        $this->actingAs($this->makeUser($roleId)->fresh(), 'sanctum');

        $this->putJson("/api/roles/{$roleId}/permissions", ['modules' => [
            'Role Management' => ['Edit' => true],
            'User Management' => ['Delete' => true],
        ]])->assertStatus(403);

        $this->assertFalse($this->hasPerm($roleId, 'User Management', 'Delete'));
    }

    public function test_role_editor_can_grant_permission_they_hold_to_another_role(): void
    {
        $editorRole = $this->makeRole([['Role Management', 'Edit'], ['User Management', 'View']]);
        $targetRole = $this->makeRole([]);
        $this->actingAs($this->makeUser($editorRole)->fresh(), 'sanctum');

        $this->putJson("/api/roles/{$targetRole}/permissions", ['modules' => [
            'User Management' => ['View' => true],
        ]])->assertOk();

        $this->assertTrue($this->hasPerm($targetRole, 'User Management', 'View'));
    }

    public function test_role_editor_cannot_change_admin_role(): void
    {
        $adminRole = $this->adminRoleId();
        $before = DB::table('role_permissions')->where('role_id', $adminRole)->count();
        $editorRole = $this->makeRole([['Role Management', 'Edit']]);
        $this->actingAs($this->makeUser($editorRole)->fresh(), 'sanctum');

        $this->putJson("/api/roles/{$adminRole}/permissions", ['modules' => ['Role Management' => ['Edit' => true]]]);

        $this->assertSame($before, DB::table('role_permissions')->where('role_id', $adminRole)->count());
    }

    public function test_permission_editor_cannot_grant_override_they_do_not_hold(): void
    {
        $editorRole = $this->makeRole([['User Permission', 'Edit']]);
        $editor = $this->makeUser($editorRole);
        $this->actingAs($editor->fresh(), 'sanctum');

        $this->putJson("/api/permissions/{$editor->id}", ['modules' => ['User Management' => ['Delete' => true]]])
            ->assertStatus(403);

        $this->assertSame(0, DB::table('user_permission_overrides')->where('user_id', $editor->id)->count());
    }

    public function test_permission_editor_cannot_touch_admin_user(): void
    {
        $admin = $this->makeUser($this->adminRoleId());
        $editorRole = $this->makeRole([['User Permission', 'Edit']]);
        $this->actingAs($this->makeUser($editorRole)->fresh(), 'sanctum');

        $this->putJson("/api/permissions/{$admin->id}", ['modules' => ['User Management' => ['Delete' => false]]]);

        $this->assertSame(0, DB::table('user_permission_overrides')->where('user_id', $admin->id)->count());
    }

    public function test_user_editor_cannot_assign_admin_role(): void
    {
        $adminRole = $this->adminRoleId();
        $editorRole = $this->makeRole([['User Management', 'Edit']]);
        $editor = $this->makeUser($editorRole);
        $this->actingAs($editor->fresh(), 'sanctum');

        $this->putJson("/api/users/{$editor->id}", ['role_id' => $adminRole])->assertStatus(403);

        $this->assertSame($editorRole, (int) DB::table('users')->where('id', $editor->id)->value('role_id'));
    }

    public function test_user_editor_cannot_move_self_to_role_with_more_permissions(): void
    {
        $bigRole = $this->makeRole([['User Management', 'Delete'], ['User Management', 'Edit']]);
        $editorRole = $this->makeRole([['User Management', 'Edit']]);
        $editor = $this->makeUser($editorRole);
        $this->actingAs($editor->fresh(), 'sanctum');

        $this->putJson("/api/users/{$editor->id}", ['role_id' => $bigRole])->assertStatus(403);

        $this->assertSame($editorRole, (int) DB::table('users')->where('id', $editor->id)->value('role_id'));
    }

    public function test_achievement_owner_only(): void
    {
        $role = $this->makeRole([]);
        $owner = $this->makeUser($role);
        $other = $this->makeUser($role);
        $id = DB::table('user_achievements')->insertGetId([
            'user_id' => $owner->id, 'title' => 'T', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actingAs($other->fresh(), 'sanctum');

        $this->putJson("/api/profile/achievements/{$id}", ['title' => 'Hacked'])->assertStatus(403);
        $this->deleteJson("/api/profile/achievements/{$id}")->assertStatus(403);

        $this->assertSame('T', DB::table('user_achievements')->where('id', $id)->value('title'));
    }

    public function test_non_approver_cannot_reject_a_pending_settlement_even_without_an_open_request(): void
    {
        $role = $this->makeRole([['Loan Payments', 'Edit']]);
        $user = $this->makeUser($role);
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        $id = DB::table('facility_payments')->insertGetId([
            'code' => 'ZZ' . Str::random(8), 'credit_facility_id' => 999999, 'branch_id' => $this->branchId, 'amount' => 100,
            'paid_at' => now(), 'payment_type' => 'Settlement', 'approval_status' => 'PendingApproval', 'status' => 'Active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
        $this->actingAs($user->fresh(), 'sanctum');

        $this->postJson("/api/loan-payments/{$id}/reject-settlement", ['void_reason' => 'x'], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertStatus(403);

        $this->assertSame('PendingApproval', DB::table('facility_payments')->where('id', $id)->value('approval_status'));
    }

    private function managerRole(): int
    {
        return $this->makeRole([['User Management', 'View'], ['User Management', 'Create'], ['User Management', 'Edit'], ['User Management', 'Delete']]);
    }

    public function test_user_manager_cannot_reset_an_admins_password_or_delete_an_admin(): void
    {
        $admin = $this->makeUser($this->adminRoleId());
        $spare = $this->makeUser($this->adminRoleId()); // keeps "last admin" out of the picture
        $manager = $this->makeUser($this->managerRole());
        $hash = DB::table('users')->where('id', $admin->id)->value('password');
        $this->actingAs($manager->fresh(), 'sanctum');

        $this->putJson("/api/users/{$admin->id}", ['password' => 'Zx9!pLm2#qWe'])->assertStatus(403);
        $this->deleteJson("/api/users/{$admin->id}")->assertStatus(403);

        $this->assertSame($hash, DB::table('users')->where('id', $admin->id)->value('password'));
        $this->assertNotNull(DB::table('users')->where('id', $spare->id)->first());
        $this->assertNotNull(DB::table('users')->where('id', $admin->id)->first());
    }

    public function test_user_manager_cannot_manage_a_user_of_another_branch(): void
    {
        $otherBranch = DB::table('branches')->insertGetId([
            'name' => 'Esc2 ' . Str::random(6), 'code' => 'F' . Str::random(4), 'branch_type' => 'Branch', 'status' => 'Active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $victim = $this->makeUser($this->makeRole([]));
        DB::table('users')->where('id', $victim->id)->update(['branch_id' => $otherBranch]);
        $manager = $this->makeUser($this->managerRole());
        $this->actingAs($manager->fresh(), 'sanctum');

        $this->putJson("/api/users/{$victim->id}", ['name' => 'Renamed'])->assertStatus(403);
        $this->deleteJson("/api/users/{$victim->id}")->assertStatus(403);
        $this->assertSame('Esc User', DB::table('users')->where('id', $victim->id)->value('name'));
    }

    public function test_user_manager_cannot_assign_a_role_stronger_than_their_own(): void
    {
        $strong = $this->makeRole([['User Management', 'View'], ['User Management', 'Create'], ['User Management', 'Edit'], ['User Management', 'Delete'], ['Role Management', 'Edit']]);
        $allBranch = DB::table('roles')->insertGetId([
            'name' => 'AllB' . Str::random(4), 'slug' => 'allb_' . Str::random(6), 'is_system' => 0, 'can_view_all_branches' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $manager = $this->makeUser($this->managerRole());
        $target = $this->makeUser($this->makeRole([]));
        $this->actingAs($manager->fresh(), 'sanctum');

        foreach ([$strong, $allBranch] as $roleId) {
            $this->putJson("/api/users/{$target->id}", ['role_id' => $roleId])->assertStatus(403);
            $this->postJson('/api/users', [
                'name' => 'New', 'email' => 'new' . Str::random(6) . '@example.test', 'password' => 'Zx9!pLm2#qWe',
                'role_id' => $roleId, 'branch_id' => $this->branchId,
            ])->assertStatus(403);
        }
    }

    public function test_user_manager_can_still_create_and_edit_a_user_in_their_own_branch_with_a_weaker_role(): void
    {
        $weak = $this->makeRole([['User Management', 'View']]);
        $manager = $this->makeUser($this->managerRole());
        $this->actingAs($manager->fresh(), 'sanctum');

        $created = $this->postJson('/api/users', [
            'name' => 'New Staff', 'email' => 'ns' . Str::random(6) . '@example.test', 'password' => 'Zx9!pLm2#qWe',
            'role_id' => $weak, 'branch_id' => $this->branchId,
        ]);
        $created->assertStatus(201);
        $this->putJson('/api/users/' . $created->json('id'), ['name' => 'Renamed Staff'])->assertOk();
    }
}
