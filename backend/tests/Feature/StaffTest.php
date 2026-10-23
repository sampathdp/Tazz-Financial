<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Staff register: generated codes, search, login creation, and use as an expense payee. */
class StaffTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::whereHas('role', fn ($q) => $q->where('slug', 'admin'))->first() ?? $this->markTestSkipped('No admin user.');
    }

    private function makeStaff(array $extra = []): array
    {
        $res = $this->actingAs($this->admin, 'sanctum')->postJson('/api/staff', $extra + [
            'name' => 'Test Staff ' . Str::random(4), 'phone' => '0771234567',
            'nic_no' => 'T' . random_int(10000000, 99999999) . 'V', 'email' => Str::random(8) . '@staff.test',
        ]);
        $res->assertCreated();

        return $res->json();
    }

    public function test_create_generates_code_and_search_finds_by_phone_nic_email(): void
    {
        $s = $this->makeStaff();
        $this->assertMatchesRegularExpression('/^STF-\d{4,}$/', $s['staff_code']);
        $this->assertSame(sprintf('STF-%04d', $s['id']), $s['staff_code']);

        foreach ([$s['staff_code'], $s['phone'], $s['nic_no'], $s['email']] as $term) {
            $ids = collect($this->actingAs($this->admin, 'sanctum')->getJson('/api/staff?search=' . urlencode($term))->assertOk()->json())->pluck('id');
            $this->assertContains($s['id'], $ids->all(), "search by {$term}");
        }
    }

    public function test_nic_and_email_must_be_unique(): void
    {
        $s = $this->makeStaff();
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/staff', ['name' => 'Dup', 'nic_no' => $s['nic_no']])->assertStatus(422);
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/staff', ['name' => 'Dup', 'email' => $s['email']])->assertStatus(422);
    }

    public function test_create_login_links_user_and_blocks_delete(): void
    {
        $s = $this->makeStaff();
        $role = Role::where('can_view_all_branches', true)->where('slug', '!=', 'admin')->first() ?? Role::where('slug', '!=', 'admin')->firstOrFail();

        $this->actingAs($this->admin, 'sanctum')->postJson("/api/staff/{$s['id']}/create-login", [
            'role_id' => $role->id, 'password' => 'Str0ng!Pass#2026x', 'branch_id' => $this->admin->branch_id,
        ])->assertCreated()->assertJsonPath('has_login', true);

        $staff = Staff::findOrFail($s['id']);
        $this->assertNotNull($staff->user_id);
        $this->assertSame($s['email'], User::findOrFail($staff->user_id)->email);
        $this->assertSame(1, Staff::where('user_id', $staff->user_id)->count(), 'no duplicate staff row');

        $this->actingAs($this->admin, 'sanctum')->postJson("/api/staff/{$s['id']}/create-login", ['role_id' => $role->id, 'password' => 'Str0ng!Pass#2026x'])->assertStatus(422);
        $this->actingAs($this->admin, 'sanctum')->deleteJson("/api/staff/{$s['id']}")->assertStatus(422);
    }

    public function test_staff_without_activity_can_be_deleted_and_options_lists_active_only(): void
    {
        $s = $this->makeStaff();
        $inactive = $this->makeStaff(['status' => 'Inactive']);
        $ids = collect($this->actingAs($this->admin, 'sanctum')->getJson('/api/staff/options')->assertOk()->json())->pluck('id')->all();
        $this->assertContains($s['id'], $ids);
        $this->assertNotContains($inactive['id'], $ids);

        $this->actingAs($this->admin, 'sanctum')->deleteJson("/api/staff/{$s['id']}")->assertNoContent();
    }

    public function test_expense_accepts_staff_payee_but_not_with_vendor(): void
    {
        $s = $this->makeStaff();
        $branchId = $this->admin->branch_id ?? \DB::table('branches')->value('id');
        $categoryId = \DB::table('expense_categories')->value('id') ?? $this->markTestSkipped('No expense category.');
        $payload = ['branch_id' => $branchId, 'expense_category_id' => $categoryId, 'amount' => '100.00', 'expense_date' => now()->toDateString()];

        $this->actingAs($this->admin, 'sanctum')->postJson('/api/expenses', $payload + ['staff_id' => $s['id']])
            ->assertCreated()->assertJsonPath('staff_id', $s['id']);

        $vendorId = \DB::table('vendors')->value('id');
        if ($vendorId) {
            $this->actingAs($this->admin, 'sanctum')->postJson('/api/expenses', $payload + ['staff_id' => $s['id'], 'vendor_id' => $vendorId])->assertStatus(422);
        }
    }
}
