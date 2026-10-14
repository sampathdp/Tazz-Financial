<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\CashAccount;
use App\Models\ChartOfAccount;
use App\Models\User;
use App\Support\LedgerPoster;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Regression tests for audit findings C-1 (cash flow blank), C-2 (balance sheet adds the
 * provision to assets) and C-3 (management P&L waterfall skips accounts).
 * Every figure is hand-computed. Entries use far-future dates so existing data cannot interfere
 * with the period reports; cumulative reports are checked as baseline -> delta.
 */
class AccountingReportsAuditTest extends TestCase
{
    use DatabaseTransactions;

    private ChartOfAccount $tillLeaf;

    private ChartOfAccount $bankLeaf;

    protected function setUp(): void
    {
        parent::setUp();
        $branch = DB::table('branches')->insertGetId([
            'name' => 'Rpt ' . Str::random(5), 'code' => 'R' . Str::random(4), 'branch_type' => 'Branch', 'status' => 'Active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $till = CashAccount::create([
            'code' => 'RT-' . Str::random(6), 'branch_id' => $branch, 'account_type' => 'BranchMain', 'name' => 'RptTill ' . Str::random(4),
            'opening_balance' => 0, 'current_balance' => 0, 'status' => 'Active',
        ]);
        $this->tillLeaf = ChartOfAccount::forCashTill($till->fresh());
        $bankId = DB::table('banks')->insertGetId(['name' => 'RptBank ' . Str::random(4), 'created_at' => now(), 'updated_at' => now()]);
        $bank = BankAccount::create([
            'branch_id' => $branch, 'bank_id' => $bankId, 'name' => 'RptBankAcc ' . Str::random(4), 'account_number' => 'N' . Str::random(8),
            'account_type' => 'Current', 'currency' => 'LKR', 'opening_balance' => 0, 'current_balance' => 0, 'status' => 'Active',
        ]);
        $this->bankLeaf = ChartOfAccount::forBankAccount($bank->fresh());
        $admin = User::whereHas('role', fn ($q) => $q->where('slug', 'admin'))->first() ?? $this->markTestSkipped('no admin');
        $this->actingAs($admin, 'sanctum');
        ChartOfAccount::flushBalancesCache();
    }

    private function book(array $lines, string $date): void
    {
        LedgerPoster::post($lines, $date, ['source_type' => 'ManualAdjustment', 'description' => 'rpt audit']);
        ChartOfAccount::flushBalancesCache();
    }

    private function acct(string $code): int
    {
        return ChartOfAccount::system($code)->id;
    }

    public function test_cash_flow_sees_cash_posted_to_till_and_bank_leaf_accounts(): void
    {
        // The shared test database may already hold earlier cash (e.g. the UI seed), so cumulative cash is baseline -> delta.
        $baseline = (float) $this->getJson('/api/accounting-reports/cash-flow?from=2031-05-01&to=2031-05-31')->assertOk()->json('closing_cash');
        // A collection of 1,000 into the till (interest income) and 400 banked from the till.
        $this->book([
            ['account_id' => $this->tillLeaf->id, 'debit' => '1000.00'],
            ['account_id' => $this->acct('4010'), 'credit' => '1000.00'],
        ], '2031-05-10');
        $this->book([
            ['account_id' => $this->bankLeaf->id, 'debit' => '400.00'],
            ['account_id' => $this->tillLeaf->id, 'credit' => '400.00'],
        ], '2031-05-12');

        $r = $this->getJson('/api/accounting-reports/cash-flow?from=2031-05-01&to=2031-05-31')->assertOk()->json();

        $this->assertSame('1000.00', number_format((float) $r['net_change_in_cash'], 2, '.', ''), 'net change in cash');
        $this->assertEquals($baseline + 1000.0, (float) $r['closing_cash'], 'closing cash grows by till 600 + bank 400');
        $this->assertEquals($baseline, (float) $r['opening_cash']);
        $op = collect($r['operating_activities'])->firstWhere('code', '4010');
        $this->assertNotNull($op, 'interest income must appear under operating activities');
        $this->assertEquals(1000.0, (float) $op['amount']);
        $this->assertTrue($r['reconciled']);
    }

    public function test_balance_sheet_deducts_the_provision_from_assets_and_stays_balanced(): void
    {
        $asOf = '2031-12-31';
        $this->book([
            ['account_id' => $this->tillLeaf->id, 'debit' => '5000.00'],
            ['account_id' => $this->acct('3015'), 'credit' => '5000.00'],
        ], '2031-01-02');
        $before = $this->getJson("/api/accounting-reports/balance-sheet?as_of={$asOf}")->assertOk()->json();

        // Provision of 300: Dr 5300 expense / Cr 1190 contra-asset.
        $this->book([
            ['account_id' => $this->acct('5300'), 'debit' => '300.00'],
            ['account_id' => $this->acct('1190'), 'credit' => '300.00'],
        ], '2031-02-01');
        $after = $this->getJson("/api/accounting-reports/balance-sheet?as_of={$asOf}")->assertOk()->json();

        $this->assertEquals(-300.0, round((float) $after['total_assets'] - (float) $before['total_assets'], 2), 'a 300 provision lowers total assets by 300');
        $this->assertEquals(0.0, round((float) $after['total_assets'] - (float) $after['total_liabilities_and_equity'] - ((float) $before['total_assets'] - (float) $before['total_liabilities_and_equity']), 2), 'the provision must not unbalance the sheet');
        $this->assertSame($before['balanced'], $after['balanced']);
    }

    public function test_management_pl_waterfall_ties_to_the_flat_pl_for_every_income_and_expense_account(): void
    {
        $d = '2031-06-15';
        // Income across accounts the waterfall must not drop: 4025 pawning, 4045 savings fee, 4900 other, 4905 overage, 4010 loans.
        $this->book([
            ['account_id' => $this->tillLeaf->id, 'debit' => '1000.00'],
            ['account_id' => $this->acct('4025'), 'credit' => '400.00'],
            ['account_id' => $this->acct('4045'), 'credit' => '100.00'],
            ['account_id' => $this->acct('4900'), 'credit' => '150.00'],
            ['account_id' => $this->acct('4905'), 'credit' => '50.00'],
            ['account_id' => $this->acct('4010'), 'credit' => '300.00'],
        ], $d);
        // Expenses: 5310 shortage 20, 5110 borrowing interest 30, 5200 commission 10.
        $this->book([
            ['account_id' => $this->acct('5310'), 'debit' => '20.00'],
            ['account_id' => $this->acct('5110'), 'debit' => '30.00'],
            ['account_id' => $this->acct('5200'), 'debit' => '10.00'],
            ['account_id' => $this->tillLeaf->id, 'credit' => '60.00'],
        ], $d);

        $r = $this->getJson('/api/accounting-reports/profit-loss?from=2031-06-01&to=2031-06-30')->assertOk()->json();

        $this->assertEquals(940.0, (float) $r['net_profit_cash'], 'flat P&L: 1000 income - 60 expense');
        $this->assertEquals((float) $r['net_profit_cash'], (float) $r['waterfall_cash']['net_profit_after_tax'], 'waterfall net profit must equal the flat P&L');
    }
}
