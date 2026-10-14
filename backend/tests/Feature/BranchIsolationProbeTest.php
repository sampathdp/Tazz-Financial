<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\CashAccount;
use App\Models\ChartOfAccount;
use App\Models\User;
use App\Support\CashSessionService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A branch-scoped user must never see another branch's money records. Each list endpoint is
 * checked for a marker that only exists on a record in the OTHER branch.
 */
class BranchIsolationProbeTest extends TestCase
{
    use DatabaseTransactions;

    private int $branchA;

    private int $branchB;

    private string $marker;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::whereHas('role', fn ($q) => $q->where('slug', 'admin'))->first() ?? $this->markTestSkipped('no admin');
        $this->marker = 'ZZB' . Str::random(6);
        foreach (['A', 'B'] as $k) {
            $id = DB::table('branches')->insertGetId([
                'name' => "Iso {$k} " . $this->marker, 'code' => 'I' . $k . Str::random(3), 'branch_type' => 'Branch', 'status' => 'Active',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $k === 'A' ? $this->branchA = $id : $this->branchB = $id;
        }
    }

    private function asBranchAUser(): void
    {
        $roleId = DB::table('roles')->insertGetId([
            'name' => 'IsoRole' . Str::random(4), 'slug' => 'iso_' . Str::random(6), 'is_system' => 0, 'can_view_all_branches' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach (DB::table('permissions')->pluck('id') as $pid) {
            DB::table('role_permissions')->insert(['role_id' => $roleId, 'permission_id' => $pid, 'created_at' => now()]);
        }
        $user = User::forceCreate([
            'name' => 'Iso User', 'email' => 'iso' . Str::random(6) . '@example.test', 'password' => Hash::make('x'),
            'role_id' => $roleId, 'branch_id' => $this->branchA, 'status' => 'Active',
        ]);
        $this->actingAs($user->fresh(), 'sanctum');
    }

    public function test_branch_user_cannot_see_other_branch_money_records(): void
    {
        // Seed branch B records as the admin.
        $till = CashAccount::create([
            'code' => 'B-' . Str::random(6), 'branch_id' => $this->branchB, 'account_type' => 'BranchMain', 'name' => 'Till ' . $this->marker,
            'opening_balance' => 0, 'current_balance' => '5000.00', 'status' => 'Active',
        ]);
        ChartOfAccount::forCashTill($till->fresh());
        $bankRow = DB::table('banks')->insertGetId(['name' => 'Bank ' . $this->marker, 'created_at' => now(), 'updated_at' => now()]);
        BankAccount::create([
            'branch_id' => $this->branchB, 'bank_id' => $bankRow, 'name' => 'BankAcc ' . $this->marker, 'account_number' => 'N' . Str::random(8),
            'account_type' => 'Current', 'currency' => 'LKR', 'opening_balance' => 0, 'current_balance' => '100.00', 'status' => 'Active',
        ]);
        $this->actingAs($this->admin, 'sanctum');
        $this->postJson('/api/cashbook-entries', [
            'cash_account_id' => $till->id, 'entry_date' => now()->toDateString(), 'direction' => 'In', 'category' => 'Other',
            'description' => 'Entry ' . $this->marker, 'amount' => 10, 'offset_account_id' => ChartOfAccount::system('3015')->id,
        ])->assertStatus(201);
        CashSessionService::open($till->id, '5000.00', $this->admin->id, 'Sess ' . $this->marker);
        $fund = $this->postJson('/api/petty-cash-funds', ['branch_id' => $this->branchB, 'name' => 'Fund ' . $this->marker, 'float_limit' => 1000]);

        $this->asBranchAUser();
        $leaks = [];
        foreach (['cash-accounts', 'bank-accounts', 'cashbook-entries', 'finance-transactions', 'cash-sessions', 'petty-cash-funds', 'bank-transactions'] as $ep) {
            $res = $this->getJson("/api/{$ep}");
            if ($res->status() === 200 && str_contains($res->getContent(), $this->marker)) {
                $leaks[] = $ep;
            }
        }
        $this->assertSame([], $leaks, 'branch A user can read branch B records via: ' . implode(', ', $leaks));

        // ...and cannot write into another branch's till either (the scoped lookup 404s).
        $this->postJson('/api/cashbook-entries', [
            'cash_account_id' => $till->id, 'entry_date' => now()->toDateString(), 'direction' => 'In', 'category' => 'Other',
            'amount' => 5, 'offset_account_id' => ChartOfAccount::system('3015')->id,
        ])->assertStatus(404);
        unset($fund);
    }
}
