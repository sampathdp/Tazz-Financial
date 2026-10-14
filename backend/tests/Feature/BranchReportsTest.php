<?php

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\User;
use App\Support\CurrentBranch;
use App\Support\LedgerPoster;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Audit findings B-1 (accounting reports ignored branch), B-5 (entries with no branch) and B-7 (centers did
 * not roll up to their parent branch).
 */
class BranchReportsTest extends TestCase
{
    use DatabaseTransactions;

    private int $a;

    private int $b;

    private int $center;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $mk = fn (string $n, ?int $parent = null) => DB::table('branches')->insertGetId([
            'name' => "{$n} " . Str::random(4), 'code' => $n[0] . Str::random(4), 'branch_type' => $parent ? 'Center' : 'Branch', 'parent_id' => $parent,
            'status' => 'Active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->a = $mk('Alpha');
        $this->b = $mk('Beta');
        $this->center = $mk('Centre', $this->a);
        $this->admin = User::whereHas('role', fn ($q) => $q->where('slug', 'admin'))->first() ?? $this->markTestSkipped('no admin');
        CurrentBranch::flushCache();
        ChartOfAccount::flushBalancesCache();
    }

    private function income(int $branch, string $amount, string $date = '2032-03-10'): void
    {
        LedgerPoster::post([
            ['account_id' => ChartOfAccount::system('4010')->id, 'credit' => $amount],
            ['account_id' => ChartOfAccount::system('3015')->id, 'debit' => $amount],
        ], $date, ['source_type' => 'ManualAdjustment', 'description' => 'branch report test', 'branch_id' => $branch]);
        ChartOfAccount::flushBalancesCache();
    }

    private function interest(array $report): float
    {
        return (float) collect($report['income'])->firstWhere('code', '4010')['cash_balance'];
    }

    private function branchUser(int $branchId): User
    {
        $roleId = DB::table('roles')->insertGetId(['name' => 'BR' . Str::random(4), 'slug' => 'br_' . Str::random(6), 'is_system' => 0, 'can_view_all_branches' => 0, 'created_at' => now(), 'updated_at' => now()]);
        foreach (DB::table('permissions')->pluck('id') as $pid) {
            DB::table('role_permissions')->insert(['role_id' => $roleId, 'permission_id' => $pid, 'created_at' => now()]);
        }

        return User::forceCreate(['name' => 'BR User', 'email' => 'br' . Str::random(6) . '@example.test', 'password' => Hash::make('x'), 'role_id' => $roleId, 'branch_id' => $branchId, 'status' => 'Active'])->fresh();
    }

    public function test_profit_loss_can_be_limited_to_one_branch_and_a_branch_user_is_always_limited(): void
    {
        $this->income($this->a, '100.00');
        $this->income($this->b, '40.00');
        $this->income($this->center, '10.00');
        $range = 'from=2032-03-01&to=2032-03-31';

        $this->actingAs($this->admin, 'sanctum');
        $all = $this->getJson("/api/accounting-reports/profit-loss?{$range}")->assertOk()->json();
        $onlyB = $this->getJson("/api/accounting-reports/profit-loss?{$range}&branch_id={$this->b}")->assertOk()->json();
        $onlyA = $this->getJson("/api/accounting-reports/profit-loss?{$range}&branch_id={$this->a}")->assertOk()->json();

        $this->assertEquals(40.0, $this->interest($onlyB), 'branch B alone');
        $this->assertEquals(110.0, $this->interest($onlyA), 'branch A rolls up its center (100 + 10)');
        $this->assertEquals(150.0, $this->interest($all), 'company total is the sum of every branch');

        // A branch-confined user cannot widen the view with ?branch_id=.
        $this->actingAs($this->branchUser($this->b), 'sanctum');
        $mine = $this->getJson("/api/accounting-reports/profit-loss?{$range}&branch_id={$this->a}")->assertOk()->json();
        $this->assertEquals(40.0, $this->interest($mine));
    }

    public function test_branch_balance_sheet_and_trial_balance_do_not_claim_to_balance_on_their_own(): void
    {
        $this->income($this->a, '100.00');
        $this->actingAs($this->admin, 'sanctum');
        $bs = $this->getJson("/api/accounting-reports/balance-sheet?as_of=2032-12-31&branch_id={$this->a}")->assertOk()->json();
        $this->assertNull($bs['balanced']);
        $tb = $this->getJson("/api/accounting-reports/trial-balance?as_of=2032-12-31&branch_id={$this->a}")->assertOk()->json();
        $this->assertNull($tb['totals']['balanced']);
        $whole = $this->getJson('/api/accounting-reports/trial-balance?as_of=2032-12-31')->json();
        $this->assertTrue($whole['totals']['balanced'], 'the whole company still balances');
    }

    public function test_a_center_user_does_not_see_the_parent_and_a_parent_user_sees_the_center(): void
    {
        $this->income($this->a, '100.00');
        $this->income($this->center, '10.00');
        $range = 'from=2032-03-01&to=2032-03-31';

        $this->actingAs($this->branchUser($this->center), 'sanctum');
        $this->assertEquals(10.0, $this->interest($this->getJson("/api/accounting-reports/profit-loss?{$range}")->json()));
        CurrentBranch::flushCache();
        $this->actingAs($this->branchUser($this->a), 'sanctum');
        $this->assertEquals(110.0, $this->interest($this->getJson("/api/accounting-reports/profit-loss?{$range}")->json()));
    }

    public function test_child_money_rows_take_their_branch_from_the_parent_record(): void
    {
        $bankId = DB::table('banks')->insertGetId(['name' => 'CB ' . Str::random(4), 'created_at' => now(), 'updated_at' => now()]);
        $bank = \App\Models\BankAccount::create(['branch_id' => $this->b, 'bank_id' => $bankId, 'name' => 'CBA ' . Str::random(4), 'account_number' => 'N' . Str::random(8),
            'account_type' => 'Current', 'currency' => 'LKR', 'opening_balance' => 0, 'current_balance' => 0, 'status' => 'Active']);
        $txn = \App\Models\BankTransaction::create(['bank_account_id' => $bank->id, 'txn_date' => now()->toDateString(), 'transaction_type' => 'Deposit', 'amount' => 5, 'balance_after' => 5,
            'status' => 'Cleared', 'contra_kind' => 'Ledger']);
        $this->assertSame($this->b, (int) $txn->fresh()->branch_id);
    }

    public function test_an_entry_posted_without_a_branch_is_tagged_with_the_acting_users_branch(): void
    {
        $this->actingAs($this->branchUser($this->b), 'sanctum');
        $entry = LedgerPoster::post([
            ['account_id' => ChartOfAccount::system('5010')->id, 'debit' => '5.00'],
            ['account_id' => ChartOfAccount::system('3015')->id, 'credit' => '5.00'],
        ], '2032-04-01', ['source_type' => 'ManualAdjustment', 'description' => 'no branch given']);
        $this->assertSame($this->b, (int) $entry->branch_id);
    }
}
