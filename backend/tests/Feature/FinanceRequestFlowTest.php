<?php

namespace Tests\Feature;

use App\Exceptions\FinancePostingException;
use App\Models\ApprovalTier;
use App\Models\ApprovalType;
use App\Models\CashAccount;
use App\Models\ChartOfAccount;
use App\Models\FinanceRequest;
use App\Models\User;
use App\Support\FinanceRequestService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 2 — the finance_request lifecycle: open -> submit -> approve (routed
 * by amount tier) -> execute -> Paid, plus reject and self-approval / self-
 * execution guards. DatabaseTransactions, rolls back.
 */
class FinanceRequestFlowTest extends TestCase
{
    use DatabaseTransactions;

    private User $requester;

    private User $approver;

    private int $branchId;

    private CashAccount $till;

    private int $expenseAccountId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->expenseAccountId = ChartOfAccount::where('account_type', 'Expense')->value('id')
            ?? $this->markTestSkipped('Chart of accounts not seeded.');

        $roleId = DB::table('roles')->where('slug', 'staff')->value('id')
            ?? DB::table('roles')->insertGetId(['name' => 'T Staff ' . Str::random(4), 'slug' => 'tstaff_' . Str::random(4), 'is_system' => 0, 'can_view_all_branches' => 0, 'created_at' => now(), 'updated_at' => now()]);

        $this->branchId = DB::table('branches')->insertGetId([
            'name' => 'FR Test Branch', 'code' => 'FRT' . Str::random(3), 'branch_type' => 'Branch', 'status' => 'Active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->requester = User::create([
            'public_uuid' => (string) Str::uuid(), 'name' => 'FR Requester', 'email' => 'fr-req-' . Str::random(6) . '@t.local',
            'password' => bcrypt('x'), 'role_id' => $roleId, 'branch_id' => $this->branchId, 'status' => 'Active',
        ]);
        $this->approver = User::create([
            'public_uuid' => (string) Str::uuid(), 'name' => 'FR Approver', 'email' => 'fr-app-' . Str::random(6) . '@t.local',
            'password' => bcrypt('x'), 'role_id' => $roleId, 'branch_id' => $this->branchId, 'status' => 'Active',
        ]);
        ApprovalType::where('code', 'finance_request_payment')->firstOrFail()
            ->approvers()->syncWithoutDetaching([$this->approver->id]);

        $this->till = CashAccount::create([
            'code' => 'FRT-CASH-' . Str::random(3), 'branch_id' => $this->branchId, 'account_type' => 'BranchMain',
            'name' => 'FR Test Till', 'opening_balance' => 0, 'current_balance' => '1000000.00', 'status' => 'Active',
        ]);
    }

    private function open(string $amount): FinanceRequest
    {
        return FinanceRequestService::open([
            'request_type' => 'SupplierPayment',
            'amount' => $amount,
            'branch_id' => $this->branchId,
            'payee_name' => 'Acme Supplies',
            'purpose' => 'Stationery',
            'requested_by' => $this->requester->id,
        ]);
    }

    public function test_open_submits_and_routes_for_one_approval_by_default(): void
    {
        $req = $this->open('20000.00');

        $this->assertSame('PendingApproval', $req->status);
        $this->assertNotNull($req->approval_request_id);
        $this->assertSame(1, $req->approvalRequest->required_approvals);
        $this->assertStringStartsWith('FRT', $req->code);
    }

    public function test_a_matching_amount_tier_raises_the_approval_count(): void
    {
        ApprovalTier::create([
            'approval_type_id' => ApprovalType::where('code', 'finance_request_payment')->value('id'),
            'branch_id' => null, 'min_amount' => '100000.00', 'max_amount' => null, 'required_approvals' => 2,
        ]);

        $small = $this->open('50000.00');
        $big = $this->open('250000.00');

        $this->assertSame(1, $small->approvalRequest->required_approvals);
        $this->assertSame(2, $big->approvalRequest->required_approvals);
    }

    public function test_requester_cannot_approve_own_request(): void
    {
        $req = FinanceRequestService::open([
            'request_type' => 'SupplierPayment', 'amount' => '15000.00', 'branch_id' => $this->branchId,
            'payee_name' => 'X', 'requested_by' => $this->approver->id,
        ]);

        $this->expectException(FinancePostingException::class);
        FinanceRequestService::approve($req, $this->approver->id);
    }

    public function test_full_happy_path_releases_money(): void
    {
        $req = $this->open('30000.00');

        $req = FinanceRequestService::approve($req, $this->approver->id);
        $this->assertSame('ReadyForPayment', $req->status);

        $req = FinanceRequestService::execute($req, [
            'method' => 'Cash',
            'cash_account_id' => $this->till->id,
            'offset_account_id' => $this->expenseAccountId,
        ], $this->approver->id);

        $this->assertSame('Paid', $req->status);
        $this->assertNotNull($req->finance_transaction_id);
        $this->assertSame('970000.00', (string) $this->till->fresh()->current_balance);
        $this->assertSame('CASH_OUT', $req->financeTransaction->txn_type);
    }

    public function test_reject_closes_the_request(): void
    {
        $req = $this->open('12000.00');
        $req = FinanceRequestService::reject($req, $this->approver->id, 'no budget');

        $this->assertSame('Rejected', $req->status);
        $this->assertSame('no budget', $req->reject_reason);
        $this->assertSame('Rejected', $req->approvalRequest->status);
    }
}
