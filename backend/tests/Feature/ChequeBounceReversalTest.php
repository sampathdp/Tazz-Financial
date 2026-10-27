<?php

namespace Tests\Feature;

use App\Models\CashAccount;
use App\Models\ChartOfAccount;
use App\Models\Loan;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A cheque that already cleared (Realized) posted Dr 1030 / Cr split and marked the instalment paid.
 * If the bank then sends it back, both bounce paths must undo all of that — the loan schedule and the
 * ledger — not just relabel the cheque. Baseline->delta on the shared control accounts.
 */
class ChequeBounceReversalTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private int $branchId;

    private int $customerId;

    private int $bankId;

    protected function setUp(): void
    {
        parent::setUp();
        if (! ChartOfAccount::where('code', '1030')->exists()) {
            $this->markTestSkipped('Chart of accounts not seeded.');
        }
        $this->admin = User::whereHas('role', fn ($q) => $q->where('slug', 'admin'))->first()
            ?? $this->markTestSkipped('No admin user seeded.');

        $this->branchId = DB::table('branches')->insertGetId([
            'name' => 'CB Branch', 'code' => 'CB' . Str::random(3),
            'branch_type' => 'Branch', 'status' => 'Active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->customerId = DB::table('customers')->insertGetId([
            'public_uuid' => (string) Str::uuid(), 'branch_id' => $this->branchId, 'code' => 'C' . Str::random(7),
            'full_name' => 'CB Customer', 'status' => 'Active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        CashAccount::create([
            'code' => 'CB-CASH-' . Str::random(6), 'branch_id' => $this->branchId, 'account_type' => 'BranchMain',
            'name' => 'CB Till', 'opening_balance' => 0, 'current_balance' => '10000000.00', 'status' => 'Active',
        ]);
        $this->bankId = DB::table('banks')->insertGetId(['name' => 'CB Bank ' . Str::random(4), 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($this->admin, 'sanctum');
    }

    private function disbursedLoan(): Loan
    {
        $typeId = DB::table('loan_types')->insertGetId([
            'name' => 'CB Type', 'code' => 'LT' . Str::random(4),
            'interest_method' => 'flat', 'rate_period' => 'monthly',
            'grace_period_days' => 5, 'default_charge_rate_percent' => '0.000', 'processing_fee_percent' => '0.000',
            'commission_type' => 'fixed', 'commission_value' => '0.00', 'status' => 'Active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $id = DB::table('credit_facilities')->insertGetId([
            'public_uuid' => (string) Str::uuid(), 'code' => 'L' . Str::random(9), 'facility_type' => 'Loan',
            'branch_id' => $this->branchId, 'customer_id' => $this->customerId,
            'principal_amount' => '100000.00', 'interest_rate_percent' => '2.000', 'term_months' => 10, 'rate_period' => 'monthly',
            'status' => 'Approved', 'approved_by' => $this->admin->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('loan_facility_details')->insert([
            'credit_facility_id' => $id, 'loan_type_id' => $typeId, 'interest_method' => 'flat',
            'installment_frequency' => 'monthly', 'down_payment' => '0.00',
        ]);
        $r = $this->postJson("/api/loans/{$id}/disburse", [
            'first_installment_date' => '2026-10-22', 'disbursement_method' => 'Cash', 'disbursed_at' => '2026-10-21',
        ]);
        $this->assertSame(200, $r->status(), $r->getContent());

        return Loan::findOrFail($id);
    }

    private function glBalance(string $code): string
    {
        ChartOfAccount::flushBalancesCache();
        $r = ChartOfAccount::balancesAsOf(now()->addYears(5)->toDateString())->get(ChartOfAccount::where('code', $code)->value('id'));

        return $r ? (string) $r->balance : '0.00';
    }

    private function firstInstalment(int $loanId): object
    {
        return DB::table('facility_schedules')->where('credit_facility_id', $loanId)->orderBy('installment_no')->first();
    }

    public function test_bouncing_a_realized_cheque_payment_reverses_the_schedule_and_the_ledger(): void
    {
        $loan = $this->disbursedLoan();
        $before = ['1030' => $this->glBalance('1030'), '1100' => $this->glBalance('1100'), '4010' => $this->glBalance('4010')];

        $this->postJson("/api/loans/{$loan->id}/payments", [
            'amount' => 12000, 'payment_method' => 'Cheque', 'paid_at' => '2026-10-23',
            'bank_id' => $this->bankId, 'cheque_date' => '2026-10-23',
        ])->assertStatus(201);
        $payment = DB::table('facility_payments')->where('credit_facility_id', $loan->id)->latest('id')->first();

        $this->patchJson("/api/loan-payments/{$payment->id}/clear-cheque", ['status' => 'Realized', 'realized_at' => '2026-10-24'])->assertStatus(200);
        $this->assertSame('Paid', $this->firstInstalment($loan->id)->status);
        $this->assertSame('12000.00', bcadd(bcsub($this->glBalance('1030'), $before['1030'], 2), '0', 2));

        $this->patchJson("/api/loan-payments/{$payment->id}/clear-cheque", ['status' => 'Bounced'])->assertStatus(200);

        $after = DB::table('facility_payments')->find($payment->id);
        $this->assertSame('Voided', $after->status);
        $this->assertSame('Bounced', $after->clearance_status);
        $inst = $this->firstInstalment($loan->id);
        $this->assertSame('Pending', $inst->status, 'instalment must be unpaid again');
        $this->assertSame(0, bccomp('0.00', (string) $inst->paid_amount, 2));
        foreach ($before as $code => $balance) {
            $this->assertSame(0, bccomp($balance, $this->glBalance($code), 2), "{$code} must be back to its pre-cheque balance");
        }
    }

    public function test_bouncing_a_pending_cheque_payment_still_just_voids_it(): void
    {
        $loan = $this->disbursedLoan();
        $before1030 = $this->glBalance('1030');

        $this->postJson("/api/loans/{$loan->id}/payments", [
            'amount' => 12000, 'payment_method' => 'Cheque', 'paid_at' => '2026-10-23',
            'bank_id' => $this->bankId, 'cheque_date' => '2026-10-23',
        ])->assertStatus(201);
        $payment = DB::table('facility_payments')->where('credit_facility_id', $loan->id)->latest('id')->first();

        $this->patchJson("/api/loan-payments/{$payment->id}/clear-cheque", ['status' => 'Bounced'])->assertStatus(200);

        $this->assertSame('Voided', DB::table('facility_payments')->find($payment->id)->status);
        $this->assertSame('Pending', $this->firstInstalment($loan->id)->status);
        $this->assertSame(0, bccomp($before1030, $this->glBalance('1030'), 2));
    }

    public function test_an_approved_manual_allocation_cheque_is_booked_and_can_then_bounce(): void
    {
        $loan = $this->disbursedLoan();
        $before = ['1030' => $this->glBalance('1030'), '1100' => $this->glBalance('1100'), '4010' => $this->glBalance('4010'), '2100' => $this->glBalance('2100')];

        $this->postJson("/api/loans/{$loan->id}/payments", [
            'amount' => 12000, 'payment_method' => 'Cheque', 'paid_at' => '2026-10-23',
            'bank_id' => $this->bankId, 'cheque_date' => '2026-10-23',
            'allocation_mode' => 'manual', 'allocations' => ['interest' => 2000, 'capital' => 10000],
        ])->assertStatus(201);
        $payment = \App\Models\FacilityPayment::where('credit_facility_id', $loan->id)->latest('id')->first();
        $this->assertSame(0, bccomp($before['1030'], $this->glBalance('1030'), 2), 'a pending cheque posts nothing');

        // What approveAllocationRequest() does once the second approver signs.
        \App\Support\LoanPaymentAllocator::applyManualAllocation($loan, $payment, (array) $payment->manual_allocation, $payment->paid_at);
        \App\Support\LoanLedgerPoster::postManualAllocationApplied($payment);
        $payment->update(['approval_status' => 'Approved']);

        $this->assertSame('Paid', $this->firstInstalment($loan->id)->status);
        $this->assertSame('12000.00', bcsub($this->glBalance('1030'), $before['1030'], 2), 'booked Dr 1030 like a realized cheque');
        $this->assertSame('-10000.00', bcsub($this->glBalance('1100'), $before['1100'], 2));
        $this->assertSame('2000.00', bcsub($this->glBalance('4010'), $before['4010'], 2));
        $this->assertSame(0, bccomp($before['2100'], $this->glBalance('2100'), 2), 'nothing is left parked in 2100');

        $this->patchJson("/api/loan-payments/{$payment->id}/clear-cheque", ['status' => 'Bounced'])->assertStatus(200);

        $this->assertSame('Voided', $payment->fresh()->status);
        $this->assertSame('Pending', $this->firstInstalment($loan->id)->status);
        foreach ($before as $code => $balance) {
            $this->assertSame(0, bccomp($balance, $this->glBalance($code), 2), "{$code} must be back to its pre-cheque balance");
        }
    }

    public function test_bouncing_a_realized_post_dated_cheque_reverses_the_linked_payment(): void
    {
        $loan = $this->disbursedLoan();
        $before = ['1030' => $this->glBalance('1030'), '1100' => $this->glBalance('1100'), '4010' => $this->glBalance('4010')];

        $chequeId = $this->postJson("/api/loans/{$loan->id}/post-dated-cheques", [
            'cheque_no' => 'CB' . random_int(100000, 999999), 'bank_id' => $this->bankId, 'cheque_date' => '2026-10-23', 'amount' => 12000,
        ])->assertStatus(201)->json('id');

        $this->patchJson("/api/loan-post-dated-cheques/{$chequeId}", ['status' => 'Realized', 'realized_at' => '2026-10-24'])->assertStatus(200);
        $this->assertSame('Paid', $this->firstInstalment($loan->id)->status);

        // Anything but Bounced is refused once realized — it would leave the loan paid.
        $this->patchJson("/api/loan-post-dated-cheques/{$chequeId}", ['status' => 'Pending'])->assertStatus(422);

        $this->patchJson("/api/loan-post-dated-cheques/{$chequeId}", ['status' => 'Bounced'])->assertStatus(200);

        $cheque = DB::table('facility_post_dated_cheques')->find($chequeId);
        $this->assertSame('Bounced', $cheque->status);
        $payment = DB::table('facility_payments')->find($cheque->facility_payment_id);
        $this->assertSame('Voided', $payment->status);
        $this->assertSame('Pending', $this->firstInstalment($loan->id)->status);
        foreach ($before as $code => $balance) {
            $this->assertSame(0, bccomp($balance, $this->glBalance($code), 2), "{$code} must be back to its pre-cheque balance");
        }
    }
}
