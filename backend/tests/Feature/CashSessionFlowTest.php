<?php

namespace Tests\Feature;

use App\Exceptions\FinancePostingException;
use App\Models\CashAccount;
use App\Models\ChartOfAccount;
use App\Models\User;
use App\Support\CashSessionService;
use App\Support\FinancePostingService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 6 — daily cash session: open, close, verify (with a shortage posted
 * to 5310). DatabaseTransactions.
 */
class CashSessionFlowTest extends TestCase
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
        $branchId = DB::table('branches')->insertGetId(['name' => 'CS', 'code' => 'CS' . Str::random(3), 'branch_type' => 'Branch', 'status' => 'Active', 'created_at' => now(), 'updated_at' => now()]);
        $mk = fn ($p) => User::create(['public_uuid' => (string) Str::uuid(), 'name' => $p, 'email' => strtolower($p) . Str::random(5) . '@t.local', 'password' => bcrypt('x'), 'role_id' => $roleId, 'branch_id' => $branchId, 'status' => 'Active']);
        $this->cashier = $mk('Cashier');
        $this->manager = $mk('Manager');
        $this->till = CashAccount::create(['code' => 'CS-CASH-' . Str::random(3), 'branch_id' => $branchId, 'account_type' => 'BranchMain', 'name' => 'CS Till', 'opening_balance' => 0, 'current_balance' => '80000.00', 'status' => 'Active']);
    }

    public function test_open_records_the_expected_balance_and_blocks_a_second(): void
    {
        $s = CashSessionService::open($this->till->id, '80000.00', $this->cashier->id);
        $this->assertSame('Open', $s->status);
        $this->assertSame('80000.00', (string) $s->opening_expected);

        $this->expectException(FinancePostingException::class);
        CashSessionService::open($this->till->id, '80000.00', $this->cashier->id);
    }

    public function test_close_then_verify_posts_a_shortage_and_trues_the_till(): void
    {
        $s = CashSessionService::open($this->till->id, '80000.00', $this->cashier->id);

        // A collection lands during the day (posted straight to the till balance).
        $exp = ChartOfAccount::where('account_type', 'Expense')->value('id');
        FinancePostingService::execute([
            'txn_type' => 'CASH_IN', 'amount' => '20000.00', 'entry_date' => now()->toDateString(),
            'branch_id' => $this->till->branch_id,
            'movements' => [['account_type' => 'cash', 'account_id' => $this->till->id, 'delta' => '20000.00']],
            'gl_lines' => [
                ['account_id' => ChartOfAccount::forCashTill($this->till->fresh())->id, 'debit' => '20000.00', 'credit' => 0],
                ['account_id' => $exp, 'debit' => 0, 'credit' => '20000.00'],
            ],
            'executed_by' => $this->cashier->id,
        ]);
        $this->assertSame('100000.00', (string) $this->till->fresh()->current_balance);

        // Counted 99,700 — a 300 shortage.
        $s = CashSessionService::close($s, '99700.00', ['1000' => 99], '700.00', $this->cashier->id);
        $this->assertSame('Closed', $s->status);
        $this->assertSame('100000.00', (string) $s->expected_closing);
        $this->assertSame('-300.00', (string) $s->variance);
        $this->assertSame('300.00', (string) $s->shortage);

        $s = CashSessionService::verify($s, $this->manager->id);
        $this->assertSame('Verified', $s->status);
        $this->assertSame('99700.00', (string) $this->till->fresh()->current_balance);
        $bal = ChartOfAccount::balancesAsOf(now()->toDateString())->get(ChartOfAccount::system('5310')->id)->balance;
        $this->assertSame('300.00', $bal);
    }

    public function test_closer_cannot_verify(): void
    {
        $s = CashSessionService::open($this->till->id, '80000.00', $this->cashier->id);
        $s = CashSessionService::close($s, '80000.00', [], '0', $this->cashier->id);

        $this->expectException(FinancePostingException::class);
        CashSessionService::verify($s, $this->cashier->id);
    }
}
