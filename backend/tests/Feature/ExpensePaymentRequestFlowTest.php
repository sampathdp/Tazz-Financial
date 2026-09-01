<?php

namespace Tests\Feature;

use App\Models\ApprovalType;
use App\Models\CashAccount;
use App\Models\ChartOfAccount;
use App\Models\Expense;
use App\Models\PaymentVoucher;
use App\Models\User;
use App\Support\ExpensePaymentExecutor;
use App\Support\FinanceRequestService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 4 — an expense is paid through the finance request flow: request ->
 * approve -> execute (money moves) -> listener writes the voucher + flips the
 * expense to Paid. Also the direct pay() path and a void. DatabaseTransactions.
 */
class ExpensePaymentRequestFlowTest extends TestCase
{
    use DatabaseTransactions;

    private Expense $expense;

    private CashAccount $till;

    private User $officer;

    private User $financeUser;

    protected function setUp(): void
    {
        parent::setUp();
        $expenseCoa = ChartOfAccount::where('account_type', 'Expense')->value('id')
            ?? $this->markTestSkipped('Chart of accounts not seeded.');

        $roleId = DB::table('roles')->where('slug', 'staff')->value('id') ?? 1;
        $branchId = DB::table('branches')->insertGetId([
            'name' => 'EXP Branch', 'code' => 'EXP' . Str::random(3), 'branch_type' => 'Branch', 'status' => 'Active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $catId = DB::table('expense_categories')->insertGetId([
            'name' => 'EXP Cat ' . Str::random(4), 'code' => 'EC' . Str::random(5), 'chart_of_account_id' => $expenseCoa,
            'status' => 'Active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->officer = User::create(['public_uuid' => (string) Str::uuid(), 'name' => 'Officer', 'email' => 'exp-o-' . Str::random(5) . '@t.local', 'password' => bcrypt('x'), 'role_id' => $roleId, 'branch_id' => $branchId, 'status' => 'Active']);
        $this->financeUser = User::create(['public_uuid' => (string) Str::uuid(), 'name' => 'Finance', 'email' => 'exp-f-' . Str::random(5) . '@t.local', 'password' => bcrypt('x'), 'role_id' => $roleId, 'branch_id' => $branchId, 'status' => 'Active']);
        ApprovalType::where('code', 'finance_request_payment')->firstOrFail()->approvers()->syncWithoutDetaching([$this->financeUser->id]);

        $this->till = CashAccount::create([
            'code' => 'EXP-CASH-' . Str::random(3), 'branch_id' => $branchId, 'account_type' => 'BranchMain',
            'name' => 'EXP Till', 'opening_balance' => 0, 'current_balance' => '500000.00', 'status' => 'Active',
        ]);

        $this->expense = Expense::create([
            'code' => 'EXP-' . Str::random(6), 'branch_id' => $branchId, 'expense_category_id' => $catId,
            'amount' => '18000.00', 'expense_date' => now()->toDateString(), 'description' => 'Stationery',
            'status' => 'Approved', 'requested_by' => $this->officer->id, 'approved_by' => $this->financeUser->id,
        ]);
    }

    private function raise(): \App\Models\FinanceRequest
    {
        return FinanceRequestService::open([
            'request_type' => 'ExpensePayment',
            'amount' => $this->expense->amount,
            'branch_id' => $this->expense->branch_id,
            'source_module' => 'Expense',
            'source_id' => $this->expense->id,
            'preferred_method' => 'Cash',
            'purpose' => "Expense payment - {$this->expense->code}",
            'requested_by' => $this->officer->id,
            'meta' => [
                'gl_source_type' => 'Expense', 'gl_source_id' => $this->expense->id,
                'offset_account_id' => $this->expense->category->chart_of_account_id,
                'payment_method' => 'Cash', 'cheque_no' => null, 'source_bank_account_id' => null,
            ],
        ])->fresh();
    }

    public function test_request_flow_pays_the_expense_and_writes_a_voucher(): void
    {
        $req = $this->raise();
        $this->assertSame('PendingApproval', $req->status);
        $this->assertSame('Approved', $this->expense->fresh()->status);

        $req = FinanceRequestService::approve($req, $this->financeUser->id);
        $req = FinanceRequestService::execute($req, ['method' => 'Cash', 'cash_account_id' => $this->till->id], $this->financeUser->id);

        $this->assertSame('Completed', $req->fresh()->status);
        $this->assertSame('Paid', $this->expense->fresh()->status);
        $this->assertSame('482000.00', (string) $this->till->fresh()->current_balance);

        $voucher = PaymentVoucher::where('expense_id', $this->expense->id)->first();
        $this->assertNotNull($voucher);
        $this->assertNotNull($voucher->finance_transaction_id);
        $this->assertNotNull($voucher->journal_entry_id);
        $this->assertSame('Expense', $voucher->journalEntry->source_type);
    }

    public function test_direct_pay_moves_money_and_writes_a_linked_voucher(): void
    {
        $voucher = ExpensePaymentExecutor::pay($this->expense, ['payment_method' => 'Cash', 'cash_account_id' => $this->till->id], $this->financeUser->id);

        $this->assertSame('Paid', $this->expense->fresh()->status);
        $this->assertSame('482000.00', (string) $this->till->fresh()->current_balance);
        $this->assertNotNull($voucher->finance_transaction_id);
    }

    public function test_voiding_a_voucher_reverses_everything(): void
    {
        $voucher = ExpensePaymentExecutor::pay($this->expense, ['payment_method' => 'Cash', 'cash_account_id' => $this->till->id], $this->financeUser->id);

        $admin = User::whereHas('role', fn ($q) => $q->where('slug', 'admin'))->first()
            ?? $this->markTestSkipped('No admin user seeded.');
        $this->actingAs($admin, 'sanctum');
        $this->deleteJson("/api/payment-vouchers/{$voucher->id}", ['void_reason' => 'wrong amount'])->assertOk();

        $this->assertSame('Voided', $voucher->fresh()->status);
        $this->assertSame('Approved', $this->expense->fresh()->status);
        $this->assertSame('500000.00', (string) $this->till->fresh()->current_balance);
        $this->assertSame('Reversed', $voucher->financeTransaction->fresh()->status);
    }

    public function test_direct_pay_is_blocked_while_a_request_is_pending(): void
    {
        $this->raise();
        $admin = User::whereHas('role', fn ($q) => $q->where('slug', 'admin'))->first()
            ?? $this->markTestSkipped('No admin user seeded.');
        $this->actingAs($admin, 'sanctum');
        $this->postJson("/api/expenses/{$this->expense->id}/pay", ['payment_method' => 'Cash', 'cash_account_id' => $this->till->id])
            ->assertStatus(422);
        $this->assertSame('500000.00', (string) $this->till->fresh()->current_balance);
    }
}
