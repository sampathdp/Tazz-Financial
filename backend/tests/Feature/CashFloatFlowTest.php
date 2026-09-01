<?php

namespace Tests\Feature;

use App\Models\ApprovalType;
use App\Models\CashAccount;
use App\Models\CashFloatSettlement;
use App\Models\ChartOfAccount;
use App\Models\User;
use App\Support\CashFloatService;
use App\Support\FinancePostingService;
use App\Support\FinanceRequestService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 5 — cashier float lifecycle: issue (funded via a finance request),
 * spend, request settlement, settle with a shortage. DatabaseTransactions.
 */
class CashFloatFlowTest extends TestCase
{
    use DatabaseTransactions;

    private CashAccount $till;

    private User $cashier;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        if (! ChartOfAccount::where('code', '5310')->exists()) {
            $this->markTestSkipped('Cash short/over accounts not seeded.');
        }
        $roleId = DB::table('roles')->where('slug', 'staff')->value('id') ?? 1;
        $branchId = DB::table('branches')->insertGetId(['name' => 'FLT', 'code' => 'FLT' . Str::random(3), 'branch_type' => 'Branch', 'status' => 'Active', 'created_at' => now(), 'updated_at' => now()]);
        $mk = fn ($p) => User::create(['public_uuid' => (string) Str::uuid(), 'name' => $p, 'email' => strtolower($p) . Str::random(5) . '@t.local', 'password' => bcrypt('x'), 'role_id' => $roleId, 'branch_id' => $branchId, 'status' => 'Active']);
        $this->cashier = $mk('Cashier');
        $this->manager = $mk('Manager');
        ApprovalType::where('code', 'finance_transfer')->firstOrFail()->approvers()->syncWithoutDetaching([$this->manager->id]);
        ApprovalType::where('code', 'finance_float_issue')->firstOrFail()->approvers()->syncWithoutDetaching([$this->manager->id]);
        ApprovalType::where('code', 'finance_float_settlement')->firstOrFail()->approvers()->syncWithoutDetaching([$this->manager->id]);

        $this->till = CashAccount::create(['code' => 'FLT-CASH-' . Str::random(3), 'branch_id' => $branchId, 'account_type' => 'BranchMain', 'name' => 'FLT Till', 'opening_balance' => 0, 'current_balance' => '300000.00', 'status' => 'Active']);
    }

    private function issueAndFund(string $amount): CashAccount
    {
        $req = CashFloatService::issue($this->till->id, $this->cashier->id, $amount, $this->cashier->id);
        $req = FinanceRequestService::approve($req->fresh(), $this->manager->id);
        FinanceRequestService::execute($req, [], $this->manager->id);

        return CashAccount::where('account_type', 'CashierFloat')->where('responsible_user_id', $this->cashier->id)->firstOrFail();
    }

    public function test_issue_funds_the_float_and_opens_a_settlement(): void
    {
        $float = $this->issueAndFund('50000.00');

        $this->assertSame('50000.00', (string) $float->current_balance);
        $this->assertSame('250000.00', (string) $this->till->fresh()->current_balance);
        $settlement = CashFloatSettlement::where('cash_account_id', $float->id)->firstOrFail();
        $this->assertSame('Open', $settlement->status);
        $this->assertSame('50000.00', (string) $settlement->issued_total);
    }

    public function test_settle_posts_a_shortage_and_returns_the_rest(): void
    {
        $float = $this->issueAndFund('50000.00');
        $floatLeaf = ChartOfAccount::forCashTill($float->fresh());

        // Cashier spends 12,000 on sundries (offset to any expense account).
        $exp = ChartOfAccount::where('account_type', 'Expense')->value('id');
        FinancePostingService::execute([
            'txn_type' => 'CASH_OUT', 'amount' => '12000.00', 'entry_date' => now()->toDateString(),
            'branch_id' => $float->branch_id,
            'movements' => [['account_type' => 'cash', 'account_id' => $float->id, 'delta' => '-12000.00']],
            'gl_lines' => [
                ['account_id' => $exp, 'debit' => '12000.00', 'credit' => 0],
                ['account_id' => $floatLeaf->id, 'debit' => 0, 'credit' => '12000.00'],
            ],
            'executed_by' => $this->cashier->id,
        ]);
        $this->assertSame('38000.00', (string) $float->fresh()->current_balance);

        CashFloatService::requestSettlement($float->id, $this->cashier->id);
        // Counted 37,500 — a 500 shortage.
        $settlement = CashFloatService::settle($float->id, '37500.00', $this->manager->id);

        $this->assertSame('Settled', $settlement->status);
        $this->assertSame('-500.00', (string) $settlement->variance);
        $this->assertSame('500.00', (string) $settlement->shortage);
        $this->assertSame('37500.00', (string) $settlement->returned_total);
        $this->assertSame('0.00', (string) $float->fresh()->current_balance);
        $this->assertSame('Inactive', $float->fresh()->status);
        // 250k (after issue) + 37.5k returned
        $this->assertSame('287500.00', (string) $this->till->fresh()->current_balance);
        // 5310 Cash Shortage carries the 500
        $bal = ChartOfAccount::balancesAsOf(now()->toDateString())->get(ChartOfAccount::system('5310')->id)->balance;
        $this->assertSame('500.00', $bal);
    }
}
