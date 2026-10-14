<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Regression tests for audit findings B-3 (modules readable across branches) and
 * B-4 (a branch-confined user could create records in another branch).
 * Extends BranchIsolationProbeTest, which only covered the cash/bank models.
 */
class BranchIsolationExtendedTest extends TestCase
{
    use DatabaseTransactions;

    private int $branchA;

    private int $branchB;

    private string $marker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->marker = 'ZZX' . Str::random(6);
        foreach (['A', 'B'] as $k) {
            $id = DB::table('branches')->insertGetId([
                'name' => "Ext {$k} " . $this->marker, 'code' => 'X' . $k . Str::random(3), 'branch_type' => 'Branch', 'status' => 'Active',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $k === 'A' ? $this->branchA = $id : $this->branchB = $id;
        }
    }

    private function branchUser(int $branchId): User
    {
        $roleId = DB::table('roles')->insertGetId([
            'name' => 'ExtRole' . Str::random(4), 'slug' => 'ext_' . Str::random(6), 'is_system' => 0, 'can_view_all_branches' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach (DB::table('permissions')->pluck('id') as $pid) {
            DB::table('role_permissions')->insert(['role_id' => $roleId, 'permission_id' => $pid, 'created_at' => now()]);
        }

        return User::forceCreate([
            'name' => 'Ext User', 'email' => 'ext' . Str::random(6) . '@example.test', 'password' => Hash::make('x'),
            'role_id' => $roleId, 'branch_id' => $branchId, 'status' => 'Active',
        ])->fresh();
    }

    public function test_branch_user_cannot_list_other_branch_expenses_deposits_borrowings_auctions_reconciliations_or_journals(): void
    {
        $categoryId = DB::table('expense_categories')->value('id') ?? $this->markTestSkipped('no expense category');
        $now = now();
        DB::table('expenses')->insert([
            'code' => 'EX-' . $this->marker, 'branch_id' => $this->branchB, 'expense_category_id' => $categoryId, 'amount' => 10,
            'expense_date' => $now->toDateString(), 'description' => 'Exp ' . $this->marker, 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('borrowings')->insert([
            'borrowing_number' => 'BR-' . $this->marker, 'branch_id' => $this->branchB, 'type' => 'Overdraft',
            'lender_name' => 'Lender ' . $this->marker, 'principal_amount' => 10, 'borrowing_date' => $now->toDateString(), 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('pawning_auctions')->insert([
            'auction_no' => 'AU-' . $this->marker, 'branch_id' => $this->branchB, 'scheduled_date' => $now->toDateString(), 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('cash_reconciliations')->insert([
            'branch_id' => $this->branchB, 'as_at_date' => '2001-02-03', 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('journal_entries')->insert([
            'code' => 'JE-' . $this->marker, 'entry_date' => $now->toDateString(), 'description' => 'JE ' . $this->marker,
            'branch_id' => $this->branchB, 'status' => 'Posted', 'created_at' => $now, 'updated_at' => $now,
        ]);

        $this->actingAs($this->branchUser($this->branchA), 'sanctum');
        $leaks = [];
        foreach ([
            'expenses' => $this->marker, 'borrowings' => $this->marker, 'pawning-auctions' => $this->marker,
            'cash-reconciliations' => '2001-02-03', 'journal-entries' => $this->marker,
        ] as $ep => $needle) {
            $res = $this->getJson("/api/{$ep}");
            if ($res->status() === 200 && str_contains($res->getContent(), $needle)) {
                $leaks[] = $ep;
            }
        }
        $this->assertSame([], $leaks, 'branch A user can read branch B records via: ' . implode(', ', $leaks));
    }

    public function test_branch_user_cannot_list_other_branch_fixed_deposits(): void
    {
        $customerId = DB::table('customers')->value('id');
        $productId = DB::table('funding_products')->value('id');
        if (!$customerId || !$productId) {
            $this->markTestSkipped('needs a customer and a funding product');
        }
        $now = now();
        DB::table('fixed_deposits')->insert([
            'public_uuid' => (string) Str::uuid(), 'fd_number' => 'FD-' . $this->marker, 'branch_id' => $this->branchB, 'customer_id' => $customerId,
            'funding_product_id' => $productId, 'interest_payment_method' => 'maturity', 'interest_rate_percent' => 10, 'maturity_amount' => 110,
            'maturity_date' => $now->copy()->addYear()->toDateString(), 'principal_amount' => 100, 'start_date' => $now->toDateString(),
            'term_months' => 12, 'total_interest_amount' => 10, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->actingAs($this->branchUser($this->branchA), 'sanctum');
        $res = $this->getJson('/api/fixed-deposits');
        $this->assertStringNotContainsString($this->marker, $res->getContent(), 'branch A user can read a branch B fixed deposit');
    }

    public function test_branch_user_cannot_create_an_expense_in_another_branch(): void
    {
        $categoryId = DB::table('expense_categories')->value('id') ?? $this->markTestSkipped('no expense category');
        $this->actingAs($this->branchUser($this->branchA), 'sanctum');

        $this->postJson('/api/expenses', [
            'branch_id' => $this->branchB, 'expense_category_id' => $categoryId, 'amount' => 25, 'expense_date' => now()->toDateString(),
            'description' => 'Cross-branch ' . $this->marker,
        ])->assertStatus(403);
        $this->assertSame(0, DB::table('expenses')->where('description', 'Cross-branch ' . $this->marker)->count());

        // Own branch still works.
        $this->postJson('/api/expenses', [
            'branch_id' => $this->branchA, 'expense_category_id' => $categoryId, 'amount' => 25, 'expense_date' => now()->toDateString(),
            'description' => 'Own-branch ' . $this->marker,
        ])->assertStatus(201);
    }

    public function test_every_model_with_a_branch_column_is_branch_scoped_or_explicitly_exempt(): void
    {
        // Models whose rows are deliberately not isolated by BelongsToBranch, with the reason.
        $exempt = [
            'User' => 'managed through Administration, scoped by permission',
            'Branch' => 'the branch master itself',
            'ApprovalTier' => 'configuration',
            'NumberSequence' => 'internal counter per branch',
            'FinanceTransaction' => 'scoped in its controller with CurrentBranch::applyScope',
            'FinanceAuditEvent' => 'scoped in its controller with CurrentBranch::applyScope',
            'JournalEntry' => 'internal posting model used by every poster; scoped in JournalEntryController',
            'ManualJournalEntry' => 'scoped in ManualJournalEntryController',
            'PawningGoldRate' => 'rate card, branch optional',
            'Subscription' => 'company-level record',
            'SavingsCollectionSheet' => 'scoped in its controller with CurrentBranch::applyScope',
            'SavingsInterestRun' => 'branch optional (null = every branch)',
            'BankTransaction' => 'branch copied from its bank account; listed through the scoped bank account',
            'SavingsTransaction' => 'branch copied from its savings account, which is scoped',
            'PaymentVoucher' => 'branch copied from its expense; the list is scoped through the expense',
            'SalesExecutiveCommission' => 'branch copied from its loan, which is scoped',
        ];
        $unscoped = [];
        foreach (glob(app_path('Models/*.php')) as $file) {
            $class = 'App\\Models\\' . basename($file, '.php');
            if (!class_exists($class) || !is_subclass_of($class, Model::class)) {
                continue;
            }
            $model = new $class();
            $columns = DB::getSchemaBuilder()->getColumnListing($model->getTable());
            if (!in_array('branch_id', $columns, true)) {
                continue;
            }
            $short = class_basename($class);
            if (!in_array(\App\Models\Concerns\BelongsToBranch::class, class_uses_recursive($class), true) && !isset($exempt[$short])) {
                $unscoped[] = $short;
            }
        }
        sort($unscoped);
        $this->assertSame([], $unscoped, 'Models with a branch_id column but no BelongsToBranch scope and no documented exemption: ' . implode(', ', $unscoped));
    }
}
