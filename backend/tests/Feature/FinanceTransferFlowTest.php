<?php

namespace Tests\Feature;

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
 * Phase 5 — cash transfers through the finance request flow: one-step
 * (same custody) and the two-step release/receive for cross-branch cash,
 * plus recall. DatabaseTransactions.
 */
class FinanceTransferFlowTest extends TestCase
{
    use DatabaseTransactions;

    private CashAccount $tillA;

    private CashAccount $tillB;

    private User $requester;

    private User $approver;

    private User $receiver;

    protected function setUp(): void
    {
        parent::setUp();
        if (! ChartOfAccount::where('code', '1010')->exists()) {
            $this->markTestSkipped('Chart of accounts not seeded.');
        }
        $roleId = DB::table('roles')->where('slug', 'staff')->value('id') ?? 1;
        $branchA = DB::table('branches')->insertGetId(['name' => 'TFA', 'code' => 'TFA' . Str::random(3), 'branch_type' => 'Branch', 'status' => 'Active', 'created_at' => now(), 'updated_at' => now()]);
        $branchB = DB::table('branches')->insertGetId(['name' => 'TFB', 'code' => 'TFB' . Str::random(3), 'branch_type' => 'Branch', 'status' => 'Active', 'created_at' => now(), 'updated_at' => now()]);

        $mk = fn ($p, $b) => User::create(['public_uuid' => (string) Str::uuid(), 'name' => $p, 'email' => strtolower($p) . Str::random(5) . '@t.local', 'password' => bcrypt('x'), 'role_id' => $roleId, 'branch_id' => $b, 'status' => 'Active']);
        $this->requester = $mk('Req', $branchA);
        $this->approver = $mk('App', $branchA);
        $this->receiver = $mk('Rcv', $branchB);
        ApprovalType::where('code', 'finance_transfer')->firstOrFail()->approvers()->syncWithoutDetaching([$this->approver->id]);

        $this->tillA = CashAccount::create(['code' => 'TFA-CASH-' . Str::random(3), 'branch_id' => $branchA, 'account_type' => 'BranchMain', 'name' => 'Till A', 'opening_balance' => 0, 'current_balance' => '400000.00', 'status' => 'Active']);
        $this->tillB = CashAccount::create(['code' => 'TFB-CASH-' . Str::random(3), 'branch_id' => $branchB, 'account_type' => 'BranchMain', 'name' => 'Till B', 'opening_balance' => 0, 'current_balance' => '50000.00', 'status' => 'Active']);
    }

    private function open(bool $twoStep): FinanceRequest
    {
        return FinanceRequestService::open([
            'request_type' => $twoStep ? 'BranchFunding' : 'CashTransfer',
            'amount' => '100000.00',
            'branch_id' => $this->tillA->branch_id,
            'purpose' => 'Top up branch B',
            'requested_by' => $this->requester->id,
            'meta' => [
                'source_cash_account_id' => $this->tillA->id,
                'dest_cash_account_id' => $this->tillB->id,
                'two_step' => $twoStep,
            ],
        ])->fresh();
    }

    public function test_one_step_transfer_moves_both_tills(): void
    {
        $req = FinanceRequestService::approve($this->open(false), $this->approver->id);
        $req = FinanceRequestService::execute($req, [], $this->approver->id);

        $this->assertSame('Paid', $req->status);
        $this->assertSame('300000.00', (string) $this->tillA->fresh()->current_balance);
        $this->assertSame('150000.00', (string) $this->tillB->fresh()->current_balance);
        $this->assertSame('TRANSFER', $req->financeTransaction->txn_type);
        $this->assertSame('Move', $req->financeTransaction->direction);
    }

    public function test_two_step_transfer_goes_via_in_transit(): void
    {
        $req = FinanceRequestService::approve($this->open(true), $this->approver->id);

        $req = FinanceRequestService::release($req, $this->approver->id);
        $this->assertSame('InTransit', $req->status);
        $this->assertSame('300000.00', (string) $this->tillA->fresh()->current_balance);
        $this->assertSame('50000.00', (string) $this->tillB->fresh()->current_balance); // not yet
        $transit = CashAccount::where('branch_id', $this->tillA->branch_id)->where('account_type', 'InTransit')->first();
        $this->assertSame('100000.00', (string) $transit->current_balance);

        $req = FinanceRequestService::receive($req, $this->receiver->id);
        $this->assertSame('Completed', $req->status);
        $this->assertSame('150000.00', (string) $this->tillB->fresh()->current_balance);
        $this->assertSame('0.00', (string) $transit->fresh()->current_balance);
    }

    public function test_releaser_cannot_receive(): void
    {
        $req = FinanceRequestService::approve($this->open(true), $this->approver->id);
        $req = FinanceRequestService::release($req, $this->approver->id);

        $this->expectException(\App\Exceptions\FinancePostingException::class);
        FinanceRequestService::receive($req, $this->approver->id);
    }

    public function test_recall_restores_the_source(): void
    {
        $req = FinanceRequestService::approve($this->open(true), $this->approver->id);
        $req = FinanceRequestService::release($req, $this->approver->id);
        $req = FinanceRequestService::recall($req, $this->requester->id, 'wrong branch');

        $this->assertSame('Cancelled', $req->status);
        $this->assertSame('400000.00', (string) $this->tillA->fresh()->current_balance);
        $transit = CashAccount::where('branch_id', $this->tillA->branch_id)->where('account_type', 'InTransit')->first();
        $this->assertSame('0.00', (string) $transit->current_balance);
    }
}
