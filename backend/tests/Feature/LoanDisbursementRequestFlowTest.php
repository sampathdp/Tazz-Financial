<?php

namespace Tests\Feature;

use App\Models\ApprovalType;
use App\Models\CashAccount;
use App\Models\ChartOfAccount;
use App\Models\FinanceRequest;
use App\Models\Loan;
use App\Models\User;
use App\Support\FinanceRequestService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 3 — a loan officer raises a LoanDisbursement finance request; Finance
 * approves + executes it (money moves there); the FinanceRequestSettled
 * listener finishes the loan (schedule, Active). DatabaseTransactions.
 */
class LoanDisbursementRequestFlowTest extends TestCase
{
    use DatabaseTransactions;

    private Loan $loan;

    private CashAccount $till;

    private User $officer;

    private User $financeUser;

    protected function setUp(): void
    {
        parent::setUp();
        if (! ChartOfAccount::where('code', '1100')->exists()) {
            $this->markTestSkipped('Chart of accounts not seeded.');
        }

        $roleId = DB::table('roles')->where('slug', 'staff')->value('id') ?? 1;
        $branchId = DB::table('branches')->insertGetId([
            'name' => 'LDR Branch', 'code' => 'LDR' . Str::random(3), 'branch_type' => 'Branch', 'status' => 'Active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $customerId = DB::table('customers')->insertGetId([
            'public_uuid' => (string) Str::uuid(), 'branch_id' => $branchId, 'code' => 'C' . Str::random(6),
            'full_name' => 'LDR Customer', 'status' => 'Active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $loanTypeId = DB::table('loan_types')->insertGetId([
            'name' => 'LDR Type', 'code' => 'LDR' . Str::random(3), 'interest_method' => 'flat',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->officer = User::create(['public_uuid' => (string) Str::uuid(), 'name' => 'Officer', 'email' => 'ldr-o-' . Str::random(5) . '@t.local', 'password' => bcrypt('x'), 'role_id' => $roleId, 'branch_id' => $branchId, 'status' => 'Active']);
        $this->financeUser = User::create(['public_uuid' => (string) Str::uuid(), 'name' => 'Finance', 'email' => 'ldr-f-' . Str::random(5) . '@t.local', 'password' => bcrypt('x'), 'role_id' => $roleId, 'branch_id' => $branchId, 'status' => 'Active']);
        ApprovalType::where('code', 'finance_request_payment')->firstOrFail()->approvers()->syncWithoutDetaching([$this->financeUser->id]);

        $this->till = CashAccount::create([
            'code' => 'LDR-CASH-' . Str::random(3), 'branch_id' => $branchId, 'account_type' => 'BranchMain',
            'name' => 'LDR Till', 'opening_balance' => 0, 'current_balance' => '1000000.00', 'status' => 'Active',
        ]);

        $loanId = DB::table('credit_facilities')->insertGetId([
            'public_uuid' => (string) Str::uuid(), 'code' => 'L' . Str::random(8), 'facility_type' => 'Loan',
            'branch_id' => $branchId, 'customer_id' => $customerId, 'principal_amount' => '300000.00',
            'interest_rate_percent' => '2.500', 'term_months' => 10, 'status' => 'Approved', 'approved_by' => $this->financeUser->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('loan_facility_details')->insert([
            'credit_facility_id' => $loanId, 'loan_type_id' => $loanTypeId, 'interest_method' => 'flat',
            'installment_frequency' => 'monthly', 'down_payment' => 0,
        ]);
        $this->loan = Loan::findOrFail($loanId);
    }

    /** Mirrors LoanController::requestDisbursement() without the HTTP layer. */
    private function raiseRequest(): FinanceRequest
    {
        return FinanceRequestService::open([
            'request_type' => 'LoanDisbursement',
            'amount' => '300000.00',
            'branch_id' => $this->loan->branch_id,
            'source_module' => 'Loan',
            'source_id' => $this->loan->id,
            'payee_type' => 'Customer', 'payee_id' => $this->loan->customer_id, 'payee_name' => 'LDR Customer',
            'preferred_method' => 'Cash',
            'purpose' => "Loan disbursement - {$this->loan->code}",
            'requested_by' => $this->officer->id,
            'meta' => [
                'gl_source_type' => 'Loan', 'gl_source_id' => $this->loan->id,
                'offset_account_id' => ChartOfAccount::system('1100')->id,
                'first_installment_date' => now()->addMonth()->toDateString(),
                'disbursed_amount' => '300000.00', 'disbursed_at' => now()->toDateString(),
                'disbursement_method' => 'Cash', 'disbursement_bank_account_id' => null,
            ],
        ])->fresh();
    }

    public function test_request_leaves_the_loan_approved_and_pending(): void
    {
        $req = $this->raiseRequest();
        $this->assertSame('PendingApproval', $req->status);
        $this->assertSame('Approved', $this->loan->fresh()->status);
        $this->assertTrue($this->loan->fresh()->pendingDisbursementRequest()->exists());
    }

    public function test_execute_moves_money_and_the_listener_activates_the_loan(): void
    {
        $req = $this->raiseRequest();
        $req = FinanceRequestService::approve($req, $this->financeUser->id);
        $this->assertSame('ReadyForPayment', $req->status);

        $req = FinanceRequestService::execute($req, ['method' => 'Cash', 'cash_account_id' => $this->till->id], $this->financeUser->id);

        // request closed out by the listener
        $this->assertSame('Completed', $req->fresh()->status);
        // loan finished
        $loan = $this->loan->fresh();
        $this->assertSame('Active', $loan->status);
        $this->assertSame('300000.00', (string) $loan->disbursed_amount);
        $this->assertSame(10, $loan->schedules()->count());
        // money moved from the till
        $this->assertSame('700000.00', (string) $this->till->fresh()->current_balance);
        // finance transaction + loan-keyed GL entry
        $txn = $req->fresh()->financeTransaction;
        $this->assertSame('DISBURSEMENT', $txn->txnType ?? $txn->txn_type);
        $this->assertSame('Loan', $txn->journalEntry->source_type);
        $this->assertSame($loan->id, (int) $txn->journalEntry->source_id);
    }

    public function test_direct_disburse_is_blocked_while_a_request_is_pending(): void
    {
        $this->raiseRequest();
        $admin = User::whereHas('role', fn ($q) => $q->where('slug', 'admin'))->first()
            ?? $this->markTestSkipped('No admin user seeded.');
        $this->actingAs($admin, 'sanctum');
        $res = $this->postJson("/api/loans/{$this->loan->id}/disburse", [
            'first_installment_date' => now()->addMonth()->toDateString(),
            'disbursement_method' => 'Cash',
        ]);
        $res->assertStatus(422);
        $this->assertStringContainsString('already awaiting finance', $res->json('message'));
    }
}
