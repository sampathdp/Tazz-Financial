<?php

namespace Tests\Feature;

use App\Exceptions\InsufficientFundsException;
use App\Models\CashAccount;
use App\Models\ChartOfAccount;
use App\Models\FacilityPayment;
use App\Models\FinanceSetting;
use App\Models\Loan;
use App\Models\User;
use App\Support\CashSessionService;
use App\Support\FinancePostingService;
use App\Support\LoanLedgerPoster;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Plan: docs/audit/test-plans/cash-floats.md
 * Findings: docs/audit/failures.md
 *
 * Already covered by the pre-existing suite (not re-implemented here):
 *   CF-01, CF-03  -> CashFloatFlowTest
 *   CF-04, CF-05  -> FinanceTransferFlowTest
 *   CF-06 (shortage), CF-08, CF-15 -> CashSessionFlowTest
 *   CF-09 (bank side), CF-10, CF-11 -> BankTransactionFinancePostingTest
 *
 * This file covers the gaps: CF-02, CF-06 (excess), CF-07, CF-09 (till side,
 * hand-computed), CF-12 (Risk 10), CF-13, CF-14, CF-16, CF-17.
 */
class CashFloatsLedgerTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        if (! ChartOfAccount::where('code', '4905')->exists()) {
            $this->markTestSkipped('Cash over/short accounts not seeded.');
        }
        $this->admin = User::whereHas('role', fn ($q) => $q->where('slug', 'admin'))->first()
            ?? $this->markTestSkipped('No admin user seeded.');
        Model::preventLazyLoading(false); // see failures.md O-1
        $this->actingAs($this->admin, 'sanctum');
    }

    private function branch(string $tag): int
    {
        return DB::table('branches')->insertGetId([
            'name' => "CF {$tag}", 'code' => 'CF' . Str::random(4),
            'branch_type' => 'Branch', 'status' => 'Active', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function till(int $branchId, string $bal = '1000000.00', bool $allowNeg = false): CashAccount
    {
        return CashAccount::create([
            'code' => 'CF-CASH-' . Str::random(6), 'branch_id' => $branchId, 'account_type' => 'BranchMain',
            'name' => 'CF Till', 'opening_balance' => 0, 'current_balance' => $bal,
            'allow_negative' => $allowNeg, 'status' => 'Active',
        ]);
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

    private function assertBc(string $e, string $a, string $m = ''): void
    {
        $this->assertSame(0, bccomp($e, $a, 2), $m ?: "expected {$e} got {$a}");
    }

    // =============================================================
    // CF-02 — a loan Cash receipt lands in the loan's branch till, not another branch's
    // =============================================================
    public function test_cf02_loan_cash_receipt_lands_in_the_correct_branch_till(): void
    {
        $branchA = $this->branch('A');
        $branchB = $this->branch('B');
        $tillA = $this->till($branchA);
        $tillB = $this->till($branchB);
        $tillALeaf = ChartOfAccount::forCashTill($tillA->fresh());
        $tillBLeaf = ChartOfAccount::forCashTill($tillB->fresh());

        // A disbursed loan at branch B.
        $custId = DB::table('customers')->insertGetId([
            'public_uuid' => (string) Str::uuid(), 'branch_id' => $branchB, 'code' => 'C' . Str::random(7),
            'full_name' => 'CF Cust', 'status' => 'Active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $ltId = DB::table('loan_types')->insertGetId([
            'name' => 'CF LT', 'code' => 'CFL' . Str::random(3), 'interest_method' => 'flat',
            'rate_period' => 'monthly', 'status' => 'Active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $loanId = DB::table('credit_facilities')->insertGetId([
            'public_uuid' => (string) Str::uuid(), 'code' => 'L' . Str::random(9), 'facility_type' => 'Loan',
            'branch_id' => $branchB, 'customer_id' => $custId, 'principal_amount' => '100000.00',
            'interest_rate_percent' => '2.000', 'term_months' => 10, 'rate_period' => 'monthly',
            'disbursed_amount' => '100000.00', 'disbursed_at' => now(), 'status' => 'Active',
            'outstanding_balance_cache' => '100000.00', 'approved_by' => $this->admin->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('loan_facility_details')->insert([
            'credit_facility_id' => $loanId, 'loan_type_id' => $ltId, 'interest_method' => 'flat',
            'installment_frequency' => 'monthly', 'down_payment' => '0.00',
        ]);
        DB::table('facility_schedules')->insert([
            'credit_facility_id' => $loanId, 'installment_no' => 1, 'due_date' => '2026-04-01',
            'principal_due' => '10000.00', 'interest_due' => '2000.00', 'total_due' => '12000.00',
            'paid_amount' => '0.00', 'status' => 'Pending', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $scheduleId = DB::table('facility_schedules')->where('credit_facility_id', $loanId)->value('id');

        // A Cash FacilityPayment with a settled allocation (10,000 principal + 2,000 interest).
        $payment = FacilityPayment::create([
            'code' => 'PV' . Str::random(8), 'credit_facility_id' => $loanId, 'branch_id' => $branchB,
            'amount' => '12000.00', 'payment_type' => 'Regular', 'payment_method' => 'Cash',
            'status' => 'Active', 'approval_status' => 'Approved', 'received_by' => $this->admin->id, 'paid_at' => now(),
        ]);
        DB::table('facility_payment_allocations')->insert([
            'facility_payment_id' => $payment->id, 'facility_schedule_id' => $scheduleId,
            'principal_applied' => '10000.00', 'interest_applied' => '2000.00', 'penalty_applied' => '0.00',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $tillABefore = (string) $tillA->fresh()->current_balance;

        LoanLedgerPoster::postCashReceipt($payment->fresh());

        // Branch B's till moved +12,000 ; branch A's till untouched.
        $this->assertBc(bcadd((string) $tillB->fresh()->current_balance, '0.00', 2), (string) $tillB->fresh()->current_balance);
        $this->assertBc('1012000.00', (string) $tillB->fresh()->current_balance, 'branch-B till received the cash');
        $this->assertBc($tillABefore, (string) $tillA->fresh()->current_balance, 'branch-A till untouched');
        $this->assertBc('12000.00', $this->glBalance($tillBLeaf->id), 'branch-B till leaf GL +12,000');
        $this->assertBc('0.00', $this->glBalance($tillALeaf->id), 'branch-A till leaf GL untouched');
    }

    // =============================================================
    // CF-06 (excess) — cash session excess posts to 4905 and trues the till up
    // =============================================================
    public function test_cf06_cash_session_excess_posts_to_4905_and_trues_the_till(): void
    {
        $branchId = $this->branch('EX');
        $till = $this->till($branchId, '400000.00');
        $tillLeaf = ChartOfAccount::forCashTill($till->fresh());
        $c4905 = $this->acct('4905');

        $closer = User::create(['public_uuid' => (string) Str::uuid(), 'name' => 'Closer',
            'email' => 'cl' . Str::random(6) . '@t.local', 'password' => bcrypt('x'),
            'role_id' => DB::table('roles')->where('slug', 'staff')->value('id') ?? 1, 'branch_id' => $branchId, 'status' => 'Active']);

        $session = CashSessionService::open($till->id, '400000.00', $closer->id);
        // Counted 400,150 -> +150 excess
        $session = CashSessionService::close($session, '400150.00', [], '0.00', $closer->id);
        $before4905 = $this->glBalance($c4905);

        CashSessionService::verify($session->fresh(), $this->admin->id);

        // Dr till leaf 150 / Cr 4905 150 ; till trued to 400,150
        $this->assertBc('400150.00', (string) $till->fresh()->current_balance);
        $this->assertBc('150.00', $this->glBalance($tillLeaf->id), 'till leaf +150');
        $this->assertBc('150.00', bcsub($this->glBalance($c4905), $before4905, 2), '4905 +150');
    }

    // =============================================================
    // CF-07 — day-end count matches exactly: no GL entry
    // =============================================================
    public function test_cf07_exact_count_posts_no_gl(): void
    {
        $branchId = $this->branch('EQ');
        $till = $this->till($branchId, '250000.00');
        $closer = User::create(['public_uuid' => (string) Str::uuid(), 'name' => 'Closer2',
            'email' => 'c2' . Str::random(6) . '@t.local', 'password' => bcrypt('x'),
            'role_id' => DB::table('roles')->where('slug', 'staff')->value('id') ?? 1, 'branch_id' => $branchId, 'status' => 'Active']);

        $session = CashSessionService::open($till->id, '250000.00', $closer->id);
        $session = CashSessionService::close($session, '250000.00', [], '0.00', $closer->id);

        $entriesBefore = DB::table('journal_entries')->count();
        $session = CashSessionService::verify($session->fresh(), $this->admin->id);

        $this->assertSame('Verified', $session->status);
        $this->assertSame($entriesBefore, DB::table('journal_entries')->count(), 'no GL entry on a matched count');
        $this->assertBc('250000.00', (string) $till->fresh()->current_balance);
    }

    // =============================================================
    // CF-09 — negative till balance is impossible (till side, hand-computed)
    // =============================================================
    public function test_cf09_negative_till_balance_is_impossible(): void
    {
        $branchId = $this->branch('NEG');
        $till = $this->till($branchId, '1000.00', allowNeg: false);
        $tillLeaf = ChartOfAccount::forCashTill($till->fresh());
        $exp = $this->acct('5010');

        $spec = [
            'txn_type' => 'CASH_OUT', 'amount' => '5000.00', 'entry_date' => now()->toDateString(),
            'branch_id' => $branchId,
            'movements' => [['account_type' => 'cash', 'account_id' => $till->id, 'delta' => '-5000.00']],
            'gl_lines' => [
                ['account_id' => $exp, 'debit' => '5000.00', 'credit' => 0],
                ['account_id' => $tillLeaf->id, 'debit' => 0, 'credit' => '5000.00'],
            ],
            'executed_by' => $this->admin->id,
        ];

        $entriesBefore = DB::table('journal_entries')->count();
        try {
            FinancePostingService::execute($spec);
            $this->fail('expected InsufficientFundsException');
        } catch (InsufficientFundsException $e) {
            $this->assertStringContainsString('4,000.00', $e->getMessage());
        }
        $this->assertBc('1000.00', (string) $till->fresh()->current_balance, 'balance untouched');
        $this->assertSame($entriesBefore, DB::table('journal_entries')->count(), 'no entry, no Draft');

        // Contrast: an allow_negative till DOES go negative.
        $negTill = $this->till($branchId, '1000.00', allowNeg: true);
        $negLeaf = ChartOfAccount::forCashTill($negTill->fresh());
        FinancePostingService::execute([
            'txn_type' => 'CASH_OUT', 'amount' => '5000.00', 'entry_date' => now()->toDateString(),
            'branch_id' => $branchId,
            'movements' => [['account_type' => 'cash', 'account_id' => $negTill->id, 'delta' => '-5000.00']],
            'gl_lines' => [
                ['account_id' => $exp, 'debit' => '5000.00', 'credit' => 0],
                ['account_id' => $negLeaf->id, 'debit' => 0, 'credit' => '5000.00'],
            ],
            'executed_by' => $this->admin->id,
        ]);
        $this->assertBc('-4000.00', (string) $negTill->fresh()->current_balance, 'allow_negative flag is load-bearing');
    }

    // =============================================================
    // CF-12 — a Pending bank transaction still moves the balance immediately (F: Risk 10)
    // =============================================================
    public function test_cf12_pending_bank_transaction_moves_the_balance_immediately(): void
    {
        $branchId = $this->branch('PB');
        $till = $this->till($branchId, '2000000.00');
        $bankRowId = DB::table('banks')->insertGetId(['name' => 'CF Bank', 'created_at' => now(), 'updated_at' => now()]);
        $bank = \App\Models\BankAccount::create([
            'branch_id' => $branchId, 'bank_id' => $bankRowId, 'name' => 'CF Bank Acc',
            'account_number' => 'ACC' . Str::random(10), 'account_type' => 'Current', 'currency' => 'LKR',
            'opening_balance' => 0, 'current_balance' => '90000.00', 'status' => 'Active',
        ]);

        $res = $this->postJson('/api/bank-transactions', [
            'bank_account_id' => $bank->id, 'txn_date' => now()->toDateString(),
            'transaction_type' => 'Deposit', 'amount' => 500000, 'status' => 'Pending',
            'contra_kind' => 'Cash', 'contra_cash_account_id' => $till->id,
        ])->assertStatus(201);

        $this->assertSame('Pending', $res->json('status'));

        // Current behaviour (documented): the balance moves immediately.
        $this->assertBc('590000.00', (string) $bank->fresh()->current_balance);

        // Correct behaviour: an uncleared (Pending) deposit must NOT change the
        // spendable balance until it clears.
        $this->assertBc('90000.00', (string) $bank->fresh()->current_balance,
            'F-11: a Pending bank Deposit inflates current_balance before it clears (Risk 10). See docs/audit/failures.md#f-11');
    }

    // =============================================================
    // CF-13 — entry_date follows the transaction's own date, not the open session's business_date
    // =============================================================
    public function test_cf13_entry_date_follows_the_transaction_date_not_the_session(): void
    {
        $branchId = $this->branch('DB');
        $till = $this->till($branchId, '500000.00');
        $tillLeaf = ChartOfAccount::forCashTill($till->fresh());
        $exp = $this->acct('5010');

        // An open session with business_date = today.
        $closer = User::create(['public_uuid' => (string) Str::uuid(), 'name' => 'Closer3',
            'email' => 'c3' . Str::random(6) . '@t.local', 'password' => bcrypt('x'),
            'role_id' => DB::table('roles')->where('slug', 'staff')->value('id') ?? 1, 'branch_id' => $branchId, 'status' => 'Active']);
        CashSessionService::open($till->id, '500000.00', $closer->id);

        // A payment dated in a PRIOR period, recorded while today's session is open.
        FinancePostingService::execute([
            'txn_type' => 'CASH_OUT', 'amount' => '1000.00', 'entry_date' => '2025-11-15',
            'branch_id' => $branchId,
            'movements' => [['account_type' => 'cash', 'account_id' => $till->id, 'delta' => '-1000.00']],
            'gl_lines' => [
                ['account_id' => $exp, 'debit' => '1000.00', 'credit' => 0],
                ['account_id' => $tillLeaf->id, 'debit' => 0, 'credit' => '1000.00'],
            ],
            'executed_by' => $this->admin->id,
        ]);

        $entry = DB::table('journal_entries')->where('source_type', 'FinanceTransaction')->latest('id')->first();
        $this->assertSame('2025-11-15', Carbon::parse($entry->entry_date)->toDateString(),
            'entry_date follows the transaction date, not the open session business_date — no reconciliation between the two');
    }

    // =============================================================
    // CF-16 — require_cash_session gates Cash collection when enabled
    // =============================================================
    public function test_cf16_require_cash_session_setting_gates_cash_collection(): void
    {
        $branchId = $this->branch('RCS');
        $till = $this->till($branchId);

        $custId = DB::table('customers')->insertGetId([
            'public_uuid' => (string) Str::uuid(), 'branch_id' => $branchId, 'code' => 'C' . Str::random(7),
            'full_name' => 'RCS Cust', 'status' => 'Active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $ltId = DB::table('loan_types')->insertGetId([
            'name' => 'RCS LT', 'code' => 'RCS' . Str::random(3), 'interest_method' => 'flat',
            'rate_period' => 'monthly', 'status' => 'Active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $loanId = DB::table('credit_facilities')->insertGetId([
            'public_uuid' => (string) Str::uuid(), 'code' => 'L' . Str::random(9), 'facility_type' => 'Loan',
            'branch_id' => $branchId, 'customer_id' => $custId, 'principal_amount' => '100000.00',
            'interest_rate_percent' => '2.000', 'term_months' => 10, 'rate_period' => 'monthly',
            'disbursed_amount' => '100000.00', 'disbursed_at' => now(), 'status' => 'Active',
            'outstanding_balance_cache' => '100000.00', 'approved_by' => $this->admin->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('loan_facility_details')->insert([
            'credit_facility_id' => $loanId, 'loan_type_id' => $ltId, 'interest_method' => 'flat',
            'installment_frequency' => 'monthly', 'down_payment' => '0.00',
        ]);
        DB::table('facility_schedules')->insert([
            'credit_facility_id' => $loanId, 'installment_no' => 1, 'due_date' => '2026-04-01',
            'principal_due' => '10000.00', 'interest_due' => '2000.00', 'total_due' => '12000.00',
            'paid_amount' => '0.00', 'status' => 'Pending', 'created_at' => now(), 'updated_at' => now(),
        ]);

        FinanceSetting::put('require_cash_session', '1', $this->admin->id);

        // No session open -> 422.
        $this->postJson("/api/loans/{$loanId}/payments", ['amount' => 1000, 'payment_method' => 'Cash'])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => "Open today's cash session for {$till->name} before recording cash payments."]);

        // Default (setting off) -> the same payment is accepted with no session.
        FinanceSetting::put('require_cash_session', '0', $this->admin->id);
        $this->postJson("/api/loans/{$loanId}/payments", ['amount' => 1000, 'payment_method' => 'Cash'])
            ->assertStatus(201);
    }

    // =============================================================
    // CF-17 — multiple tills at one branch don't share balances
    // =============================================================
    public function test_cf17_multiple_tills_at_one_branch_do_not_share_balances(): void
    {
        $branchId = $this->branch('MT');
        $primary = $this->till($branchId, '400000.00'); // BranchMain
        $vault = CashAccount::create([
            'code' => 'CF-VAULT-' . Str::random(5), 'branch_id' => $branchId, 'account_type' => 'CollectionCash',
            'name' => 'CF Vault', 'opening_balance' => 0, 'current_balance' => '900000.00', 'status' => 'Active',
        ]);
        $vaultLeaf = ChartOfAccount::forCashTill($vault->fresh());
        $exp = $this->acct('5010');

        // branchPrimary() must resolve to the BranchMain, not the Vault.
        $this->assertSame($primary->id, CashAccount::branchPrimary($branchId)->id);

        // Move money on the Vault only.
        FinancePostingService::execute([
            'txn_type' => 'CASH_OUT', 'amount' => '25000.00', 'entry_date' => now()->toDateString(),
            'branch_id' => $branchId,
            'movements' => [['account_type' => 'cash', 'account_id' => $vault->id, 'delta' => '-25000.00']],
            'gl_lines' => [
                ['account_id' => $exp, 'debit' => '25000.00', 'credit' => 0],
                ['account_id' => $vaultLeaf->id, 'debit' => 0, 'credit' => '25000.00'],
            ],
            'executed_by' => $this->admin->id,
        ]);

        $this->assertBc('875000.00', (string) $vault->fresh()->current_balance);
        $this->assertBc('400000.00', (string) $primary->fresh()->current_balance, 'primary till untouched by a Vault movement');
    }
}
