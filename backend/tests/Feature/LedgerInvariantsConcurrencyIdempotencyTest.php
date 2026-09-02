<?php

namespace Tests\Feature;

use App\Exceptions\InsufficientFundsException;
use App\Models\CashAccount;
use App\Models\ChartOfAccount;
use App\Models\FdDeposit;
use App\Models\FinanceTransaction;
use App\Models\JournalEntry;
use App\Support\FdLedgerPoster;
use App\Support\FinancePostingService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Plan: docs/audit/test-plans/ledger-invariants.md — INV-09, INV-11.
 * Findings: docs/audit/failures.md (F-4).
 *
 * Concurrency note: this repo's test harness wraps each test in a
 * DatabaseTransactions rollback against a shared persistent DB, so fixtures
 * created here are invisible to any second real DB connection — true parallel
 * interleaving cannot be reproduced deterministically without committing
 * fixtures and doing manual teardown. INV-09 is therefore tested as the
 * *end-state* invariant the plan specifies (no double-spend, no negative
 * balance, exactly one transaction), which is order-independent. INV-10 (loan
 * lost-update, which genuinely needs parallel reads) is deferred — see report.
 */
class LedgerInvariantsConcurrencyIdempotencyTest extends TestCase
{
    use DatabaseTransactions;

    private int $branchId;

    private CashAccount $till;

    private ChartOfAccount $tillLeaf;

    private ChartOfAccount $expense;

    protected function setUp(): void
    {
        parent::setUp();
        if (! ChartOfAccount::where('code', '1100')->exists()) {
            $this->markTestSkipped('Chart of accounts not seeded.');
        }

        $this->branchId = DB::table('branches')->insertGetId([
            'name' => 'INV CC Branch', 'code' => 'ICC' . Str::random(3),
            'branch_type' => 'Branch', 'status' => 'Active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->till = CashAccount::create([
            'code' => 'ICC-CASH-' . Str::random(6), 'branch_id' => $this->branchId, 'account_type' => 'BranchMain',
            'name' => 'INV CC Till', 'opening_balance' => 0, 'current_balance' => '500000.00', 'status' => 'Active',
        ]);
        $this->tillLeaf = ChartOfAccount::forCashTill($this->till->fresh());
        $this->expense = ChartOfAccount::where('code', '5010')->firstOrFail();
    }

    // =============================================================
    // INV-09 — two 300k outflows from a 500k till: one succeeds, one is refused,
    // balance never goes negative, exactly one transaction is recorded.
    // =============================================================
    public function test_inv09_second_outflow_that_would_overdraw_is_refused_and_nothing_double_posts(): void
    {
        $spec = fn () => [
            'txn_type' => 'CASH_OUT', 'amount' => '300000.00', 'entry_date' => now()->toDateString(),
            'branch_id' => $this->branchId,
            'movements' => [['account_type' => 'cash', 'account_id' => $this->till->id, 'delta' => '-300000.00']],
            'gl_lines' => [
                ['account_id' => $this->expense->id, 'debit' => '300000.00', 'credit' => 0],
                ['account_id' => $this->tillLeaf->id, 'debit' => 0, 'credit' => '300000.00'],
            ],
            'executed_by' => 1,
        ];

        $first = FinancePostingService::execute($spec());
        $this->assertSame('200000.00', (string) $this->till->fresh()->current_balance);

        // Second attempt — whatever the interleaving, once the first has moved the
        // balance to 200,000 the second cannot succeed (200,000 - 300,000 < 0).
        try {
            FinancePostingService::execute($spec());
            $this->fail('Expected InsufficientFundsException on the second outflow.');
        } catch (InsufficientFundsException $e) {
            // expected
        }

        // Balance never negative, still exactly 200,000.
        $this->assertSame('200000.00', (string) $this->till->fresh()->current_balance);

        // Exactly one CASH_OUT finance_transactions row for this till as source.
        $count = FinanceTransaction::where('source_account_type', 'cash')
            ->where('source_account_id', $this->till->id)
            ->where('txn_type', 'CASH_OUT')->count();
        $this->assertSame(1, $count);

        // GL leaf moved by exactly -300,000 (one posting).
        ChartOfAccount::flushBalancesCache();
        $leaf = (string) (ChartOfAccount::balancesAsOf(now()->toDateString())->get($this->tillLeaf->id)->balance ?? '0.00');
        $this->assertSame(0, bccomp('-300000.00', $leaf, 2));

        $this->assertNotNull($first->id);
    }

    /**
     * INV-09 corroboration: the guard is order-independent — build the till at
     * 400,000 and fire a 250k then a 250k; first succeeds (->150,000), second
     * refused, then a 100k succeeds (->50,000). No negative, three attempts,
     * two postings.
     */
    public function test_inv09_guard_is_order_independent(): void
    {
        DB::table('cash_accounts')->where('id', $this->till->id)->update(['current_balance' => '400000.00']);

        $out = fn (string $amt) => FinancePostingService::execute([
            'txn_type' => 'CASH_OUT', 'amount' => $amt, 'entry_date' => now()->toDateString(),
            'branch_id' => $this->branchId,
            'movements' => [['account_type' => 'cash', 'account_id' => $this->till->id, 'delta' => '-' . $amt]],
            'gl_lines' => [
                ['account_id' => $this->expense->id, 'debit' => $amt, 'credit' => 0],
                ['account_id' => $this->tillLeaf->id, 'debit' => 0, 'credit' => $amt],
            ],
            'executed_by' => 1,
        ]);

        $out('250000.00');
        $this->assertSame('150000.00', (string) $this->till->fresh()->current_balance);
        try {
            $out('250000.00');
            $this->fail('expected refusal');
        } catch (InsufficientFundsException) {
        }
        $out('100000.00');
        $this->assertSame('50000.00', (string) $this->till->fresh()->current_balance);

        $this->assertSame(2, FinanceTransaction::where('source_account_id', $this->till->id)
            ->where('txn_type', 'CASH_OUT')->count());
    }

    // =============================================================
    // INV-11
    // =============================================================

    /** The idempotency-key mechanism itself: a replayed key returns the original, moves nothing twice. */
    public function test_inv11a_a_replayed_idempotency_key_posts_once(): void
    {
        $spec = [
            'txn_type' => 'CASH_OUT', 'amount' => '1500.00', 'entry_date' => now()->toDateString(),
            'branch_id' => $this->branchId,
            'movements' => [['account_type' => 'cash', 'account_id' => $this->till->id, 'delta' => '-1500.00']],
            'gl_lines' => [
                ['account_id' => $this->expense->id, 'debit' => '1500.00', 'credit' => 0],
                ['account_id' => $this->tillLeaf->id, 'debit' => 0, 'credit' => '1500.00'],
            ],
            'idempotency_key' => 'inv11-key-' . Str::random(6),
            'executed_by' => 1,
        ];

        $a = FinancePostingService::execute($spec);
        $b = FinancePostingService::execute($spec);

        $this->assertSame($a->id, $b->id);
        $this->assertSame('498500.00', (string) $this->till->fresh()->current_balance);
        $this->assertSame(1, FinanceTransaction::where('idempotency_key', $spec['idempotency_key'])->count());
    }

    /**
     * F-4: replaying a logical FD deposit (two rows, identical data — what a
     * client retry produces) posts TWICE. The idempotency key is derived from
     * the fd_deposits row id, which is fresh each time, so it protects nothing
     * at the request level.
     */
    public function test_inv11b_a_replayed_fd_deposit_should_post_once(): void
    {
        $customerId = DB::table('customers')->insertGetId([
            'public_uuid' => (string) Str::uuid(), 'branch_id' => $this->branchId, 'code' => 'C' . Str::random(7),
            'full_name' => 'INV CC Customer', 'status' => 'Active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $mk = fn () => FdDeposit::create([
            'deposit_number' => 'DEP-' . Str::random(8), 'branch_id' => $this->branchId, 'customer_id' => $customerId,
            'deposit_date' => now()->toDateString(), 'amount' => '500000.00', 'payment_method' => 'Cash',
            'status' => 'Available', 'created_by' => 1,
        ]);

        $tillBefore = (string) $this->till->fresh()->current_balance;

        FdLedgerPoster::postDepositReceived($mk());
        FdLedgerPoster::postDepositReceived($mk());   // the "retry"

        $posted = FinanceTransaction::where('source_module', 'FdDeposit')->where('branch_id', $this->branchId)->count();

        // Desired: one logical deposit -> one posting, till moved once.
        $this->assertSame(1, $posted,
            'F-4: no request-level idempotency on FD deposits — a retried deposit posts twice (Risk 6). See docs/audit/failures.md#f-4');
        $this->assertSame(bcadd($tillBefore, '500000.00', 2), (string) $this->till->fresh()->current_balance,
            'F-4: till moved twice for one logical FD deposit');
    }

    /** INV-11 event replay: re-dispatching the settled event does not re-run completion for an already-finished facility. */
    public function test_inv11c_disbursement_completion_listener_is_a_no_op_when_the_loan_is_no_longer_approved(): void
    {
        $customerId = DB::table('customers')->insertGetId([
            'public_uuid' => (string) Str::uuid(), 'branch_id' => $this->branchId, 'code' => 'C' . Str::random(7),
            'full_name' => 'INV CC Cust2', 'status' => 'Active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $loanTypeId = DB::table('loan_types')->insertGetId([
            'name' => 'INV CC Type', 'code' => 'ICT' . Str::random(3),
            'interest_method' => 'flat', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $loanId = DB::table('credit_facilities')->insertGetId([
            'public_uuid' => (string) Str::uuid(), 'code' => 'L' . Str::random(8), 'facility_type' => 'Loan',
            'branch_id' => $this->branchId, 'customer_id' => $customerId, 'principal_amount' => '100000.00',
            'interest_rate_percent' => '2.000', 'term_months' => 10, 'disbursed_amount' => '100000.00',
            'disbursed_at' => now(), 'status' => 'Active', 'approved_by' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('loan_facility_details')->insert([
            'credit_facility_id' => $loanId, 'loan_type_id' => $loanTypeId, 'interest_method' => 'flat',
            'disbursement_method' => 'Cash',
        ]);

        $request = new \App\Models\FinanceRequest([
            'request_type' => 'LoanDisbursement', 'source_module' => 'Loan', 'source_id' => $loanId,
            'status' => 'Paid', 'amount' => '100000.00',
            'meta' => ['first_installment_date' => now()->addMonth()->toDateString(), 'disbursement_method' => 'Cash'],
        ]);
        $request->id = 0;

        $schedulesBefore = DB::table('facility_schedules')->where('credit_facility_id', $loanId)->count();

        $listener = new \App\Listeners\CompleteDisbursementOnSettled();
        $listener->handle(new \App\Events\FinanceRequestSettled($request, 'paid'));
        $listener->handle(new \App\Events\FinanceRequestSettled($request, 'paid'));

        // Loan already Active (not 'Approved') -> finishLoan() returns immediately, both times.
        $this->assertSame($schedulesBefore, DB::table('facility_schedules')->where('credit_facility_id', $loanId)->count());
    }
}
