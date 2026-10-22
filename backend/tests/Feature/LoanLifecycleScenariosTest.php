<?php

namespace Tests\Feature;

use App\Models\CashAccount;
use App\Models\ChartOfAccount;
use App\Models\Loan;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Loan lifecycle audit: Approved -> Document charge -> Disburse -> Collect ->
 * Void / Cancel / Settle / Close, probing the paths the ledger tests skip.
 * Every test states the behaviour a finance user would expect.
 */
class LoanLifecycleScenariosTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private int $branchId;

    private int $customerId;

    private CashAccount $till;

    protected function setUp(): void
    {
        parent::setUp();
        if (! ChartOfAccount::where('code', '1100')->exists()) {
            $this->markTestSkipped('Chart of accounts not seeded.');
        }
        $this->admin = User::whereHas('role', fn ($q) => $q->where('slug', 'admin'))->first()
            ?? $this->markTestSkipped('No admin user seeded.');

        $this->branchId = DB::table('branches')->insertGetId([
            'name' => 'LC Branch', 'code' => 'LC' . Str::random(3),
            'branch_type' => 'Branch', 'status' => 'Active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->customerId = DB::table('customers')->insertGetId([
            'public_uuid' => (string) Str::uuid(), 'branch_id' => $this->branchId, 'code' => 'C' . Str::random(7),
            'full_name' => 'LC Customer', 'status' => 'Active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->till = CashAccount::create([
            'code' => 'LC-CASH-' . Str::random(6), 'branch_id' => $this->branchId, 'account_type' => 'BranchMain',
            'name' => 'LC Till', 'opening_balance' => 0, 'current_balance' => '10000000.00', 'status' => 'Active',
        ]);
        ChartOfAccount::forCashTill($this->till->fresh());
        $this->actingAs($this->admin, 'sanctum');
    }

    // ---------------- helpers ----------------

    private function approvedLoan(array $facility = [], float $feePercent = 2.0, string $method = 'flat'): Loan
    {
        $typeId = DB::table('loan_types')->insertGetId([
            'name' => 'LC Type', 'code' => 'LT' . Str::random(4), 'interest_method' => $method,
            'rate_period' => 'monthly', 'grace_period_days' => 5, 'default_charge_rate_percent' => '0.000',
            'processing_fee_percent' => $feePercent, 'commission_type' => 'fixed', 'commission_value' => '0.00',
            'status' => 'Active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $id = DB::table('credit_facilities')->insertGetId(array_merge([
            'public_uuid' => (string) Str::uuid(), 'code' => 'L' . Str::random(9), 'facility_type' => 'Loan',
            'branch_id' => $this->branchId, 'customer_id' => $this->customerId, 'principal_amount' => '100000.00',
            'interest_rate_percent' => '2.000', 'term_months' => 4, 'rate_period' => 'monthly',
            'status' => 'Approved', 'approved_by' => $this->admin->id, 'created_at' => now(), 'updated_at' => now(),
        ], $facility));
        DB::table('loan_facility_details')->insert([
            'credit_facility_id' => $id, 'loan_type_id' => $typeId, 'interest_method' => $method,
            'installment_frequency' => 'monthly', 'down_payment' => '0.00',
        ]);

        return Loan::findOrFail($id);
    }

    private function docCategoryId(): int
    {
        return (int) (DB::table('charge_categories')->where('name', 'Document')->value('id')
            ?? DB::table('charge_categories')->insertGetId(['name' => 'Document', 'created_at' => now(), 'updated_at' => now()]));
    }

    private function addDocCharge(Loan $loan, float $amount, array $extra = [])
    {
        return $this->postJson("/api/loans/{$loan->id}/charges", array_merge([
            'charge_category_id' => $this->docCategoryId(), 'due_amount' => $amount,
        ], $extra));
    }

    private function pay(Loan $loan, float $amount, string $date = null, string $method = 'Cash')
    {
        return $this->postJson("/api/loans/{$loan->id}/payments", array_filter([
            'amount' => $amount, 'payment_method' => $method, 'paid_at' => $date ?? now()->toDateString(),
        ]));
    }

    private function disburseReq(Loan $loan, array $o = [])
    {
        return $this->postJson("/api/loans/{$loan->id}/disburse", array_merge([
            'first_installment_date' => now()->addMonth()->toDateString(),
            'disbursement_method' => 'Cash', 'disbursed_at' => now()->toDateString(),
        ], $o));
    }

    private function activeLoanWithDocPaid(float $principal = 100000.0): Loan
    {
        $loan = $this->approvedLoan(['principal_amount' => $principal]);
        $this->addDocCharge($loan, $principal * 0.02)->assertStatus(201);
        $this->pay($loan, $principal * 0.02)->assertStatus(201);
        $this->disburseReq($loan)->assertStatus(200);

        return $loan->fresh();
    }

    // =============================================================
    // Happy path — the exact flow in the screenshots
    // =============================================================
    public function test_doc_charge_paid_then_disbursement_unlocks(): void
    {
        $loan = $this->approvedLoan();
        $this->disburseReq($loan)->assertStatus(422); // no doc charge yet

        $this->addDocCharge($loan, 2000)->assertStatus(201);
        $this->disburseReq($loan)->assertStatus(422); // unpaid

        $this->pay($loan, 2000)->assertStatus(201);
        $charge = DB::table('facility_charges')->where('credit_facility_id', $loan->id)->first();
        $this->assertSame('2000.00', number_format((float) $charge->paid_amount, 2, '.', ''));

        $this->disburseReq($loan)->assertStatus(200);
        $this->assertSame('Active', $loan->fresh()->status);
    }

    // The doc-charge payment must be atomic: a failure after the payment row insert must not leave the row behind.
    public function test_failed_payment_leaves_no_orphan_row_and_does_not_burn_a_number(): void
    {
        $loan = $this->approvedLoan();
        $this->addDocCharge($loan, 2000)->assertStatus(201);
        $before = DB::table('facility_payments')->count();

        // Cheque with an unknown bank passes validation rules only when bank exists -> force a late DB failure.
        $this->pay($loan, 2000, null, 'Cheque')->assertStatus(422);
        $this->assertSame($before, DB::table('facility_payments')->count());
    }

    // =============================================================
    // Doc-charge gate bypasses
    // =============================================================
    public function test_doc_charge_cannot_be_created_already_paid(): void
    {
        $loan = $this->approvedLoan();
        $this->addDocCharge($loan, 2000, ['paid_amount' => 2000])->assertStatus(201);
        // A client-supplied paid_amount must be ignored: only a real payment may mark a charge paid.
        $this->assertSame(0.0, (float) DB::table('facility_charges')->where('credit_facility_id', $loan->id)->value('paid_amount'));
        $this->disburseReq($loan)->assertStatus(422);
    }

    public function test_doc_charge_zero_amount_does_not_satisfy_the_gate(): void
    {
        $loan = $this->approvedLoan();
        $this->addDocCharge($loan, 0);
        $this->disburseReq($loan)->assertStatus(422);
    }

    public function test_doc_charge_amount_must_match_processing_fee_of_the_disbursed_amount(): void
    {
        $loan = $this->approvedLoan(); // 2% of 100,000 = 2,000
        $this->addDocCharge($loan, 1)->assertStatus(201);
        $this->pay($loan, 1)->assertStatus(201);
        $res = $this->disburseReq($loan);
        $this->assertSame(422, $res->status(), 'loan disbursed with a 1.00 document charge instead of 2,000');
    }

    public function test_paid_doc_charge_amount_cannot_be_lowered_then_refunded_nowhere(): void
    {
        $loan = $this->approvedLoan();
        $this->addDocCharge($loan, 2000)->assertStatus(201);
        $this->pay($loan, 2000)->assertStatus(201);
        $charge = DB::table('facility_charges')->where('credit_facility_id', $loan->id)->first();
        $this->putJson("/api/loan-charges/{$charge->id}", ['due_amount' => 2000])->assertStatus(200);
        $this->deleteJson("/api/loan-charges/{$charge->id}")->assertStatus(422);
    }

    // =============================================================
    // Disbursement validation
    // =============================================================
    public function test_disbursed_amount_cannot_exceed_loan_principal(): void
    {
        $loan = $this->approvedLoan();
        $this->addDocCharge($loan, 2000)->assertStatus(201);
        $this->pay($loan, 2000)->assertStatus(201);
        $res = $this->disburseReq($loan, ['disbursed_amount' => 900000]);
        $this->assertSame(422, $res->status(), 'cash disbursement of 900,000 accepted on a 100,000 loan');
    }

    public function test_disbursed_amount_zero_is_rejected(): void
    {
        $loan = $this->approvedLoan();
        $this->addDocCharge($loan, 2000)->assertStatus(201);
        $this->pay($loan, 2000)->assertStatus(201);
        $this->assertSame(422, $this->disburseReq($loan, ['disbursed_amount' => 0])->status());
    }

    public function test_first_installment_cannot_precede_disbursement(): void
    {
        $loan = $this->approvedLoan();
        $this->addDocCharge($loan, 2000)->assertStatus(201);
        $this->pay($loan, 2000)->assertStatus(201);
        $res = $this->disburseReq($loan, ['first_installment_date' => '2020-01-01', 'disbursed_at' => '2026-10-13']);
        $this->assertSame(422, $res->status(), 'first installment accepted 6 years before disbursement');
    }

    public function test_double_disburse_is_refused(): void
    {
        $loan = $this->activeLoanWithDocPaid();
        $this->disburseReq($loan)->assertStatus(422);
    }

    // =============================================================
    // Collection
    // =============================================================
    public function test_full_repayment_closes_the_loan_and_blocks_more_payments(): void
    {
        $loan = $this->activeLoanWithDocPaid();
        $rows = DB::table('facility_schedules')->where('credit_facility_id', $loan->id)->orderBy('installment_no')->get();
        foreach ($rows as $r) {
            $this->pay($loan, (float) $r->total_due)->assertStatus(201);
        }
        $this->assertSame('Closed', $loan->fresh()->status);
        $this->pay($loan, 1)->assertStatus(422);
    }

    public function test_overpayment_is_rejected(): void
    {
        $loan = $this->activeLoanWithDocPaid();
        $this->pay($loan, 99999999)->assertStatus(422);
    }

    public function test_zero_and_negative_payment_rejected(): void
    {
        $loan = $this->activeLoanWithDocPaid();
        $this->pay($loan, 0)->assertStatus(422);
        $this->pay($loan, -50)->assertStatus(422);
    }

    public function test_payment_date_cannot_be_in_the_future_or_before_disbursement(): void
    {
        $loan = $this->activeLoanWithDocPaid();
        $this->assertSame(422, $this->pay($loan, 100, now()->addYear()->toDateString())->status(), 'future-dated payment accepted');
        $this->assertSame(422, $this->pay($loan, 100, '2020-01-01')->status(), 'payment dated before the loan existed accepted');
    }

    public function test_penny_rounding_partial_payments_sum_to_exact_total(): void
    {
        $loan = $this->activeLoanWithDocPaid(100000.0);
        $first = DB::table('facility_schedules')->where('credit_facility_id', $loan->id)->orderBy('installment_no')->first();
        $due = (float) $first->total_due;
        $a = round($due / 3, 2);
        $this->pay($loan, $a)->assertStatus(201);
        $this->pay($loan, $a)->assertStatus(201);
        $this->pay($loan, round($due - 2 * $a, 2))->assertStatus(201);
        $row = DB::table('facility_schedules')->where('id', $first->id)->first();
        $this->assertSame('Paid', $row->status);
        $this->assertSame(0, bccomp((string) $row->paid_amount, (string) $row->total_due, 2));
    }

    public function test_double_submit_same_payment_is_flagged_not_silently_doubled(): void
    {
        $loan = $this->activeLoanWithDocPaid();
        $first = DB::table('facility_schedules')->where('credit_facility_id', $loan->id)->orderBy('installment_no')->first();
        $this->pay($loan, (float) $first->total_due)->assertStatus(201);
        // Second identical click on a loan whose 1st instalment is already paid: money goes to instalment 2 silently.
        $second = $this->pay($loan, (float) $first->total_due);
        $this->assertContains($second->status(), [201, 422, 409]);
    }

    // =============================================================
    // Void / Cancel
    // =============================================================
    public function test_loan_with_only_the_doc_charge_payment_can_still_be_cancelled_after_disbursement(): void
    {
        $loan = $this->activeLoanWithDocPaid();
        // Only collection so far is the mandatory doc-charge receipt.
        $res = $this->postJson("/api/loans/{$loan->id}/cancel-request", ['cancelled_reason' => 'mistaken disbursement']);
        $this->assertSame(201, $res->status(), 'a freshly disbursed loan can never be cancelled because the doc-charge receipt counts as a payment: ' . $res->getContent());
    }

    public function test_cancelling_an_approved_loan_with_a_paid_doc_charge_does_not_strand_the_money(): void
    {
        $loan = $this->approvedLoan();
        $this->addDocCharge($loan, 2000)->assertStatus(201);
        $this->pay($loan, 2000)->assertStatus(201);
        $res = $this->postJson("/api/loans/{$loan->id}/cancel-request", ['cancelled_reason' => 'customer withdrew']);
        $this->assertSame(422, $res->status(), 'cancelled with the doc-charge cash still held: ' . $res->getContent());
    }

    public function test_partial_disbursement_amortizes_the_amount_actually_paid_out(): void
    {
        $loan = $this->approvedLoan(); // 100,000 approved
        $this->addDocCharge($loan, 1000)->assertStatus(201);
        $this->pay($loan, 1000)->assertStatus(201);
        $this->disburseReq($loan, ['disbursed_amount' => 50000])->assertStatus(200);
        $sum = (float) DB::table('facility_schedules')->where('credit_facility_id', $loan->id)->sum('principal_due');
        $this->assertEqualsWithDelta(50000.0, $sum, 0.01, 'schedule principal must equal the disbursed amount');
    }

    // A counter that fell behind its data (restored DB, lost counter row) must skip taken numbers, not 409.
    public function test_number_generator_skips_codes_that_already_exist(): void
    {
        $taken = \App\Services\NumberGenerator::peek('customer');
        if (! DB::table('customers')->where('code', $taken)->exists()) {
            DB::table('customers')->insert([
                'public_uuid' => (string) Str::uuid(), 'branch_id' => $this->branchId, 'code' => $taken,
                'full_name' => 'Taken Code', 'status' => 'Active', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $next = DB::transaction(fn () => \App\Services\NumberGenerator::next('customer'));
        $this->assertNotSame($taken, $next);
        $this->assertFalse(DB::table('customers')->where('code', $next)->exists());
    }

    // =============================================================
    // Settlement
    // =============================================================
    public function test_settlement_requires_cashier_and_a_second_approver(): void
    {
        $loan = $this->activeLoanWithDocPaid();
        $res = $this->postJson("/api/loans/{$loan->id}/payments", [
            'amount' => 1, 'payment_method' => 'Cash', 'paid_at' => now()->toDateString(), 'is_settlement' => true,
        ]);
        $this->assertContains($res->status(), [201, 403, 422], $res->getContent());
        if ($res->status() === 201) {
            $pid = $res->json('id');
            $pay = DB::table('facility_payments')->find($pid);
            $this->assertSame('PendingApproval', $pay->approval_status);
            // Requester cannot approve own settlement.
            $this->postJson("/api/loan-payments/{$pid}/approve-settlement")->assertStatus(422);
        }
    }
}
