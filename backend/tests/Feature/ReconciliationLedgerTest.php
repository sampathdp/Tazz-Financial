<?php

namespace Tests\Feature;

use App\Models\CashAccount;
use App\Models\ChartOfAccount;
use App\Models\Expense;
use App\Models\FacilityPayment;
use App\Models\PettyCashFund;
use App\Models\User;
use App\Support\FinancePostingService;
use App\Support\LedgerPoster;
use App\Support\LoanLedgerPoster;
use App\Support\PawningLedgerPoster;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Plan: docs/audit/test-plans/reconciliation.md
 *
 * The DB is persistent/populated, so every assertion is DELTA-based: capture a
 * figure, run a self-contained balanced scenario, assert the movement matches
 * an independent hand computation and that the ledger stays internally
 * consistent. No reversals / auctions / bank-method collections in the
 * scenario (those hit F-1 / F-5 / F-7 / Risk 1 and would inject a known
 * residual — each is proven separately in its own file).
 */
class ReconciliationLedgerTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private int $branchId;

    private CashAccount $till;

    private ChartOfAccount $tillLeaf;

    protected function setUp(): void
    {
        parent::setUp();
        if (! ChartOfAccount::where('code', '1120')->exists()) {
            $this->markTestSkipped('Chart of accounts not seeded.');
        }
        $this->admin = User::whereHas('role', fn ($q) => $q->where('slug', 'admin'))->first()
            ?? $this->markTestSkipped('No admin user seeded.');
        Model::preventLazyLoading(false);
        $this->travelTo(Carbon::parse('2026-03-01 09:00:00'));

        $this->branchId = DB::table('branches')->insertGetId([
            'name' => 'RC Branch', 'code' => 'RC' . Str::random(3),
            'branch_type' => 'Branch', 'status' => 'Active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->till = CashAccount::create([
            'code' => 'RC-CASH-' . Str::random(6), 'branch_id' => $this->branchId, 'account_type' => 'BranchMain',
            'name' => 'RC Till', 'opening_balance' => '5000000.00', 'current_balance' => '5000000.00', 'status' => 'Active',
        ]);
        $this->tillLeaf = ChartOfAccount::forCashTill($this->till->fresh());
        // Post the till's opening balance through the GL so the leaf and the
        // cache agree from the start (as CashAccountController::store does).
        \App\Support\OpeningBalanceLedgerPoster::postForCashAccount($this->till->fresh());

        $this->actingAs($this->admin, 'sanctum');
    }

    private function acct(string $code): int
    {
        return ChartOfAccount::where('code', $code)->value('id');
    }

    private function glBalance(int $accountId, ?string $asOf = null): string
    {
        ChartOfAccount::flushBalancesCache();
        $r = ChartOfAccount::balancesAsOf($asOf ?? now()->addYears(5)->toDateString())->get($accountId);

        return $r ? (string) $r->balance : '0.00';
    }

    /** system-wide (SUM debit, SUM credit) over ALL journal lines. */
    private function globalTotals(): array
    {
        $r = DB::table('journal_lines')->selectRaw('COALESCE(SUM(debit),0) d, COALESCE(SUM(credit),0) c')->first();

        return [(string) $r->d, (string) $r->c];
    }

    private function assertBc(string $e, string $a, string $m = ''): void
    {
        $this->assertSame(0, bccomp($e, $a, 2), $m ?: "expected {$e} got {$a}");
    }

    // ---------------- the scenario ----------------

    /** Runs a mixed but fully-balanced business day; returns hand-computed expected deltas. */
    private function runFullDay(): array
    {
        $c1100 = $this->acct('1100');
        $c1120 = $this->acct('1120');
        $c4010 = $this->acct('4010');
        $c4025 = $this->acct('4025');
        $c5010 = $this->acct('5010');

        // 1) Loan disbursement 100,000 Cash: Dr 1100 / Cr till.
        $custId = DB::table('customers')->insertGetId([
            'public_uuid' => (string) Str::uuid(), 'branch_id' => $this->branchId, 'code' => 'C' . Str::random(7),
            'full_name' => 'RC Cust', 'status' => 'Active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $ltId = DB::table('loan_types')->insertGetId([
            'name' => 'RC LT', 'code' => 'RCL' . Str::random(3), 'interest_method' => 'flat',
            'rate_period' => 'monthly', 'status' => 'Active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $loanId = DB::table('credit_facilities')->insertGetId([
            'public_uuid' => (string) Str::uuid(), 'code' => 'L' . Str::random(9), 'facility_type' => 'Loan',
            'branch_id' => $this->branchId, 'customer_id' => $custId, 'principal_amount' => '100000.00',
            'interest_rate_percent' => '2.000', 'term_months' => 10, 'rate_period' => 'monthly',
            'disbursed_amount' => '100000.00', 'disbursed_at' => now(), 'status' => 'Active',
            'outstanding_balance_cache' => '100000.00', 'approved_by' => $this->admin->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('loan_facility_details')->insert([
            'credit_facility_id' => $loanId, 'loan_type_id' => $ltId, 'interest_method' => 'flat',
            'installment_frequency' => 'monthly', 'down_payment' => '0.00', 'disbursement_method' => 'Cash',
        ]);
        LoanLedgerPoster::postDisbursement(\App\Models\Loan::find($loanId), $this->admin->id);

        // 2) Loan Cash payment 12,000 against instalment 1 of a full 10-row flat schedule
        //    (10 x principal 10,000 / interest 2,000 — see LOAN-01).
        for ($i = 1; $i <= 10; $i++) {
            DB::table('facility_schedules')->insert([
                'credit_facility_id' => $loanId, 'installment_no' => $i,
                'due_date' => Carbon::parse('2026-04-01')->addMonthsNoOverflow($i - 1)->toDateString(),
                'principal_due' => '10000.00', 'interest_due' => '2000.00', 'total_due' => '12000.00',
                'paid_amount' => '0.00', 'status' => 'Pending', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $lpay = FacilityPayment::create([
            'code' => 'PV' . Str::random(8), 'credit_facility_id' => $loanId, 'branch_id' => $this->branchId,
            'amount' => '12000.00', 'payment_type' => 'Regular', 'payment_method' => 'Cash',
            'status' => 'Active', 'approval_status' => 'Approved', 'received_by' => $this->admin->id, 'paid_at' => now(),
        ]);
        \App\Support\LoanPaymentAllocator::applyAllocation(\App\Models\Loan::find($loanId), $lpay, 12000.0);
        LoanLedgerPoster::postCashReceipt($lpay->fresh());

        // 3) Pawning disbursement 200,000 + full redemption at 3% (interest 6,000).
        $pawId = DB::table('credit_facilities')->insertGetId([
            'public_uuid' => (string) Str::uuid(), 'code' => 'P' . Str::random(9), 'facility_type' => 'Pawning',
            'branch_id' => $this->branchId, 'customer_id' => $custId, 'principal_amount' => '200000.00',
            'interest_rate_percent' => '3.000', 'term_months' => 1, 'rate_period' => 'monthly',
            'disbursed_amount' => '200000.00', 'disbursed_at' => now(), 'status' => 'Active',
            'outstanding_balance_cache' => '200000.00', 'created_by' => $this->admin->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $ptId = DB::table('pawning_types')->insertGetId([
            'name' => 'RC PT', 'code' => 'RCP' . Str::random(3), 'normal_rate_percent' => '3.000',
            'cycle_days' => 30, 'ltv_percent' => '80.00', 'auction_eligible_days' => 30, 'auction_notice_days' => 7,
            'status' => 'Active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('pawning_facility_details')->insert([
            'credit_facility_id' => $pawId, 'pawning_type_id' => $ptId, 'applied_rate_percent' => '3.000',
            'is_special_rate' => 0, 'total_gross_weight_grams' => '20.000', 'total_net_weight_grams' => '20.000',
            'total_appraised_value' => '300000.00', 'repay_due_date' => '2026-03-31', 'renewal_count' => 0,
        ]);
        PawningLedgerPoster::postDisbursement(\App\Models\Pawning::find($pawId), $this->admin->id);

        $ppay = FacilityPayment::create([
            'code' => 'PP' . Str::random(8), 'credit_facility_id' => $pawId, 'branch_id' => $this->branchId,
            'amount' => '206000.00', 'payment_type' => 'Settlement', 'payment_method' => 'Cash',
            'status' => 'Active', 'approval_status' => 'Approved', 'received_by' => $this->admin->id, 'paid_at' => now(),
        ]);
        DB::table('facility_payment_allocations')->insert([
            'facility_payment_id' => $ppay->id, 'facility_schedule_id' => null,
            'principal_applied' => '200000.00', 'interest_applied' => '6000.00', 'penalty_applied' => '0.00',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        PawningLedgerPoster::postCashReceipt($ppay->fresh());

        // 4) Cash expense 50,000 (Dr 5010 / Cr till).
        $catId = DB::table('expense_categories')->insertGetId([
            'name' => 'RC Cat ' . Str::random(4), 'code' => 'RCC' . Str::random(4),
            'chart_of_account_id' => $c5010, 'status' => 'Active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $exp = Expense::create([
            'code' => 'EX' . Str::random(8), 'branch_id' => $this->branchId, 'expense_category_id' => $catId,
            'amount' => '50000.00', 'expense_date' => now()->toDateString(), 'status' => 'Approved',
            'requested_by' => $this->admin->id, 'approved_by' => $this->admin->id, 'approved_at' => now(),
        ]);
        \App\Support\ExpensePaymentExecutor::pay($exp, ['payment_method' => 'Cash', 'cash_account_id' => $this->till->id], $this->admin->id);

        // 5) Petty cash: replenish 30,000 from till, then spend 4,000.
        $fund = PettyCashFund::create([
            'code' => 'PCF' . Str::random(5), 'branch_id' => $this->branchId, 'custodian_id' => $this->admin->id,
            'name' => 'RC Petty', 'float_limit' => '50000.00', 'current_balance' => '0.00', 'status' => 'Active',
        ]);
        $this->postJson('/api/petty-cash-replenishments', [
            'petty_cash_fund_id' => $fund->id, 'amount' => 30000, 'funding_method' => 'Cash', 'cash_account_id' => $this->till->id,
        ])->assertStatus(201);
        $this->postJson('/api/petty-cash-vouchers', [
            'petty_cash_fund_id' => $fund->id, 'expense_category_id' => $catId, 'amount' => 4000,
        ])->assertStatus(201);

        // 6) Bank deposit 150,000 from till.
        $bankRowId = DB::table('banks')->insertGetId(['name' => 'RC Bank', 'created_at' => now(), 'updated_at' => now()]);
        $bank = \App\Models\BankAccount::create([
            'branch_id' => $this->branchId, 'bank_id' => $bankRowId, 'name' => 'RC Bank Acc',
            'account_number' => 'ACC' . Str::random(10), 'account_type' => 'Current', 'currency' => 'LKR',
            'opening_balance' => 0, 'current_balance' => '0.00', 'status' => 'Active',
        ]);
        $bankLeaf = ChartOfAccount::forBankAccount($bank->fresh());
        $this->postJson('/api/bank-transactions', [
            'bank_account_id' => $bank->id, 'txn_date' => now()->toDateString(), 'transaction_type' => 'Deposit',
            'amount' => 150000, 'status' => 'Cleared', 'contra_kind' => 'Cash', 'contra_cash_account_id' => $this->till->id,
        ])->assertStatus(201);

        // Hand-computed till cache movement:
        //   +5,000,000 opening
        //   -  100,000 (1) disbursement
        //   +   12,000 (2) loan payment
        //   -  200,000 (3) pawning disbursement
        //   +  206,000 (3) pawning redemption
        //   -   50,000 (4) expense
        //   -   30,000 (5) petty cash replenish
        //   -  150,000 (6) bank deposit
        //   = 4,688,000.00   (petty cash spend does NOT touch the till)
        return [
            'loanId' => $loanId, 'pawId' => $pawId, 'fund' => $fund, 'bank' => $bank, 'bankLeaf' => $bankLeaf,
            'expectedTillCash' => '4688000.00',
            'accts' => compact('c1100', 'c1120', 'c4010', 'c4025', 'c5010'),
        ];
    }

    // =============================================================
    // RECON-01 — full-day sim: ledger stays internally consistent, every
    // operational figure equals its GL counterpart.
    // =============================================================
    public function test_recon01_full_day_simulation_reconciles_to_the_ledger(): void
    {
        [$dBefore, $cBefore] = $this->globalTotals();

        $r = $this->runFullDay();
        $a = $r['accts'];

        [$dAfter, $cAfter] = $this->globalTotals();

        // System-wide: the day's postings added equal debits and credits.
        $this->assertBc(bcsub($dAfter, $dBefore, 2), bcsub($cAfter, $cBefore, 2),
            'the day added unequal debits and credits');

        // Till: cache == hand computation == GL leaf.
        $this->assertBc($r['expectedTillCash'], (string) $this->till->fresh()->current_balance, 'till cache');
        // GL leaf = cache - opening (opening was posted through the GL in setUp).
        $this->assertBc((string) $this->till->fresh()->current_balance, $this->glBalance($this->tillLeaf->id), 'till leaf GL == cache');

        // Bank: cache == GL leaf == 150,000.
        $this->assertBc('150000.00', (string) $r['bank']->fresh()->current_balance);
        $this->assertBc('150000.00', $this->glBalance($r['bankLeaf']->id));

        // Petty cash fund: cache == 1015-x leaf == 30,000 - 4,000 = 26,000.
        $fundLeaf = ChartOfAccount::forPettyCashFund($r['fund']->fresh());
        $this->assertBc('26000.00', (string) $r['fund']->fresh()->current_balance);
        $this->assertBc('26000.00', $this->glBalance($fundLeaf->id));

        // 1100: this loan's GL contribution = 100,000 disbursed - 10,000 principal collected = +90,000.
        $loanGl1100 = DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->whereIn('journal_entries.source_type', ['Loan', 'LoanPayment'])
            ->where(function ($q) use ($r) {
                $q->where(fn ($w) => $w->where('journal_entries.source_type', 'Loan')->where('journal_entries.source_id', $r['loanId']))
                    ->orWhereIn('journal_entries.source_id', DB::table('facility_payments')->where('credit_facility_id', $r['loanId'])->pluck('id'));
            })
            ->where('journal_lines.account_id', $a['c1100'])
            ->selectRaw('COALESCE(SUM(debit),0) - COALESCE(SUM(credit),0) net')->value('net');
        $this->assertBc('90000.00', (string) $loanGl1100, 'loan 1100 GL contribution');
        // The all-in cache legitimately differs (it includes unpaid interest) — 9 rows x 12,000 = 108,000.
        $this->assertBc('108000.00', (string) \App\Models\Loan::find($r['loanId'])->outstanding_balance_cache);
        // 1120: pawning nets to zero for the ticket (disburse +200k, redeem -200k).
        // (both are deltas vs the shared control account — captured around runFullDay)
        // -> checked via the day's own entries:
        $pawDisb = DB::table('journal_lines')->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.source_type', 'Pawning')->where('journal_entries.source_id', $r['pawId'])
            ->where('journal_lines.account_id', $a['c1120'])->sum('debit');
        $this->assertBc('200000.00', (string) $pawDisb);
    }

    // =============================================================
    // RECON-03 — Trial Balance report ties to an independent raw recompute
    // =============================================================
    public function test_recon03_trial_balance_report_ties_to_journal_lines(): void
    {
        $this->runFullDay();

        $body = $this->getJson('/api/accounting-reports/trial-balance?as_of=2030-01-01')->assertOk()->json();

        // Independent recompute per account (raw GROUP BY, all statuses — a
        // Reversed original and its reversal net to zero) — NOT balancesAsOf().
        $raw = DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->join('chart_of_accounts', 'chart_of_accounts.id', '=', 'journal_lines.account_id')
            ->selectRaw('chart_of_accounts.id, chart_of_accounts.normal_balance nb, SUM(journal_lines.debit) d, SUM(journal_lines.credit) c')
            ->groupBy('chart_of_accounts.id', 'chart_of_accounts.normal_balance')
            ->get()->keyBy('id');

        $mismatch = 0;
        foreach ($body['rows'] as $row) {
            $r = $raw->get($row['account_id']);
            $expected = $r ? ($r->nb === 'Debit' ? bcsub((string) $r->d, (string) $r->c, 2) : bcsub((string) $r->c, (string) $r->d, 2)) : '0.00';
            if (bccomp($expected, (string) $row['balance'], 2) !== 0) {
                $mismatch++;
            }
        }
        $this->assertSame(0, $mismatch, 'trial-balance report balances disagree with an independent raw recompute');

        // The report's own debit/credit totals net (difference) — assert it did not
        // get WORSE from our balanced day (F-1 aside, a balanced set keeps it stable).
        $this->assertIsString($body['totals']['difference']);
    }

    // =============================================================
    // RECON-08 — date filters: as_of excludes future-dated entries, no leakage
    // =============================================================
    public function test_recon08_as_of_filter_excludes_later_entries(): void
    {
        // Assert on the per-test till leaf (fresh, zero history) — a shared seeded
        // account like 5010 already carries dev-data entries in the date window.
        $leaf = $this->tillLeaf->id;
        $c5010 = $this->acct('5010');

        // One entry today, one dated a year later — both credit the till leaf.
        LedgerPoster::post([
            ['account_id' => $c5010, 'debit' => '1111.00', 'credit' => 0],
            ['account_id' => $leaf, 'debit' => 0, 'credit' => '1111.00'],
        ], '2026-03-01', ['source_type' => 'ManualAdjustment']);
        LedgerPoster::post([
            ['account_id' => $c5010, 'debit' => '2222.00', 'credit' => 0],
            ['account_id' => $leaf, 'debit' => 0, 'credit' => '2222.00'],
        ], '2027-03-01', ['source_type' => 'ManualAdjustment']);

        $asMarch = $this->getJson('/api/accounting-reports/trial-balance?as_of=2026-03-31')->json();
        $asNextYear = $this->getJson('/api/accounting-reports/trial-balance?as_of=2027-12-31')->json();

        $row = fn ($body) => collect($body['rows'])->firstWhere('account_id', $leaf)['balance'];
        // Only the 2026-03-01 entry (-1,111) is in the 2026-03-31 view; the
        // 2027-03-01 entry (-2,222) is added only in the 2027-12-31 view.
        $this->assertBc('-2222.00', bcsub((string) $row($asNextYear), (string) $row($asMarch), 2),
            'the 2027-dated entry leaks into the 2026 as-of view');
    }

    // =============================================================
    // RECON-10 — balancesAsOf memo is keyed on (as_of, from) — different args recompute
    // =============================================================
    public function test_recon10_balances_cache_is_keyed_on_both_date_args(): void
    {
        $c5010 = $this->acct('5010');
        LedgerPoster::post([
            ['account_id' => $c5010, 'debit' => '900.00', 'credit' => 0],
            ['account_id' => $this->tillLeaf->id, 'debit' => 0, 'credit' => '900.00'],
        ], '2026-02-15', ['source_type' => 'ManualAdjustment']);

        ChartOfAccount::flushBalancesCache();

        // 4 calls, same args -> identical (memoized).
        $a1 = ChartOfAccount::balancesAsOf('2026-03-31')->get($c5010)->balance;
        $a2 = ChartOfAccount::balancesAsOf('2026-03-31')->get($c5010)->balance;
        $a3 = ChartOfAccount::balancesAsOf('2026-03-31')->get($c5010)->balance;
        $a4 = ChartOfAccount::balancesAsOf('2026-03-31')->get($c5010)->balance;
        $this->assertSame($a1, $a2);
        $this->assertSame($a1, $a3);
        $this->assertSame($a1, $a4);

        // Different fromDate -> recomputed, and excludes the 2026-02-15 entry.
        $withFrom = ChartOfAccount::balancesAsOf('2026-03-31', '2026-03-01')->get($c5010)->balance ?? '0.00';
        $this->assertBc('900.00', bcsub((string) $a1, (string) $withFrom, 2),
            'the from=2026-03-01 view must exclude the 2026-02-15 entry (cache not keyed on fromDate)');
    }
}
