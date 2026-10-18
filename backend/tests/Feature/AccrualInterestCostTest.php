<?php

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\FixedDeposit;
use App\Models\FundingProduct;
use App\Models\SavingsAccount;
use App\Models\User;
use App\Support\AccrualInterestCostCalculator;
use App\Support\FdInterestCalculator;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The Accrual Basis column of the Profit & Loss recognises interest COST
 * (Fixed Deposit + Daily Savings deposit interest) day-count as it is
 * scheduled to be incurred, not when the cash moves — the mirror of accrual
 * interest income. Covers AccrualInterestCostCalculator and its wiring into
 * the profit-loss endpoint.
 *
 * Runs against the normal (seeded) DB under DatabaseTransactions, so every
 * assertion is a delta against a baseline captured before the test's own
 * FD / savings rows are added — never an absolute ledger figure.
 */
class AccrualInterestCostTest extends TestCase
{
    use DatabaseTransactions;

    private int $branchId;

    private int $customerId;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        // The FD interest-expense control account is a base-seed account; the
        // savings ones (2050 / 5120 / 4045) come from a later migration that
        // some databases pre-date — backfill them so the test is self-sufficient
        // (mirrors 2026_09_08_000001_seed_savings_accounts_to_chart_of_accounts).
        ChartOfAccount::where('code', '5100')->exists() ?: $this->markTestSkipped('Chart of account 5100 not seeded.');
        foreach ([['2050', 'Customer Savings Deposits Payable', 'Liability', 'Credit'],
            ['5120', 'Interest Expense - Savings Deposits', 'Expense', 'Debit'],
            ['4045', 'Savings Account Fee Income', 'Income', 'Credit']] as [$code, $name, $type, $normal]) {
            ChartOfAccount::firstOrCreate(['code' => $code], [
                'name' => $name, 'account_type' => $type, 'normal_balance' => $normal, 'is_system' => true, 'status' => 'Active',
            ]);
        }
        $this->admin = User::whereHas('role', fn ($q) => $q->where('slug', 'admin'))->first()
            ?? $this->markTestSkipped('No admin user seeded.');
        $this->customerId = DB::table('customers')->value('id') ?? $this->markTestSkipped('No customer seeded.');
        $this->branchId = DB::table('branches')->insertGetId([
            'name' => 'AIC', 'code' => 'AIC' . Str::random(3), 'branch_type' => 'Branch',
            'status' => 'Active', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function fundingProduct(): FundingProduct
    {
        return FundingProduct::create([
            'name' => 'FD 12%', 'code' => 'FD' . Str::random(4), 'interest_method' => 'monthly',
            'allow_method_choice' => true, 'annual_rate_percent' => '12.000', 'rate_is_manual' => false,
            'allowed_terms_months' => [12], 'allow_custom_term' => true,
            'min_deposit_amount' => '0', 'sort_order' => 0, 'status' => 'Active',
        ]);
    }

    private function fd(string $method, string $start, int $termMonths, float $principal): FixedDeposit
    {
        $calc = FdInterestCalculator::calculate($principal, 12.0, $termMonths, $method);
        $fd = FixedDeposit::create([
            'fd_number' => 'FD-' . Str::random(8),
            'branch_id' => $this->branchId, 'customer_id' => $this->customerId,
            'funding_product_id' => $this->fundingProduct()->id, 'principal_amount' => $principal,
            'interest_rate_percent' => '12.000', 'interest_payment_method' => $method,
            'term_months' => $termMonths, 'start_date' => $start,
            'maturity_date' => Carbon::parse($start)->addMonthsNoOverflow($termMonths)->toDateString(),
            'monthly_interest_amount' => $calc['monthlyInterestAmount'],
            'total_interest_amount' => $calc['totalInterestAmount'],
            'maturity_amount' => $calc['maturityAmount'],
            'status' => 'Active', 'created_by' => $this->admin->id,
        ]);
        if ($method === 'monthly') {
            $fd->interestPayments()->insert(FdInterestCalculator::generateSchedule($fd));
        }

        return $fd;
    }

    public function test_monthly_fd_interest_accrues_over_the_term_never_on_a_payment(): void
    {
        // Baselines captured before this test's own FD is added — every
        // assertion below is a delta against the seeded books.
        $wideBefore = AccrualInterestCostCalculator::fdInterestForPeriod('2026-01-01', '2028-06-30');
        $janBefore = AccrualInterestCostCalculator::fdInterestForPeriod('2026-01-01', '2026-01-31');
        $cash5100Before = $this->ledger('5100');

        $this->fd('monthly', '2026-01-15', 12, 1_200_000);

        // A window spanning the whole schedule sees every installment in full —
        // the delta is exactly this FD's 12 x 12,000 = 144,000.
        $wideAfter = AccrualInterestCostCalculator::fdInterestForPeriod('2026-01-01', '2028-06-30');
        $this->assertSame('144000.00', bcsub($wideAfter, $wideBefore, 2));

        // A single month picks up only a partial first installment (< 12,000).
        $jan = (float) bcsub(
            AccrualInterestCostCalculator::fdInterestForPeriod('2026-01-01', '2026-01-31'), $janBefore, 2
        );
        $this->assertGreaterThan(0, $jan);
        $this->assertLessThan(12_000, $jan);

        // Cash basis (ledger 5100) is untouched — no interest was paid.
        $this->assertSame('0.00', bcsub($this->ledger('5100'), $cash5100Before, 2));
    }

    public function test_maturity_method_fd_accrues_straight_line_across_the_term(): void
    {
        $fullBefore = AccrualInterestCostCalculator::fdInterestForPeriod('2026-01-01', '2027-06-30');
        $halfBefore = AccrualInterestCostCalculator::fdInterestForPeriod('2026-01-01', '2026-06-30');

        // 1,000,000 @ 12% for 12 months => 120,000, all at maturity, no schedule rows.
        $this->fd('maturity', '2026-01-01', 12, 1_000_000);

        $this->assertSame('120000.00', bcsub(
            AccrualInterestCostCalculator::fdInterestForPeriod('2026-01-01', '2027-06-30'), $fullBefore, 2
        ));
        $half = (float) bcsub(
            AccrualInterestCostCalculator::fdInterestForPeriod('2026-01-01', '2026-06-30'), $halfBefore, 2
        );
        $this->assertGreaterThan(55_000, $half);
        $this->assertLessThan(65_000, $half);
    }

    public function test_savings_interest_cost_matches_the_calculator_with_no_run_posted(): void
    {
        $account = SavingsAccount::create([
            'account_number' => 'SAV-' . Str::random(6), 'branch_id' => $this->branchId, 'customer_id' => $this->customerId,
            'savings_product_id' => $this->savingsProductId(), 'interest_rate_percent' => '12.000',
            'interest_calc_method' => 'min_monthly_balance', 'interest_posting_frequency' => 'monthly',
            'min_balance' => '0', 'withdrawal_fee' => '0', 'open_date' => '2026-01-01',
            'current_balance' => '100000.00', 'status' => 'Active', 'created_by' => $this->admin->id,
        ]);

        // Account exists but has no posted movements yet — contributes nothing.
        $before = AccrualInterestCostCalculator::savingsInterestForPeriod('2026-01-01', '2026-01-31');
        $cash5120Before = $this->ledger('5120');

        DB::table('savings_transactions')->insert([
            'txn_number' => 'ST-' . Str::random(8), 'savings_account_id' => $account->id, 'txn_date' => '2026-01-01',
            'type' => 'Deposit', 'direction' => 'In', 'amount' => '100000.00', 'running_balance' => '100000.00',
            'status' => 'Posted', 'created_at' => now(), 'updated_at' => now(),
        ]);

        // min_monthly_balance: 12%/12 on 100,000 held all of January = 1,000.00.
        $after = AccrualInterestCostCalculator::savingsInterestForPeriod('2026-01-01', '2026-01-31');
        $this->assertSame('1000.00', bcsub($after, $before, 2));

        // 5120 ledger balance is untouched — no interest run was posted.
        $this->assertSame('0.00', bcsub($this->ledger('5120'), $cash5120Before, 2));
    }

    public function test_profit_loss_endpoint_wires_accrual_interest_cost_from_the_calculator(): void
    {
        $this->actingAs($this->admin, 'sanctum');
        // Absolute URL on purpose: this project's .env APP_URL carries an "/api"
        // suffix that the test client would otherwise double up on a "/api/..." path.
        $url = 'http://localhost/api/accounting-reports/profit-loss?from=2026-01-01&to=2026-03-31';
        $before = $this->getJson($url)->assertOk()->json();

        $this->fd('monthly', '2026-01-15', 12, 1_200_000);

        $after = $this->getJson($url)->assertOk()->json();

        // The endpoint's accrual Interest Cost is exactly the calculator's total.
        $this->assertSame(
            AccrualInterestCostCalculator::forPeriod('2026-01-01', '2026-03-31')['total'],
            $after['waterfall_accrual']['interest_cost'],
        );
        // Cash basis Interest Cost did not move — this FD paid nothing.
        $this->assertSame($before['waterfall_cash']['interest_cost'], $after['waterfall_cash']['interest_cost']);
        // The accrual column now leads the cash column, and net profit split with it.
        $this->assertNotSame($after['waterfall_cash']['interest_cost'], $after['waterfall_accrual']['interest_cost']);
        $this->assertSame($before['net_profit_cash'], $after['net_profit_cash']);
        $this->assertNotSame($before['net_profit_accrual'], $after['net_profit_accrual']);
        $this->assertArrayHasKey('total_expense_accrual', $after);
    }

    public function test_accrual_basis_keeps_ledger_balance_for_income_without_an_accrual_schedule(): void
    {
        $this->actingAs($this->admin, 'sanctum');
        $res = $this->getJson('http://localhost/api/accounting-reports/profit-loss?from=2020-01-01&to=2030-12-31')->assertOk()->json();

        $nonInterest = array_filter($res['income'], fn ($row) => !in_array($row['code'], ['4010', '4015'], true));
        $this->assertNotEmpty($nonInterest);
        foreach ($nonInterest as $row) {
            $this->assertSame($row['cash_balance'], $row['accrual_balance'], "income {$row['code']} must not be zeroed on the accrual basis");
        }
    }

    private function ledger(string $code): string
    {
        $row = ChartOfAccount::balancesAsOf('2030-01-01')->get(ChartOfAccount::system($code)->id);

        return $row->balance ?? '0.00';
    }

    private function savingsProductId(): int
    {
        return DB::table('savings_products')->value('id')
            ?? DB::table('savings_products')->insertGetId([
                'name' => 'DS', 'code' => 'DS' . Str::random(4), 'interest_rate_percent' => '12.000',
                'interest_calc_method' => 'min_monthly_balance', 'interest_posting_frequency' => 'monthly',
                'min_opening_deposit' => '0', 'min_balance' => '0', 'withdrawal_fee' => '0', 'ledger_fee' => '0',
                'dormancy_days' => 0, 'status' => 'Active', 'created_at' => now(), 'updated_at' => now(),
            ]);
    }
}
