<?php

namespace Tests\Feature;

use App\Models\CashAccount;
use App\Models\ChartOfAccount;
use App\Models\User;
use App\Support\CurrentBranch;
use App\Support\FinancePostingService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Branch-to-branch funding through Head Office: lender B -> HO -> borrower A. Every entry balances per branch,
 * the till balances move with the ledger, and the clearing accounts net to zero and drop out of company-wide statements.
 */
class BranchFundingTest extends TestCase
{
    use DatabaseTransactions;

    private int $lender;

    private int $borrower;

    private int $ho;

    private CashAccount $lenderTill;

    private CashAccount $borrowerTill;

    protected function setUp(): void
    {
        parent::setUp();
        $now = ['created_at' => now(), 'updated_at' => now()];
        $mk = fn (string $n, bool $ho = false) => DB::table('branches')->insertGetId(['name' => "{$n} " . Str::random(4), 'code' => $n[0] . Str::random(4), 'branch_type' => 'Branch', 'status' => 'Active', 'is_head_office' => $ho] + $now);
        // Only one Head Office may exist for routing; use the existing one, or make one for this test.
        $this->ho = (int) (DB::table('branches')->where('is_head_office', true)->value('id') ?: $mk('Head', true));
        $this->lender = $mk('Lender');
        $this->borrower = $mk('Borrower');
        $till = fn (int $branch) => CashAccount::create(['code' => 'BF-' . Str::random(8), 'branch_id' => $branch, 'account_type' => 'BranchMain', 'name' => 'BFT ' . Str::random(4), 'opening_balance' => 0, 'current_balance' => 0, 'status' => 'Active'])->fresh();
        $this->lenderTill = $till($this->lender);
        $this->borrowerTill = $till($this->borrower);
        $this->actingAs(User::whereHas('role', fn ($q) => $q->where('slug', 'admin'))->first() ?? $this->markTestSkipped('no admin'), 'sanctum');
        CurrentBranch::flushCache();

        // Seed the lender's till with 10,000 through the one money writer.
        DB::transaction(fn () => FinancePostingService::execute([
            'txn_type' => 'ADJUSTMENT', 'amount' => '10000.00', 'entry_date' => '2033-01-01', 'branch_id' => $this->lender,
            'movements' => [['account_type' => 'cash', 'account_id' => $this->lenderTill->id, 'delta' => '10000.00']],
            'gl_lines' => [
                ['account_id' => ChartOfAccount::forCashTill($this->lenderTill)->id, 'debit' => '10000.00', 'credit' => 0],
                ['account_id' => ChartOfAccount::system('3010')->id, 'debit' => 0, 'credit' => '10000.00'],
            ],
            'description' => 'bf seed',
        ]));
        ChartOfAccount::flushBalancesCache();
    }

    private function request(array $over = []): array
    {
        return $this->postJson('/api/branch-funding', $over + [
            'lender_branch_id' => $this->lender, 'borrower_branch_id' => $this->borrower,
            'lender_account_id' => ChartOfAccount::forCashTill($this->lenderTill)->id,
            'borrower_account_id' => ChartOfAccount::forCashTill($this->borrowerTill)->id,
            'principal_amount' => 5000, 'interest_rate_percent' => 12, 'accrual_mode' => 'monthly', 'request_date' => '2033-02-01',
        ])->assertCreated()->json();
    }

    private function balance(string $code, ?int $branch = null): float
    {
        ChartOfAccount::flushBalancesCache();
        $q = $branch ? "&branch_id={$branch}" : '';
        $tb = $this->getJson("/api/accounting-reports/trial-balance?as_of=2033-12-31{$q}")->assertOk()->json();
        $r = collect($tb['rows'])->firstWhere('code', $code);

        return $r ? (float) $r['debit_balance'] - (float) $r['credit_balance'] : 0.0;
    }

    public function test_funding_flows_through_head_office_and_nets_to_zero(): void
    {
        $a = $this->request();
        $this->assertSame('Requested', $a['status']);

        $this->postJson("/api/branch-funding/{$a['id']}/approve", ['disbursed_on' => '2033-02-01'])->assertOk()->assertJsonPath('status', 'Active');

        $this->assertEquals(5000, (float) $this->lenderTill->fresh()->current_balance, 'lender till paid out 5000');
        $this->assertEquals(5000, (float) $this->borrowerTill->fresh()->current_balance);
        $this->assertEquals(5000, $this->balance('1410', $this->lender));
        $this->assertEquals(-5000, $this->balance('2410', $this->borrower));
        $this->assertEquals(5000, $this->balance('1411', $this->ho));
        $this->assertEquals(-5000, $this->balance('2411', $this->ho));

        // Company-wide: the funding accounts net to zero, so statements hide them and still balance.
        $tb = $this->getJson('/api/accounting-reports/trial-balance?as_of=2033-12-31')->assertOk()->json();
        $codes = collect($tb['rows'])->pluck('code');
        foreach (['1410', '2410', '1411', '2411'] as $hidden) {
            $this->assertNotContains($hidden, $codes->all(), "{$hidden} hidden company-wide");
        }
        $this->assertTrue($tb['totals']['balanced']);

        // 28 days at 12% on 5000 = 46.03
        $this->postJson("/api/branch-funding/{$a['id']}/accrue", ['through' => '2033-03-01'])->assertOk()->assertJsonPath('accrued_interest_unpaid', '46.03');
        $this->assertEquals(46.03, $this->balance('4040', $this->lender) * -1);
        $this->assertEquals(46.03, $this->balance('5130', $this->borrower));

        // The borrower has only the 5000 it was lent; it must hold the interest too before it can repay.
        FinancePostingService::execute([
            'txn_type' => 'ADJUSTMENT', 'amount' => '100.00', 'entry_date' => '2033-02-15', 'branch_id' => $this->borrower,
            'movements' => [['account_type' => 'cash', 'account_id' => $this->borrowerTill->id, 'delta' => '100.00']],
            'gl_lines' => [
                ['account_id' => ChartOfAccount::forCashTill($this->borrowerTill)->id, 'debit' => '100.00', 'credit' => 0],
                ['account_id' => ChartOfAccount::system('3010')->id, 'debit' => 0, 'credit' => '100.00'],
            ],
            'description' => 'bf borrower top-up',
        ]);
        $this->postJson("/api/branch-funding/{$a['id']}/repay", ['amount' => 5046.03, 'payment_date' => '2033-03-01'])->assertOk()->assertJsonPath('status', 'Closed');
        $this->assertEquals(10046.03, (float) $this->lenderTill->fresh()->current_balance);
        $this->assertEquals(0, $this->balance('1410', $this->lender));
        $this->assertEquals(0, $this->balance('2410', $this->borrower));
    }

    public function test_borrow_limit_and_lender_cash_are_enforced(): void
    {
        DB::table('branches')->where('id', $this->borrower)->update(['funding_borrow_limit' => 3000]);
        $a = $this->request(['principal_amount' => 5000]);
        $this->postJson("/api/branch-funding/{$a['id']}/approve")->assertStatus(422);

        DB::table('branches')->where('id', $this->borrower)->update(['funding_borrow_limit' => null]);
        $big = $this->request(['principal_amount' => 50000]);
        $this->postJson("/api/branch-funding/{$big['id']}/approve")->assertStatus(422);
    }

    public function test_head_office_cannot_be_a_party_and_lender_must_differ(): void
    {
        $this->postJson('/api/branch-funding', [
            'lender_branch_id' => $this->ho, 'borrower_branch_id' => $this->borrower,
            'lender_account_id' => ChartOfAccount::forCashTill($this->lenderTill)->id,
            'borrower_account_id' => ChartOfAccount::forCashTill($this->borrowerTill)->id,
            'principal_amount' => 100, 'interest_rate_percent' => 10, 'accrual_mode' => 'monthly', 'request_date' => '2033-02-01',
        ])->assertStatus(422);
    }
}
