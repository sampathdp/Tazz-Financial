<?php

namespace Tests\Feature;

use App\Models\CashAccount;
use App\Models\ChartOfAccount;
use App\Models\FacilitySchedule;
use App\Models\FinanceTransaction;
use App\Models\Loan;
use App\Models\User;
use App\Support\LoanLedgerPoster;
use App\Support\LoanPaymentAllocator;
use App\Support\LoanSummary;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Plan: docs/audit/test-plans/loans.md
 * Findings: docs/audit/failures.md
 *
 * Persistent DB + DatabaseTransactions rollback -> baseline->delta on shared
 * control accounts; absolute on the per-test till leaf. Every figure is
 * hand-computed in the test body.
 */
class LoanLedgerTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private int $branchId;

    private int $customerId;

    private CashAccount $till;

    private ChartOfAccount $tillLeaf;

    protected function setUp(): void
    {
        parent::setUp();
        if (! ChartOfAccount::where('code', '1100')->exists()) {
            $this->markTestSkipped('Chart of accounts not seeded.');
        }
        $this->admin = User::whereHas('role', fn ($q) => $q->where('slug', 'admin'))->first()
            ?? $this->markTestSkipped('No admin user seeded.');

        $this->branchId = DB::table('branches')->insertGetId([
            'name' => 'LN Branch', 'code' => 'LN' . Str::random(3),
            'branch_type' => 'Branch', 'status' => 'Active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->customerId = DB::table('customers')->insertGetId([
            'public_uuid' => (string) Str::uuid(), 'branch_id' => $this->branchId, 'code' => 'C' . Str::random(7),
            'full_name' => 'LN Customer', 'status' => 'Active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->till = CashAccount::create([
            'code' => 'LN-CASH-' . Str::random(6), 'branch_id' => $this->branchId, 'account_type' => 'BranchMain',
            'name' => 'LN Till', 'opening_balance' => 0, 'current_balance' => '10000000.00', 'status' => 'Active',
        ]);
        $this->tillLeaf = ChartOfAccount::forCashTill($this->till->fresh());

        $this->actingAs($this->admin, 'sanctum');
    }

    // ---------------- helpers ----------------

    private function loanType(array $o = []): int
    {
        return DB::table('loan_types')->insertGetId(array_merge([
            'name' => 'LN Type', 'code' => 'LT' . Str::random(4),
            'interest_method' => 'flat', 'rate_period' => 'monthly',
            'grace_period_days' => 5, 'default_charge_rate_percent' => '0.000', 'processing_fee_percent' => '0.000',
            'commission_type' => 'fixed', 'commission_value' => '0.00', 'status' => 'Active',
            'created_at' => now(), 'updated_at' => now(),
        ], $o));
    }

    /** Creates an Approved (not yet disbursed) loan. */
    private function approvedLoan(array $facility, array $detail): Loan
    {
        $loanTypeId = $detail['loan_type_id'] ?? $this->loanType([
            'interest_method' => $detail['interest_method'] ?? 'flat',
            'default_charge_rate_percent' => $detail['default_charge_rate_percent'] ?? '0.000',
        ]);
        $id = DB::table('credit_facilities')->insertGetId(array_merge([
            'public_uuid' => (string) Str::uuid(), 'code' => 'L' . Str::random(9), 'facility_type' => 'Loan',
            'branch_id' => $this->branchId, 'customer_id' => $this->customerId,
            'interest_rate_percent' => '2.000', 'term_months' => 10, 'rate_period' => 'monthly',
            'status' => 'Approved', 'approved_by' => $this->admin->id,
            'created_at' => now(), 'updated_at' => now(),
        ], $facility));
        DB::table('loan_facility_details')->insert(array_merge([
            'credit_facility_id' => $id, 'loan_type_id' => $loanTypeId,
            'interest_method' => $detail['interest_method'] ?? 'flat',
            'installment_frequency' => 'monthly', 'down_payment' => '0.00',
        ], array_diff_key($detail, ['default_charge_rate_percent' => 1, 'loan_type_id' => 1])));

        return Loan::findOrFail($id);
    }

    private function disburse(Loan $loan, string $method = 'Cash', ?string $firstInst = '2026-04-01', ?string $disbursedAt = '2026-03-01', ?int $bankAccountId = null): Loan
    {
        $payload = [
            'first_installment_date' => $firstInst,
            'disbursement_method' => $method,
            'disbursed_at' => $disbursedAt,
        ];
        if ($bankAccountId) {
            $payload['disbursement_bank_account_id'] = $bankAccountId;
        }
        $this->postJson("/api/loans/{$loan->id}/disburse", $payload)->assertStatus(200);

        return $loan->fresh();
    }

    private function glBalance(int $accountId): string
    {
        ChartOfAccount::flushBalancesCache();
        $r = ChartOfAccount::balancesAsOf(now()->addYears(5)->toDateString())->get($accountId);

        return $r ? (string) $r->balance : '0.00';
    }

    private function acct(string $code): int
    {
        return ChartOfAccount::where('code', $code)->value('id');
    }

    private function linesFor(string $sourceType, int $sourceId): array
    {
        $rows = DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.source_type', $sourceType)->where('journal_entries.source_id', $sourceId)
            ->get(['journal_lines.account_id', 'journal_lines.debit', 'journal_lines.credit']);
        $out = [];
        foreach ($rows as $r) {
            $out[$r->account_id] = ['debit' => (string) $r->debit, 'credit' => (string) $r->credit];
        }

        return $out;
    }

    private function schedule(int $loanId): \Illuminate\Support\Collection
    {
        return DB::table('facility_schedules')->where('credit_facility_id', $loanId)->orderBy('installment_no')->get();
    }

    private function assertBc(string $e, string $a, string $m = ''): void
    {
        $this->assertSame(0, bccomp($e, $a, 2), $m ?: "expected {$e} got {$a}");
    }

    // =============================================================
    // LOAN-01 — flat schedule, clean division
    // =============================================================
    public function test_loan01_flat_schedule_clean_division(): void
    {
        // financed 100,000 ; flat 2%/mo ; 10 mo
        // totalInterest = 100,000 * 2/100 * 10 = 20,000
        // principalPer = round(100,000/10,2) = 10,000 ; interestPer = round(20,000/10,2) = 2,000
        $loan = $this->approvedLoan(
            ['principal_amount' => '100000.00', 'interest_rate_percent' => '2.000', 'term_months' => 10],
            ['interest_method' => 'flat'],
        );
        $this->disburse($loan);

        $rows = $this->schedule($loan->id);
        $this->assertCount(10, $rows);
        foreach ($rows as $r) {
            $this->assertBc('10000.00', (string) $r->principal_due);
            $this->assertBc('2000.00', (string) $r->interest_due);
            $this->assertBc('12000.00', (string) $r->total_due);
        }
        $this->assertBc('100000.00', (string) $rows->sum('principal_due'));
        $this->assertBc('20000.00', (string) $rows->sum('interest_due'));
        $this->assertBc('120000.00', (string) $rows->sum('total_due'));

        $this->assertSame('2026-04-01', Carbon::parse($rows->first()->due_date)->toDateString());
        $this->assertSame('2027-01-01', Carbon::parse($rows->last()->due_date)->toDateString());
    }

    // =============================================================
    // LOAN-02 — flat schedule, awkward division, sum still exact
    // =============================================================
    public function test_loan02_flat_schedule_awkward_division_sum_exact(): void
    {
        // financed 100,000 ; flat 1.5%/mo ; 7 mo
        // totalInterest = 100,000 * 0.015 * 7 = 10,500
        // principalPer = round(100,000/7,2) = 14,285.71 ; interestPer = round(10,500/7,2) = 1,500.00
        // row 7 principal = round(100,000 - 6*14,285.71, 2) = round(14,285.74, 2) = 14,285.74
        $loan = $this->approvedLoan(
            ['principal_amount' => '100000.00', 'interest_rate_percent' => '1.500', 'term_months' => 7],
            ['interest_method' => 'flat'],
        );
        $this->disburse($loan);
        $rows = $this->schedule($loan->id);

        foreach ($rows->take(6) as $r) {
            $this->assertBc('14285.71', (string) $r->principal_due);
            $this->assertBc('1500.00', (string) $r->interest_due);
        }
        $this->assertBc('14285.74', (string) $rows->last()->principal_due);
        $this->assertBc('1500.00', (string) $rows->last()->interest_due);

        $this->assertBc('100000.00', (string) $rows->sum('principal_due'));
        $this->assertBc('10500.00', (string) $rows->sum('interest_due'));
        $this->assertBc('110500.00', (string) $rows->sum('total_due'));
    }

    // =============================================================
    // LOAN-03 — reducing-balance schedule
    // =============================================================
    public function test_loan03_reducing_balance_schedule(): void
    {
        // P 12,000 ; reducing ; 1%/mo ; 3 mo ; installment ~ 4,080.2654
        // r1: interest round(12,000*0.01,2)=120.00 ; principal round(4080.2654-120,2)=3960.27 ; bal 8039.73
        // r2: interest round(8039.73*0.01,2)=80.40 ; principal round(4080.2654-80.40,2)=3999.87 ; bal 4039.86
        // r3: interest round(4039.86*0.01,2)=40.40 ; principal round(bal,2)=4039.86
        $loan = $this->approvedLoan(
            ['principal_amount' => '12000.00', 'interest_rate_percent' => '1.000', 'term_months' => 3],
            ['interest_method' => 'reducing_balance'],
        );
        $this->disburse($loan);
        $rows = $this->schedule($loan->id)->values();

        $this->assertBc('3960.27', (string) $rows[0]->principal_due);
        $this->assertBc('120.00', (string) $rows[0]->interest_due);
        $this->assertBc('3999.87', (string) $rows[1]->principal_due);
        $this->assertBc('80.40', (string) $rows[1]->interest_due);
        $this->assertBc('4039.86', (string) $rows[2]->principal_due);
        $this->assertBc('40.40', (string) $rows[2]->interest_due);

        $this->assertBc('12000.00', (string) $rows->sum('principal_due'));
        $this->assertBc('240.80', (string) $rows->sum('interest_due'));
        $this->assertBc('12240.80', (string) $rows->sum('total_due'));
    }

    // =============================================================
    // LOAN-04 — disbursement, Cash
    // =============================================================
    public function test_loan04_disbursement_cash(): void
    {
        $c1100 = $this->acct('1100');
        $before1100 = $this->glBalance($c1100);
        $tillCashBefore = (string) $this->till->fresh()->current_balance;

        $loan = $this->approvedLoan(
            ['principal_amount' => '100000.00', 'interest_rate_percent' => '2.000', 'term_months' => 10],
            ['interest_method' => 'flat'],
        );
        $loan = $this->disburse($loan, 'Cash');

        $lines = $this->linesFor('Loan', $loan->id);
        $this->assertBc('100000.00', $lines[$c1100]['debit']);
        $this->assertBc('100000.00', $lines[$this->tillLeaf->id]['credit']);

        $this->assertBc('100000.00', bcsub($this->glBalance($c1100), $before1100, 2));
        $this->assertBc(bcsub($tillCashBefore, '100000.00', 2), (string) $this->till->fresh()->current_balance);
        $this->assertBc('-100000.00', $this->glBalance($this->tillLeaf->id));

        $ftx = FinanceTransaction::where('source_module', 'Loan')->where('source_id', $loan->id)->first();
        $this->assertNotNull($ftx);
        $this->assertSame('DISBURSEMENT', $ftx->txn_type);
        $this->assertSame('loan-disbursement-' . $loan->id, $ftx->idempotency_key);

        $loan->refresh();
        $this->assertSame('Active', $loan->status);
        $this->assertBc('100000.00', (string) $loan->outstanding_balance_cache);
    }

    // =============================================================
    // LOAN-05 — disbursement, Bank
    // =============================================================
    public function test_loan05_disbursement_bank(): void
    {
        $bankRowId = DB::table('banks')->insertGetId(['name' => 'LN Bank', 'created_at' => now(), 'updated_at' => now()]);
        $bank = \App\Models\BankAccount::create([
            'branch_id' => $this->branchId, 'bank_id' => $bankRowId, 'name' => 'LN Bank Acc',
            'account_number' => 'ACC' . Str::random(10), 'account_type' => 'Current', 'currency' => 'LKR',
            'opening_balance' => 0, 'current_balance' => '1000000.00', 'status' => 'Active',
        ]);
        $bankLeaf = ChartOfAccount::forBankAccount($bank->fresh());
        $c1100 = $this->acct('1100');

        $loan = $this->approvedLoan(
            ['principal_amount' => '100000.00', 'interest_rate_percent' => '2.000', 'term_months' => 10],
            ['interest_method' => 'flat'],
        );
        $loan = $this->disburse($loan, 'Bank', bankAccountId: $bank->id);

        $lines = $this->linesFor('Loan', $loan->id);
        $this->assertBc('100000.00', $lines[$c1100]['debit']);
        $this->assertBc('100000.00', $lines[$bankLeaf->id]['credit']);
        $this->assertBc('900000.00', (string) $bank->fresh()->current_balance);
    }

    // =============================================================
    // LOAN-07 — Bank-method instalment payment posts Dr 1030 / Cr split (F-8 fixed)
    // =============================================================
    public function test_loan07_bank_method_payment_posts_to_1030_undeposited_funds(): void
    {
        $c1100 = $this->acct('1100');
        $c4010 = $this->acct('4010');
        $c1030 = $this->acct('1030');

        $loan = $this->approvedLoan(
            ['principal_amount' => '100000.00', 'interest_rate_percent' => '2.000', 'term_months' => 10],
            ['interest_method' => 'flat'],
        );
        $loan = $this->disburse($loan);
        $before1100 = $this->glBalance($c1100);
        $before4010 = $this->glBalance($c4010);
        $before1030 = $this->glBalance($c1030);

        $this->postJson("/api/loans/{$loan->id}/payments", [
            'amount' => 12000, 'payment_method' => 'Bank', 'paid_at' => '2026-04-01',
        ])->assertStatus(201);

        $inst1 = $this->schedule($loan->id)->first();
        $this->assertSame('Paid', $inst1->status);
        $this->assertBc('12000.00', (string) $inst1->paid_amount);

        // Dr 1030 12,000 / Cr 1100 10,000 / Cr 4010 2,000
        $payment = DB::table('facility_payments')->where('credit_facility_id', $loan->id)->latest('id')->first();
        $lines = $this->linesFor('LoanPayment', $payment->id);
        $this->assertBc('12000.00', $lines[$c1030]['debit'], 'Dr 1030 undeposited funds');
        $this->assertBc('10000.00', $lines[$c1100]['credit'], 'Cr 1100 principal');
        $this->assertBc('2000.00', $lines[$c4010]['credit'], 'Cr 4010 interest');

        $this->assertBc('-10000.00', bcsub($this->glBalance($c1100), $before1100, 2), '1100 relieved');
        $this->assertBc('2000.00', bcsub($this->glBalance($c4010), $before4010, 2), '4010 recognised');
        $this->assertBc('12000.00', bcsub($this->glBalance($c1030), $before1030, 2), '1030 holds the receipt until banked');
    }

    // =============================================================
    // LOAN-08 — Cash payment: allocation order + exact split
    // =============================================================
    public function test_loan08_cash_payment_allocation_order_and_split(): void
    {
        // A regular Cash payment computes penalty as-of Carbon::today() (NOT paid_at
        // — LoanPaymentController::store:190 passes no $asOf). Freeze time so the
        // hand computation below is deterministic.
        $this->travelTo(Carbon::parse('2026-05-20 10:00:00'));

        $c1100 = $this->acct('1100');
        $c4010 = $this->acct('4010');
        $c4030 = $this->acct('4030');

        $loan = $this->approvedLoan(
            ['principal_amount' => '100000.00', 'interest_rate_percent' => '2.000', 'term_months' => 10],
            ['interest_method' => 'flat', 'default_charge_rate_percent' => '3.000'],
        );
        $loan = $this->disburse($loan, 'Cash', firstInst: '2026-04-01', disbursedAt: '2026-03-01');

        $before1100 = $this->glBalance($c1100);
        $before4010 = $this->glBalance($c4010);
        $before4030 = $this->glBalance($c4030);
        $tillCashBefore = (string) $this->till->fresh()->current_balance;

        // Pay 20,000 Cash as-of 2026-05-20. Instalments 1 (due 2026-04-01) and 2 (due 2026-05-01) overdue.
        // dailyBase per instalment = (10,000 + 2,000) * 3/100 = 360
        // penalty1: chargeStart 2026-04-06. Apr 06->May 01 = 25d /30 -> 300.00 ; May 01->May 20 = 19d /31 -> round(360/31*19,2)=220.65 ; total 520.65
        // penalty2: chargeStart 2026-05-06. May 06->May 20 = 14d /31 -> round(360/31*14,2)=162.58
        // waterfall: I1 int 2,000 ; I1 pen 520.65 ; I1 pri 10,000 ; I2 int 2,000 ; I2 pen 162.58 ; I2 pri min(5,316.77,10,000)=5,316.77
        // totals: principal 15,316.77 ; interest 4,000.00 ; penalty 683.23 ; sum 20,000.00
        $this->postJson("/api/loans/{$loan->id}/payments", [
            'amount' => 20000, 'payment_method' => 'Cash', 'paid_at' => '2026-05-20',
        ])->assertStatus(201);

        $payment = DB::table('facility_payments')->where('credit_facility_id', $loan->id)->latest('id')->first();
        $lines = $this->linesFor('LoanPayment', $payment->id);

        $this->assertBc('20000.00', $lines[$this->tillLeaf->id]['debit'], 'Dr till 20,000');
        $this->assertBc('15316.77', $lines[$c1100]['credit'], 'Cr 1100 principal');
        $this->assertBc('4000.00', $lines[$c4010]['credit'], 'Cr 4010 interest');
        $this->assertBc('683.23', $lines[$c4030]['credit'], 'Cr 4030 penalty');

        $this->assertBc('4000.00', bcsub($this->glBalance($c4010), $before4010, 2));
        $this->assertBc('683.23', bcsub($this->glBalance($c4030), $before4030, 2));
        $this->assertBc('-15316.77', bcsub($this->glBalance($c1100), $before1100, 2));
        $this->assertBc(bcadd($tillCashBefore, '20000.00', 2), (string) $this->till->fresh()->current_balance);

        $rows = $this->schedule($loan->id)->values();
        $this->assertSame('Paid', $rows[0]->status);
        $this->assertBc('12000.00', (string) $rows[0]->paid_amount);
        $this->assertSame('Partial', $rows[1]->status);
        $this->assertBc('7316.77', (string) $rows[1]->paid_amount); // 2,000 interest + 5,316.77 principal
    }

    // =============================================================
    // LOAN-09 — partial payment: waterfall stops mid-bucket
    // =============================================================
    public function test_loan09_partial_payment_waterfall_stops(): void
    {
        $c1100 = $this->acct('1100');
        $c4010 = $this->acct('4010');
        $c4030 = $this->acct('4030');

        $loan = $this->approvedLoan(
            ['principal_amount' => '100000.00', 'interest_rate_percent' => '2.000', 'term_months' => 10],
            ['interest_method' => 'flat', 'default_charge_rate_percent' => '3.000'],
        );
        $loan = $this->disburse($loan);
        $before1100 = $this->glBalance($c1100);

        // Pay 2,500 as-of 2026-05-20: I1 interest 2,000 -> pen min(500, 520.65) = 500.00. Nothing to principal.
        $this->postJson("/api/loans/{$loan->id}/payments", [
            'amount' => 2500, 'payment_method' => 'Cash', 'paid_at' => '2026-05-20',
        ])->assertStatus(201);

        $payment = DB::table('facility_payments')->where('credit_facility_id', $loan->id)->latest('id')->first();
        $lines = $this->linesFor('LoanPayment', $payment->id);
        $this->assertBc('2000.00', $lines[$c4010]['credit']);
        $this->assertBc('500.00', $lines[$c4030]['credit']);
        $this->assertArrayNotHasKey($c1100, $lines, 'no principal credited on a 2,500 partial');

        $this->assertBc('0.00', bcsub($this->glBalance($c1100), $before1100, 2), '1100 unchanged');

        $inst1 = $this->schedule($loan->id)->first();
        $this->assertSame('Partial', $inst1->status);
        $this->assertBc('2000.00', (string) $inst1->paid_amount, 'penalty is not part of paid_amount');
    }

    // =============================================================
    // LOAN-12 — grace-period edge: penalty 0 on the grace day, > 0 the day after
    // =============================================================
    public function test_loan12_grace_period_edge(): void
    {
        $loan = $this->approvedLoan(
            ['principal_amount' => '100000.00', 'interest_rate_percent' => '2.000', 'term_months' => 10],
            ['interest_method' => 'flat', 'default_charge_rate_percent' => '3.000'],
        );
        // due_date of instalment 1 -> control it: first_installment_date 2026-02-28
        $loan = $this->disburse($loan, firstInst: '2026-02-28', disbursedAt: '2026-01-28');

        /** @var FacilitySchedule $row */
        $row = FacilitySchedule::where('credit_facility_id', $loan->id)->orderBy('installment_no')->first();
        // principal_due 10,000 + interest_due 2,000, paid 0 -> overdueBalance 12,000
        // chargeStart = 2026-02-28 + 5d = 2026-03-05 ; dailyBase = 12,000 * 3/100 = 360

        $this->assertBc('0.00', (string) LoanSummary::penaltyDueForRow($row, 5, 3.0, Carbon::parse('2026-03-05')), 'grace day -> 0');
        $this->assertBc('0.00', (string) LoanSummary::penaltyDueForRow($row, 5, 3.0, Carbon::parse('2026-03-04')), 'before grace ends -> 0');
        // day after: 2026-03-05 -> 2026-03-06 = 1 day, March has 31 days -> round(360/31*1, 2) = 11.61
        $this->assertBc('11.61', (string) LoanSummary::penaltyDueForRow($row, 5, 3.0, Carbon::parse('2026-03-06')));
    }

    // =============================================================
    // LOAN-13 — penalty across a month boundary (per-month daily rate)
    // =============================================================
    public function test_loan13_penalty_across_month_boundary(): void
    {
        // Build one schedule row directly with the plan's numbers:
        // principal_due 20,000 + interest_due 5,000 -> overdueBalance 25,000 ; chargeRate 3% -> dailyBase 750
        $loan = $this->approvedLoan(
            ['principal_amount' => '100000.00', 'interest_rate_percent' => '1.000', 'term_months' => 10],
            ['interest_method' => 'flat', 'default_charge_rate_percent' => '3.000'],
        );
        $this->disburse($loan);
        $row = FacilitySchedule::where('credit_facility_id', $loan->id)->orderBy('installment_no')->first();
        $row->update(['principal_due' => '20000.00', 'interest_due' => '5000.00', 'paid_amount' => '0.00',
            'due_date' => '2026-01-25', 'total_due' => '25000.00']);
        $row->refresh();

        // chargeStart 2026-01-30 ; asOf 2026-03-10
        // Jan 30->Feb 01 = 2d /31 -> 750/31*2 = 48.387096...
        // Feb 01->Mar 01 = 28d /28 -> 750.00
        // Mar 01->Mar 10 = 9d /31 -> 750/31*9 = 217.741935...
        // total round(48.387096 + 750 + 217.741935, 2) = 1,016.13
        $this->assertBc('1016.13', (string) LoanSummary::penaltyDueForRow($row, 5, 3.0, Carbon::parse('2026-03-10')));
    }

    // =============================================================
    // LOAN-15 — early settlement: accrued-to-date interest only
    // =============================================================
    public function test_loan15_settlement_quote_accrued_interest_only(): void
    {
        $loan = $this->approvedLoan(
            ['principal_amount' => '100000.00', 'interest_rate_percent' => '2.000', 'term_months' => 10],
            ['interest_method' => 'flat', 'default_charge_rate_percent' => '3.000'],
        );
        $loan = $this->disburse($loan, firstInst: '2026-04-01', disbursedAt: '2026-03-01');
        $loan->load('schedules', 'loanDetail.loanType');

        // Settle as-of 2026-04-15. (Hand computation is in loans.md LOAN-15.)
        // I1: fully accrued -> waive 0 ; I2: accrued round(2,000*14/30,2)=933.33 -> waive 1,066.67 ; I3..10: waive 2,000 each
        // penalty (I1 only): chargeStart 2026-04-06 ; 9d /30 -> round(360/30*9,2) = 108.00
        // schedule balance = 12,000 + (12,000-1,066.67) + 8*(12,000-2,000) = 12,000 + 10,933.33 + 80,000 = 102,933.33
        // outstanding Q = round(102,933.33 + 108.00, 2) = 103,041.33
        $quote = LoanPaymentAllocator::settlementQuote($loan, Carbon::parse('2026-04-15'));

        $this->assertBc('103041.33', (string) $quote['outstanding']);

        $rows = $loan->schedules->sortBy('installment_no')->values();
        $this->assertBc('1066.67', (string) ($quote['waivedInterest'][$rows[1]->id] ?? '0'));
        for ($i = 2; $i < 10; $i++) {
            $this->assertBc('2000.00', (string) ($quote['waivedInterest'][$rows[$i]->id] ?? '0'), "row {$i} waive");
        }
        $this->assertArrayNotHasKey($rows[0]->id, $quote['waivedInterest'], 'instalment 1 fully accrued -> no waive');
    }

    // =============================================================
    // LOAN-18 — write-off over-credits 1100 with never-booked interest/penalty (F: Risk 2)
    // =============================================================
    public function test_loan18_writeoff_over_credits_1100(): void
    {
        $c1100 = $this->acct('1100');
        $c1190 = $this->acct('1190');
        $c5300 = $this->acct('5300');

        $loan = $this->approvedLoan(
            ['principal_amount' => '100000.00', 'interest_rate_percent' => '2.000', 'term_months' => 10],
            ['interest_method' => 'flat'],
        );
        $loan = $this->disburse($loan);

        // Pay instalments 1-3 (principal 30,000 collected) -> this loan's 1100 contribution = 70,000.
        $this->postJson("/api/loans/{$loan->id}/payments", ['amount' => 36000, 'payment_method' => 'Cash', 'paid_at' => '2026-06-01'])
            ->assertStatus(201);
        $loan->refresh();

        // Simulate the write-off state: cache = unpaid principal 70,000 + unpaid interest 14,000
        //   + accrued penalty 8,000 + charges 0 = 92,000 ; provision posted 45,000.
        DB::table('credit_facilities')->where('id', $loan->id)->update([
            'outstanding_balance_cache' => '92000.00', 'provision_posted_amount' => '45000.00',
        ]);
        // Post the provision that was "already expensed" (Dr 5300 / Cr 1190 45,000).
        \App\Support\LedgerPoster::post([
            ['account_id' => $c5300, 'debit' => '45000.00', 'credit' => 0],
            ['account_id' => $c1190, 'debit' => 0, 'credit' => '45000.00'],
        ], now()->toDateString(), ['source_type' => 'LoanProvision', 'source_id' => $loan->id]);

        $before1100 = $this->glBalance($c1100);
        $before5300 = $this->glBalance($c5300);
        $before1190 = $this->glBalance($c1190);

        // amount = outstanding_balance_cache = 92,000
        LoanLedgerPoster::postWriteOff($loan->fresh(), 92000.00, $this->admin->id);

        $lines = $this->linesFor('Loan', $loan->id); // write-off is source_type='Loan'
        // Current behaviour: Dr 1190 45,000 + Dr 5300 47,000 / Cr 1100 92,000
        $writeOffEntry = DB::table('journal_entries')->where('source_type', 'Loan')->where('source_id', $loan->id)
            ->where('description', 'like', '%written off%')->value('id');
        $woLines = DB::table('journal_lines')->where('journal_entry_id', $writeOffEntry)->get()->keyBy('account_id');
        $this->assertBc('45000.00', (string) $woLines[$c1190]->debit);
        $this->assertBc('47000.00', (string) $woLines[$c5300]->debit);
        $this->assertBc('92000.00', (string) $woLines[$c1100]->credit);

        // This loan only had 70,000 of principal in 1100 -> crediting 92,000 drives its contribution to -22,000.
        $this->assertBc('-92000.00', bcsub($this->glBalance($c1100), $before1100, 2),
            'write-off credits 1100 for the full all-in outstanding, not just principal on the books');

        // F (Risk 2): 5300 charged 47,000 for this write-off, but the real principal loss is only
        // 70,000 - 45,000 already provided = 25,000. Over-expensed by 22,000.
        $this->assertBc('25000.00', bcsub($this->glBalance($c5300), $before5300, 2),
            'F-Risk2: bad-debt expense over-stated by the never-booked interest/penalty portion. See docs/audit/failures.md');
    }

    // =============================================================
    // LOAN-21 — restructure rollover: no cash, Dr 1100 = Cr 4010+4030+4020
    // =============================================================
    public function test_loan21_reschedule_rollover_no_cash_income_catch_up(): void
    {
        $c1100 = $this->acct('1100');
        $c4010 = $this->acct('4010');
        $c4030 = $this->acct('4030');
        $c4020 = $this->acct('4020');

        $oldLoan = $this->approvedLoan(
            ['principal_amount' => '100000.00', 'interest_rate_percent' => '2.000', 'term_months' => 10],
            ['interest_method' => 'flat'],
        );
        $oldLoan = $this->disburse($oldLoan);
        $newLoan = $this->approvedLoan(
            ['principal_amount' => '120000.00', 'interest_rate_percent' => '2.000', 'term_months' => 12, 'code' => $oldLoan->code . '-R1'],
            ['interest_method' => 'flat'],
        );

        $tillCashBefore = (string) $this->till->fresh()->current_balance;
        $before1100 = $this->glBalance($c1100);

        // capitalisedInterest 8,000 ; retainedOd 5,000 ; rolledAndAddedCharges 2,000 ; total 15,000
        LoanLedgerPoster::postRescheduleRollover($oldLoan, $newLoan, 8000.00, 5000.00, 2000.00, '2026-09-01', $this->admin->id);

        $lines = $this->linesFor('LoanReschedule', $newLoan->id);
        $this->assertBc('15000.00', $lines[$c1100]['debit'], 'Dr 1100 total');
        $this->assertBc('8000.00', $lines[$c4010]['credit']);
        $this->assertBc('5000.00', $lines[$c4030]['credit']);
        $this->assertBc('2000.00', $lines[$c4020]['credit']);

        // Dr 1100 == Cr 4010 + Cr 4030 + Cr 4020
        $this->assertBc($lines[$c1100]['debit'],
            bcadd(bcadd($lines[$c4010]['credit'], $lines[$c4030]['credit'], 2), $lines[$c4020]['credit'], 2));

        // No cash moved, no finance_transactions row.
        $this->assertBc($tillCashBefore, (string) $this->till->fresh()->current_balance, 'no cash movement on a reschedule');
        $this->assertSame(0, FinanceTransaction::where('source_module', 'LoanReschedule')->where('source_id', $newLoan->id)->count());

        // 1100 rose by exactly the capitalised total.
        $this->assertBc('15000.00', bcsub($this->glBalance($c1100), $before1100, 2));
    }

    // =============================================================
    // LOAN-22 — payment on a fully-paid / zero-outstanding loan is rejected
    // =============================================================
    public function test_loan22_payment_on_closed_loan_rejected(): void
    {
        $loan = $this->approvedLoan(
            ['principal_amount' => '12000.00', 'interest_rate_percent' => '0.000', 'term_months' => 1],
            ['interest_method' => 'flat'],
        );
        $loan = $this->disburse($loan, firstInst: '2026-04-01', disbursedAt: '2026-03-01');

        // Pay it off (single instalment, zero interest -> 12,000).
        $this->postJson("/api/loans/{$loan->id}/payments", ['amount' => 12000, 'payment_method' => 'Cash', 'paid_at' => '2026-04-01'])
            ->assertStatus(201);
        $this->assertSame('Closed', $loan->fresh()->status);

        // Second payment -> rejected, no entry.
        $entriesBefore = DB::table('journal_entries')->count();
        $this->postJson("/api/loans/{$loan->id}/payments", ['amount' => 100, 'payment_method' => 'Cash'])
            ->assertStatus(422);
        $this->assertSame($entriesBefore, DB::table('journal_entries')->count());
    }

    // =============================================================
    // LOAN-23 — payment on WrittenOff / Restructured loan is rejected
    // =============================================================
    public function test_loan23_payment_on_non_active_loan_rejected(): void
    {
        foreach (['WrittenOff', 'Restructured', 'Cancelled'] as $status) {
            $loan = $this->approvedLoan(
                ['principal_amount' => '100000.00', 'interest_rate_percent' => '2.000', 'term_months' => 10],
                ['interest_method' => 'flat'],
            );
            $loan = $this->disburse($loan);
            DB::table('credit_facilities')->where('id', $loan->id)->update(['status' => $status]);

            $this->postJson("/api/loans/{$loan->id}/payments", ['amount' => 1000, 'payment_method' => 'Cash'])
                ->assertStatus(422);
        }
    }

    // =============================================================
    // LOAN-27 — principal waiver never hits the GL (F: Risk 8)
    // =============================================================
    public function test_loan27_principal_waiver_never_hits_the_gl(): void
    {
        $c1100 = $this->acct('1100');
        $c5300 = $this->acct('5300');

        $loan = $this->approvedLoan(
            ['principal_amount' => '100000.00', 'interest_rate_percent' => '2.000', 'term_months' => 10],
            ['interest_method' => 'flat'],
        );
        $loan = $this->disburse($loan);

        // Pay instalments 1-3 -> 1100 for this loan = 70,000 ; remaining principal due = 70,000.
        $this->postJson("/api/loans/{$loan->id}/payments", ['amount' => 36000, 'payment_method' => 'Cash', 'paid_at' => '2026-06-01'])
            ->assertStatus(201);

        $before1100 = $this->glBalance($c1100);
        $before5300 = $this->glBalance($c5300);
        $entriesBefore = DB::table('journal_entries')->count();

        $this->postJson("/api/loans/{$loan->id}/waivers", [
            'waiver_type' => 'Principal', 'amount' => 70000, 'reason' => 'goodwill',
        ])->assertStatus(201);

        // No journal entry at all.
        $this->assertSame($entriesBefore, DB::table('journal_entries')->count(),
            'F-Risk8: a Principal waiver posts NO ledger entry. See docs/audit/failures.md');

        // Outstanding drops but 1100 is untouched -> asset overstated, no bad-debt expense.
        $loan->refresh();
        LoanPaymentAllocator::outstandingBalance($loan); // recompute
        $this->assertBc('0.00', bcsub($this->glBalance($c1100), $before1100, 2), 'F-Risk8: 1100 not relieved by the principal waiver');
        $this->assertBc('70000.00', bcsub($this->glBalance($c5300), $before5300, 2),
            'F-Risk8: the 70,000 loss should hit P&L (5300 or a waiver-expense account) — it does not');
    }
}
