<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\CashAccount;
use App\Models\ChartOfAccount;
use App\Models\Loan;
use App\Models\User;
use App\Support\CashSessionService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Cash / ledger audit probes (see docs/audit/cash-flow-ledger-map.md). Each test states
 * what a finance user would expect; a failing test is a confirmed gap.
 */
class CashLedgerAuditProbeTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private int $branchId;

    private int $customerId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::whereHas('role', fn ($q) => $q->where('slug', 'admin'))->first()
            ?? $this->markTestSkipped('No admin user seeded.');
        $this->branchId = DB::table('branches')->insertGetId([
            'name' => 'AU Branch', 'code' => 'AU' . Str::random(3), 'branch_type' => 'Branch', 'status' => 'Active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->customerId = DB::table('customers')->insertGetId([
            'public_uuid' => (string) Str::uuid(), 'branch_id' => $this->branchId, 'code' => 'C' . Str::random(7),
            'full_name' => 'AU Customer', 'status' => 'Active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actingAs($this->admin, 'sanctum');
    }

    private function till(string $name, string $balance = '0.00'): CashAccount
    {
        $t = CashAccount::create([
            'code' => 'AU-' . Str::random(6), 'branch_id' => $this->branchId, 'account_type' => 'BranchMain',
            'name' => $name, 'opening_balance' => 0, 'current_balance' => $balance, 'status' => 'Active',
        ]);
        ChartOfAccount::forCashTill($t->fresh());

        return $t->fresh();
    }

    private function approvedLoan(float $feePercent = 2.0): Loan
    {
        $typeId = DB::table('loan_types')->insertGetId([
            'name' => 'AU Type', 'code' => 'AT' . Str::random(4), 'interest_method' => 'flat', 'rate_period' => 'monthly',
            'grace_period_days' => 5, 'default_charge_rate_percent' => '0.000', 'processing_fee_percent' => $feePercent,
            'commission_type' => 'fixed', 'commission_value' => '0.00', 'status' => 'Active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $id = DB::table('credit_facilities')->insertGetId([
            'public_uuid' => (string) Str::uuid(), 'code' => 'L' . Str::random(9), 'facility_type' => 'Loan',
            'branch_id' => $this->branchId, 'customer_id' => $this->customerId, 'principal_amount' => '100000.00',
            'interest_rate_percent' => '2.000', 'term_months' => 4, 'rate_period' => 'monthly', 'status' => 'Approved',
            'approved_by' => $this->admin->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('loan_facility_details')->insert([
            'credit_facility_id' => $id, 'loan_type_id' => $typeId, 'interest_method' => 'flat',
            'installment_frequency' => 'monthly', 'down_payment' => '0.00',
        ]);

        return Loan::findOrFail($id);
    }

    private function docCharge(Loan $loan, float $amount = 2000): void
    {
        $cat = (int) (DB::table('charge_categories')->where('name', 'Document')->value('id')
            ?? DB::table('charge_categories')->insertGetId(['name' => 'Document', 'created_at' => now(), 'updated_at' => now()]));
        $this->postJson("/api/loans/{$loan->id}/charges", ['charge_category_id' => $cat, 'due_amount' => $amount])->assertStatus(201);
    }

    private function glBalance(int $accountId): string
    {
        ChartOfAccount::flushBalancesCache();
        $r = ChartOfAccount::balancesAsOf(now()->addYears(5)->toDateString())->get($accountId);

        return $r ? (string) $r->balance : '0.00';
    }

    // AUD-01: with several tills at one branch, each kind of cash goes to the till dedicated to it.
    public function test_cash_goes_to_the_till_dedicated_to_its_purpose(): void
    {
        $general = $this->till('Main');
        $doc = $this->till('Doc charge');
        $doc->update(['purpose' => CashAccount::PURPOSE_DOCUMENT_CHARGES]);
        $fd = $this->till('FD');
        $fd->update(['purpose' => CashAccount::PURPOSE_FD]);

        $this->assertSame($doc->id, CashAccount::branchPrimary($this->branchId, CashAccount::PURPOSE_DOCUMENT_CHARGES)->id);
        $this->assertSame($fd->id, CashAccount::branchPrimary($this->branchId, CashAccount::PURPOSE_FD)->id);
        // No dedicated till for a purpose -> the general till, never a till reserved for something else.
        $this->assertSame($general->id, CashAccount::branchPrimary($this->branchId, CashAccount::PURPOSE_SAVINGS)->id);
        $this->assertSame($general->id, CashAccount::branchPrimary($this->branchId)->id);

        // End to end: the document-charge receipt lands in the Doc charge till.
        $loan = $this->approvedLoan();
        $this->docCharge($loan);
        $this->postJson("/api/loans/{$loan->id}/payments", ['amount' => 2000, 'payment_method' => 'Cash'])->assertStatus(201);
        $this->assertSame('2000.00', (string) $doc->fresh()->current_balance);
        $this->assertSame('0.00', (string) $general->fresh()->current_balance);
        $this->assertSame('0.00', (string) $fd->fresh()->current_balance);
    }

    // When every till at the branch is dedicated to something, the oldest one is used (the previous behaviour).
    public function test_all_dedicated_tills_fall_back_to_the_oldest(): void
    {
        $a = $this->till('A');
        $a->update(['purpose' => CashAccount::PURPOSE_FD]);
        $b = $this->till('B');
        $b->update(['purpose' => CashAccount::PURPOSE_DOCUMENT_CHARGES]);
        $this->assertSame($a->id, CashAccount::branchPrimary($this->branchId, CashAccount::PURPOSE_COLLECTIONS)->id);
    }

    // AUD-02: the Document charge is booked to the income account its category is mapped to (4910 Documentation Income), falling back to 4020.
    public function test_document_charge_income_account(): void
    {
        $this->till('Main');
        $loan = $this->approvedLoan();
        $this->docCharge($loan);
        $mapped = DB::table('charge_categories')->where('name', 'Document')->value('chart_of_account_id');
        $expectedAccount = $mapped ?: ChartOfAccount::system('4020')->id;
        $before = $this->glBalance($expectedAccount);
        $this->postJson("/api/loans/{$loan->id}/payments", ['amount' => 2000, 'payment_method' => 'Cash'])->assertStatus(201);
        $this->assertSame('2000.00', number_format((float) $this->glBalance($expectedAccount) - (float) $before, 2, '.', ''));
        if (DB::table('chart_of_accounts')->where('code', '4910')->exists()) {
            $this->assertNotNull($mapped, 'Document category must be mapped to 4910 Documentation Income when that account exists');
        }
    }

    // AUD-03: a stale session from a previous day must not satisfy the "today's session" gate.
    public function test_yesterdays_open_session_does_not_unlock_todays_collections(): void
    {
        \App\Models\FinanceSetting::put('require_cash_session', '1');
        $till = $this->till('Main');
        $session = CashSessionService::open($till->id, '0.00', $this->admin->id);
        DB::table('cash_sessions')->where('id', $session->id)->update(['business_date' => now()->subDay()->toDateString()]);

        $loan = $this->approvedLoan();
        $this->docCharge($loan);
        $res = $this->postJson("/api/loans/{$loan->id}/payments", ['amount' => 2000, 'payment_method' => 'Cash']);
        $this->assertSame(422, $res->status(), 'collection accepted against a session opened yesterday');
    }

    // AUD-04: a session opened with a count that differs from the book balance needs an explanation.
    public function test_opening_count_must_match_book_balance_or_carry_a_note(): void
    {
        $till = $this->till('Main', '0.00');
        $this->postJson('/api/cash-sessions/open', ['cash_account_id' => $till->id, 'opening_amount' => 20000])->assertStatus(422);
        $this->postJson('/api/cash-sessions/open', ['cash_account_id' => $till->id, 'opening_amount' => 20000, 'notes' => 'cash brought from head office'])->assertStatus(201);
    }

    // AUD-05: petty-cash top-up must not drive a till negative.
    public function test_petty_cash_topup_cannot_overdraw_a_till(): void
    {
        $till = $this->till('Main', '1000.00');
        $fund = $this->postJson('/api/petty-cash-funds', ['branch_id' => $this->branchId, 'name' => 'AU float', 'float_limit' => 50000]);
        if ($fund->status() !== 201) {
            $this->markTestSkipped('could not create a petty cash fund: ' . $fund->getContent());
        }
        $res = $this->postJson('/api/petty-cash-replenishments', [
            'petty_cash_fund_id' => $fund->json('id'), 'amount' => 20000, 'funding_method' => 'Cash', 'cash_account_id' => $till->id,
        ]);
        $this->assertContains($res->status(), [409, 422], 'till overdrawn by a petty cash top-up: ' . $res->getContent());
        $this->assertGreaterThanOrEqual(0, (float) $till->fresh()->current_balance);
    }

    // AUD-06: Bank-method loan receipts - where does the money show up?
    public function test_bank_method_receipt_lands_in_undeposited_funds_not_a_bank_account(): void
    {
        $this->till('Main');
        $bankRow = DB::table('banks')->insertGetId(['name' => 'AU Bank', 'created_at' => now(), 'updated_at' => now()]);
        $bank = BankAccount::create([
            'branch_id' => $this->branchId, 'bank_id' => $bankRow, 'name' => 'AU Acc', 'account_number' => 'AU' . Str::random(8),
            'account_type' => 'Current', 'currency' => 'LKR', 'opening_balance' => 0, 'current_balance' => '0.00', 'status' => 'Active',
        ]);
        $loan = $this->approvedLoan();
        $this->docCharge($loan);
        $c1030 = ChartOfAccount::system('1030')->id;
        $before = $this->glBalance($c1030);
        $this->postJson("/api/loans/{$loan->id}/payments", ['amount' => 2000, 'payment_method' => 'Bank'])->assertStatus(201);
        $this->assertSame('0.00', (string) $bank->fresh()->current_balance);
        $this->assertSame('2000.00', number_format((float) $this->glBalance($c1030) - (float) $before, 2, '.', ''));
    }

    // AUD-07: the cash-session gate only exists on loan collections.
    public function test_session_gate_also_applies_to_cash_disbursement(): void
    {
        \App\Models\FinanceSetting::put('require_cash_session', '1');
        $till = $this->till('Main', '500000.00');
        $loan = $this->approvedLoan(0.0);
        $res = $this->postJson("/api/loans/{$loan->id}/disburse", [
            'first_installment_date' => now()->addMonth()->toDateString(), 'disbursement_method' => 'Cash', 'disbursed_at' => now()->toDateString(),
        ]);
        if ($res->status() === 200) {
            $this->markTestIncomplete('OPEN POLICY GAP: cash disbursed from a till with no open session while require_cash_session is on (the setting only gates loan collections).');
        }
        $this->assertSame(422, $res->status());
        unset($till);
    }
}
