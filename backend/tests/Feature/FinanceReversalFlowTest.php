<?php

namespace Tests\Feature;

use App\Models\ApprovalType;
use App\Models\CashAccount;
use App\Models\ChartOfAccount;
use App\Models\FinanceAuditEvent;
use App\Models\FinanceSetting;
use App\Models\FinanceTransaction;
use App\Models\User;
use App\Support\FinancePostingService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 8 — the finance_reversal maker-checker + the audit trail.
 * DatabaseTransactions.
 */
class FinanceReversalFlowTest extends TestCase
{
    use DatabaseTransactions;

    private CashAccount $till;

    private User $admin;

    private User $approver;

    private int $expenseAccountId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->expenseAccountId = ChartOfAccount::where('account_type', 'Expense')->value('id')
            ?? $this->markTestSkipped('Chart of accounts not seeded.');
        $this->admin = User::whereHas('role', fn ($q) => $q->where('slug', 'admin'))->first()
            ?? $this->markTestSkipped('No admin user seeded.');

        $roleId = DB::table('roles')->where('slug', 'staff')->value('id') ?? 1;
        $branchId = DB::table('branches')->insertGetId(['name' => 'RV', 'code' => 'RV' . Str::random(3), 'branch_type' => 'Branch', 'status' => 'Active', 'created_at' => now(), 'updated_at' => now()]);
        $this->approver = User::create(['public_uuid' => (string) Str::uuid(), 'name' => 'Rev App', 'email' => 'rv-' . Str::random(5) . '@t.local', 'password' => bcrypt('x'), 'role_id' => $roleId, 'branch_id' => $branchId, 'status' => 'Active']);
        ApprovalType::where('code', 'finance_reversal')->firstOrFail()->approvers()->syncWithoutDetaching([$this->approver->id]);

        $this->till = CashAccount::create(['code' => 'RV-CASH-' . Str::random(3), 'branch_id' => $branchId, 'account_type' => 'BranchMain', 'name' => 'RV Till', 'opening_balance' => 0, 'current_balance' => '100000.00', 'status' => 'Active']);
    }

    private function makeTxn(): FinanceTransaction
    {
        return FinancePostingService::execute([
            'txn_type' => 'CASH_OUT', 'amount' => '9000.00', 'entry_date' => now()->toDateString(),
            'branch_id' => $this->till->branch_id,
            'movements' => [['account_type' => 'cash', 'account_id' => $this->till->id, 'delta' => '-9000.00']],
            'gl_lines' => [
                ['account_id' => $this->expenseAccountId, 'debit' => '9000.00', 'credit' => 0],
                ['account_id' => ChartOfAccount::forCashTill($this->till->fresh())->id, 'debit' => 0, 'credit' => '9000.00'],
            ],
            'executed_by' => $this->admin->id,
        ]);
    }

    public function test_execute_writes_an_audit_event(): void
    {
        $txn = $this->makeTxn();
        $ev = FinanceAuditEvent::where('subject_type', 'FinanceTransaction')->where('subject_id', $txn->id)->where('action', 'transaction.executed')->first();
        $this->assertNotNull($ev);
        $this->assertSame('9000.00', (string) $ev->amount);
    }

    public function test_reversal_goes_through_approval_when_the_setting_is_on(): void
    {
        FinanceSetting::put('reversal_requires_approval', '1');
        $txn = $this->makeTxn();

        $this->actingAs($this->admin, 'sanctum');
        $this->postJson("/api/finance-transactions/{$txn->id}/reverse-request", ['reason' => 'wrong amount'])
            ->assertStatus(201)->assertJsonPath('message', 'Reversal sent for approval.');
        $this->assertSame('Completed', $txn->fresh()->status);

        $reqId = DB::table('approval_requests')
            ->whereIn('approval_type_id', ApprovalType::where('code', 'finance_reversal')->pluck('id'))
            ->where('subject_id', $txn->id)->value('id');

        $this->actingAs($this->approver, 'sanctum');
        $this->postJson("/api/finance-transaction-reverse-requests/{$reqId}/approve")->assertOk();

        $this->assertSame('Reversed', $txn->fresh()->status);
        $this->assertSame('100000.00', (string) $this->till->fresh()->current_balance);
        $this->assertTrue(FinanceAuditEvent::where('subject_id', $txn->id)->where('action', 'transaction.reversed')->exists());
    }

    public function test_reversal_is_direct_when_the_setting_is_off(): void
    {
        FinanceSetting::put('reversal_requires_approval', '0');
        $txn = $this->makeTxn();

        $this->actingAs($this->admin, 'sanctum');
        $this->postJson("/api/finance-transactions/{$txn->id}/reverse-request", ['reason' => 'quick fix'])
            ->assertStatus(201)->assertJsonPath('message', 'Transaction reversed.');

        $this->assertSame('Reversed', $txn->fresh()->status);
        $this->assertSame('100000.00', (string) $this->till->fresh()->current_balance);
    }
}
