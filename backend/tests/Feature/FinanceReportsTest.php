<?php

namespace Tests\Feature;

use App\Models\CashAccount;
use App\Models\ChartOfAccount;
use App\Models\User;
use App\Support\FinancePostingService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 8 — the Finance reports set (FinanceReportController). Smoke-tests
 * every report type responds, plus checks the two figures a report must
 * always tie out to: daily-cash closing == the live cache balance, and
 * cash-flow net == inflow - outflow. DatabaseTransactions.
 */
class FinanceReportsTest extends TestCase
{
    use DatabaseTransactions;

    private CashAccount $till;

    private User $admin;

    private int $expenseAccountId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->expenseAccountId = ChartOfAccount::where('account_type', 'Expense')->value('id')
            ?? $this->markTestSkipped('Chart of accounts not seeded.');
        $this->admin = User::whereHas('role', fn ($q) => $q->where('slug', 'admin'))->first()
            ?? $this->markTestSkipped('No admin user seeded.');

        $branchId = DB::table('branches')->insertGetId(['name' => 'RP', 'code' => 'RP' . Str::random(3), 'branch_type' => 'Branch', 'status' => 'Active', 'created_at' => now(), 'updated_at' => now()]);
        $this->till = CashAccount::create(['code' => 'RP-CASH-' . Str::random(3), 'branch_id' => $branchId, 'account_type' => 'BranchMain', 'name' => 'RP Till', 'opening_balance' => 0, 'current_balance' => '50000.00', 'status' => 'Active']);
    }

    private function spend(string $amount): void
    {
        FinancePostingService::execute([
            'txn_type' => 'CASH_OUT', 'amount' => $amount, 'entry_date' => now()->toDateString(),
            'branch_id' => $this->till->branch_id,
            'movements' => [['account_type' => 'cash', 'account_id' => $this->till->id, 'delta' => '-' . $amount]],
            'gl_lines' => [
                ['account_id' => $this->expenseAccountId, 'debit' => $amount, 'credit' => 0],
                ['account_id' => ChartOfAccount::forCashTill($this->till->fresh())->id, 'debit' => 0, 'credit' => $amount],
            ],
            'executed_by' => $this->admin->id,
        ]);
    }

    public function test_every_report_type_responds(): void
    {
        $this->spend('1200.00');
        $this->actingAs($this->admin, 'sanctum');

        foreach ([
            'daily-cash', 'cash-position', 'cash-flow', 'transfers', 'floats',
            'requests-pending', 'requests-unpaid', 'reversals', 'variance', 'audit',
        ] as $type) {
            $this->getJson("/api/finance-reports/{$type}?from=2000-01-01&to=2100-01-01")
                ->assertOk();
        }

        $this->getJson('/api/finance-reports/not-a-report')->assertNotFound();
    }

    public function test_daily_cash_closing_ties_to_the_cache_balance(): void
    {
        $this->spend('1200.00');
        $this->actingAs($this->admin, 'sanctum');

        $body = $this->getJson('/api/finance-reports/daily-cash')->assertOk()->json();
        $row = collect($body['rows'])->firstWhere('cashAccountId', $this->till->id);

        $this->assertNotNull($row);
        $this->assertEqualsWithDelta(50000.0, (float) $row['opening'], 0.001);
        $this->assertEqualsWithDelta(1200.0, (float) $row['outflow'], 0.001);
        $this->assertEqualsWithDelta((float) $this->till->fresh()->current_balance, (float) $row['closing'], 0.001);
    }

    public function test_cash_flow_net_is_inflow_minus_outflow(): void
    {
        $this->spend('1200.00');
        $this->actingAs($this->admin, 'sanctum');

        $body = $this->getJson('/api/finance-reports/cash-flow')->assertOk()->json();
        $this->assertEqualsWithDelta((float) $body['totalIn'] - (float) $body['totalOut'], (float) $body['netCash'], 0.001);
        $this->assertGreaterThanOrEqual(1200.0, (float) $body['totalOut']);
    }

    public function test_report_is_gated_by_permission(): void
    {
        $roleId = DB::table('roles')->where('slug', 'staff')->value('id') ?? 1;
        $nobody = User::create(['public_uuid' => (string) Str::uuid(), 'name' => 'No Perm', 'email' => 'np-' . Str::random(5) . '@t.local', 'password' => bcrypt('x'), 'role_id' => $roleId, 'branch_id' => $this->till->branch_id, 'status' => 'Active']);

        $this->actingAs($nobody, 'sanctum');
        $this->getJson('/api/finance-reports/daily-cash')->assertForbidden();
    }
}
