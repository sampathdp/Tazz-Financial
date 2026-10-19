<?php

namespace Tests\Feature;

use App\Models\CashAccount;
use App\Models\ChartOfAccount;
use App\Models\FixedDeposit;
use App\Models\Loan;
use App\Models\User;
use App\Support\FdLienService;
use App\Support\LoanPaymentAllocator;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Loan against FD: eligibility/limit/rate quote, lien lifecycle (pledge, block,
 * release, transfer) and settlement of the loan from its FD.
 *
 * Fixture: FD of 100,000 @ 8% p.a., policy 90% LTV + 2% spread => max loan
 * 90,000 at 10% p.a. Persistent DB + DatabaseTransactions, own fixtures only.
 */
class FdLoanTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private int $branchId;

    private int $customerId;

    private int $productId;

    private int $loanTypeId;

    private CashAccount $till;

    protected function setUp(): void
    {
        parent::setUp();
        if (! ChartOfAccount::where('code', '2010')->exists()) {
            $this->markTestSkipped('Chart of accounts not seeded.');
        }
        $this->admin = User::whereHas('role', fn ($q) => $q->where('slug', 'admin'))->first()
            ?? $this->markTestSkipped('No admin user seeded.');

        $this->branchId = DB::table('branches')->insertGetId([
            'name' => 'FL Branch', 'code' => 'FL' . Str::random(3), 'branch_type' => 'Branch', 'status' => 'Active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->customerId = $this->customer();
        $this->till = CashAccount::create([
            'code' => 'FL-CASH-' . Str::random(6), 'branch_id' => $this->branchId, 'account_type' => 'BranchMain',
            'name' => 'FL Till', 'opening_balance' => 0, 'current_balance' => '10000000.00', 'status' => 'Active',
        ]);
        ChartOfAccount::forCashTill($this->till->fresh());

        $this->productId = DB::table('funding_products')->insertGetId([
            'name' => 'FL Product', 'code' => 'FP' . Str::random(5), 'interest_method' => 'maturity', 'annual_rate_percent' => '8.000',
            'allowed_terms_months' => json_encode([12]), 'min_deposit_amount' => '1000.00', 'status' => 'Active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('fd_loan_policies')->insert([
            'funding_product_id' => $this->productId, 'max_ltv_percent' => '90.00', 'rate_mode' => 'spread',
            'rate_spread_percent' => '2.000', 'min_days_to_maturity' => 30, 'cap_term_to_maturity' => 1,
            'status' => 'Active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->loanTypeId = DB::table('loan_types')->insertGetId([
            'name' => 'FL Type', 'code' => 'LT' . Str::random(4), 'is_product' => 1, 'interest_method' => 'flat', 'rate_period' => 'annual',
            'min_amount' => '0.00', 'max_amount' => '10000000.00', 'min_term_months' => 1, 'max_term_months' => 120,
            'grace_period_days' => 5, 'default_charge_rate_percent' => '0.000', 'processing_fee_percent' => '0.000',
            'commission_type' => 'fixed', 'commission_value' => '0.00', 'status' => 'Active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($this->admin, 'sanctum');
    }

    // ---------------- helpers ----------------

    private function customer(): int
    {
        return DB::table('customers')->insertGetId([
            'public_uuid' => (string) Str::uuid(), 'branch_id' => $this->branchId, 'code' => 'C' . Str::random(7),
            'full_name' => 'FL Customer', 'status' => 'Active', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function fd(array $o = []): FixedDeposit
    {
        $id = DB::table('fixed_deposits')->insertGetId(array_merge([
            'public_uuid' => (string) Str::uuid(), 'fd_number' => 'FD' . Str::random(8), 'branch_id' => $this->branchId,
            'customer_id' => $this->customerId, 'funding_product_id' => $this->productId,
            'principal_amount' => '100000.00', 'interest_rate_percent' => '8.000', 'interest_payment_method' => 'maturity',
            'term_months' => 12, 'start_date' => now()->subMonth()->toDateString(), 'maturity_date' => now()->addMonths(11)->toDateString(),
            'total_interest_amount' => '5000.00', 'maturity_amount' => '105000.00', 'status' => 'Active',
            'created_at' => now(), 'updated_at' => now(),
        ], $o));

        return FixedDeposit::findOrFail($id);
    }

    private function body(FixedDeposit $fd, array $o = []): array
    {
        return array_merge([
            'branch_id' => $this->branchId, 'fixed_deposit_id' => $fd->id, 'loan_type_id' => $this->loanTypeId,
            'principal_amount' => 90000, 'term_months' => 10, 'installment_frequency' => 'monthly',
        ], $o);
    }

    private function createLoan(FixedDeposit $fd, array $o = []): Loan
    {
        $r = $this->postJson('/api/fd-loans', $this->body($fd, $o));
        $r->assertStatus(201);

        return Loan::findOrFail($r->json('id'));
    }

    private function activeLien(int $loanId)
    {
        return DB::table('facility_collateral_fd_details')->where('credit_facility_id', $loanId)->first();
    }

    private function approveAndDisburse(Loan $loan): Loan
    {
        $loan->update(['status' => 'Approved', 'approved_by' => $this->admin->id]);
        $this->postJson("/api/loans/{$loan->id}/disburse", [
            'first_installment_date' => now()->addMonth()->toDateString(),
            'disbursement_method' => 'Cash',
            'disbursed_at' => now()->toDateString(),
        ])->assertStatus(200);

        return $loan->fresh();
    }

    // =============================================================
    // Quote / eligibility
    // =============================================================

    public function test_quote_applies_ltv_rate_spread_and_term_cap(): void
    {
        $fd = $this->fd();
        $q = FdLienService::quote($fd);

        $this->assertTrue($q['eligible']);
        $this->assertEquals(90000.00, $q['max_loan_amount']);   // 100,000 x 90%
        $this->assertEquals(10.0, $q['interest_rate_percent']);  // 8% FD + 2% spread
        $this->assertSame('annual', $q['rate_period']);
        $this->assertGreaterThanOrEqual(10, $q['max_term_months']); // ~11 months to maturity
        $this->assertLessThanOrEqual(11, $q['max_term_months']);
    }

    public function test_fixed_rate_policy_and_max_loan_cap(): void
    {
        DB::table('fd_loan_policies')->where('funding_product_id', $this->productId)
            ->update(['rate_mode' => 'fixed', 'fixed_rate_percent' => '14.500', 'max_loan_amount' => '50000.00']);
        $q = FdLienService::quote($this->fd());

        $this->assertEquals(14.5, $q['interest_rate_percent']);
        $this->assertEquals(50000.00, $q['max_loan_amount']);
    }

    public function test_ineligible_reasons(): void
    {
        $matured = $this->fd(['start_date' => now()->subYear()->toDateString(), 'maturity_date' => now()->subDay()->toDateString()]);
        $this->assertStringContainsString('matured', FdLienService::quote($matured)['reason']);

        $soon = $this->fd(['maturity_date' => now()->addDays(10)->toDateString()]);
        $this->assertStringContainsString('at least 30', FdLienService::quote($soon)['reason']);

        $closed = $this->fd(['status' => 'Closed']);
        $this->assertFalse(FdLienService::quote($closed)['eligible']);

        DB::table('fd_loan_policies')->where('funding_product_id', $this->productId)->update(['status' => 'Inactive']);
        $this->assertStringContainsString('Inactive', FdLienService::quote($this->fd())['reason']);

        DB::table('fd_loan_policies')->where('funding_product_id', $this->productId)->delete();
        $this->assertStringContainsString('not enabled', FdLienService::quote($this->fd())['reason']);
    }

    // =============================================================
    // Create loan + lien
    // =============================================================

    public function test_create_loan_pledges_the_fd(): void
    {
        $fd = $this->fd();
        $loan = $this->createLoan($fd);

        $this->assertSame('Pending', $loan->status);
        $this->assertEquals('90000.00', $loan->principal_amount);
        $this->assertEquals('10.000', $loan->interest_rate_percent);   // snapshot of FD rate + spread
        $this->assertSame('annual', $loan->rate_period);
        $this->assertSame($this->customerId, (int) $loan->customer_id);

        $lien = $this->activeLien($loan->id);
        $this->assertSame($fd->id, (int) $lien->fixed_deposit_id);
        $this->assertSame('Active', $lien->lien_status);
        $this->assertEquals('100000.00', $lien->lien_amount);
        $this->assertEquals('90000.00', $lien->max_loan_value);
        $this->assertSame(1, DB::table('facility_collateral')->where('credit_facility_id', $loan->id)->where('collateral_type', 'FixedDeposit')->count());

        $this->assertEquals('100000.00', $fd->fresh()->lien_amount);
        $this->assertTrue($fd->fresh()->is_pledged);
        // goes through the normal application approval
        $this->assertDatabaseHas('approval_requests', ['subject_id' => $loan->id, 'status' => 'PendingApproval']);
    }

    public function test_create_rejects_amount_over_ltv_and_term_over_maturity(): void
    {
        $fd = $this->fd();
        $this->postJson('/api/fd-loans', $this->body($fd, ['principal_amount' => 90000.01]))->assertStatus(422);
        $this->postJson('/api/fd-loans', $this->body($fd, ['term_months' => 36]))->assertStatus(422);

        $this->assertSame(0, (int) $fd->fresh()->lien_amount, 'a rejected application must not leave a lien');
        $this->assertSame(0, DB::table('facility_collateral_fd_details')->where('fixed_deposit_id', $fd->id)->count());
    }

    public function test_cannot_pledge_another_customers_fd(): void
    {
        $fd = $this->fd(['customer_id' => $this->customer()]);
        $loanCount = DB::table('credit_facilities')->count();
        // pledge() compares the FD owner with the loan customer, and the loan takes the FD owner => always same.
        $loan = $this->createLoan($fd);
        $this->assertSame((int) $fd->customer_id, (int) $loan->customer_id);
        $this->assertSame($loanCount + 1, DB::table('credit_facilities')->count());
    }

    public function test_same_fd_cannot_be_pledged_twice(): void
    {
        $fd = $this->fd();
        $this->createLoan($fd);

        $r = $this->postJson('/api/fd-loans', $this->body($fd, ['principal_amount' => 1000]));
        $r->assertStatus(422);
        $this->assertStringContainsString('already pledged', json_encode($r->json()));
        $this->assertSame(1, DB::table('facility_collateral_fd_details')->where('fixed_deposit_id', $fd->id)->count());
    }

    public function test_db_unique_index_blocks_two_active_liens_on_one_fd(): void
    {
        $fd = $this->fd();
        $first = $this->createLoan($fd);
        $row = (array) $this->activeLien($first->id);

        // a second loan + collateral row, hand-inserted with an Active lien on the same FD
        $second = $this->createLoan($this->fd());
        $collateralId = DB::table('facility_collateral')->where('credit_facility_id', $second->id)->value('id');
        DB::table('facility_collateral_fd_details')->where('facility_collateral_id', $collateralId)->delete();

        $this->expectException(QueryException::class);
        DB::table('facility_collateral_fd_details')->insert(array_merge($row, [
            'facility_collateral_id' => $collateralId, 'credit_facility_id' => $second->id,
        ]));
    }

    // =============================================================
    // FD actions blocked while pledged
    // =============================================================

    public function test_pledged_fd_blocks_renew_withdraw_and_part_payment(): void
    {
        $fd = $this->fd(['start_date' => now()->subYear()->toDateString(), 'maturity_date' => now()->addDays(40)->toDateString()]);
        $this->createLoan($fd, ['term_months' => 1, 'principal_amount' => 1000]);
        // matured now, so only the lien can be what refuses it
        $fd->update(['maturity_date' => now()->subDay()->toDateString()]);

        foreach (['renew' => ['term_months' => 12], 'withdraw' => ['payment_method' => 'Cash'], 'part-payment' => ['amount' => 100, 'payment_method' => 'Cash']] as $action => $payload) {
            $r = $this->postJson("/api/fixed-deposits/{$fd->id}/{$action}", $payload);
            $r->assertStatus(422);
            $this->assertStringContainsString('pledged', $r->json('message'), $action);
        }
        $this->assertSame('Active', $fd->fresh()->status);
    }

    // =============================================================
    // Lien release
    // =============================================================

    public function test_withdrawing_the_application_releases_the_fd(): void
    {
        $fd = $this->fd();
        $loan = $this->createLoan($fd);

        $this->deleteJson("/api/loans/{$loan->id}", ['rejected_reason' => 'changed mind'])->assertStatus(204);

        $this->assertSame('Released', $this->activeLien($loan->id)->lien_status);
        $this->assertSame(0, (int) $fd->fresh()->lien_amount);
        // free again: a new loan can take it
        $this->createLoan($fd, ['principal_amount' => 5000]);
    }

    public function test_cancelled_rejected_and_closed_loans_release_the_fd(): void
    {
        foreach (['Rejected', 'Cancelled', 'Closed'] as $status) {
            $fd = $this->fd();
            $loan = $this->createLoan($fd);
            $loan->update(['status' => $status]);

            $this->assertSame('Released', $this->activeLien($loan->id)->lien_status, $status);
            $this->assertSame(0, (int) $fd->fresh()->lien_amount, $status);
            $this->assertNotNull($this->activeLien($loan->id)->released_at, $status);
        }
    }

    public function test_other_status_changes_keep_the_lien(): void
    {
        $fd = $this->fd();
        $loan = $this->createLoan($fd);
        foreach (['Approved', 'Active', 'WrittenOff'] as $status) {
            $loan->update(['status' => $status]);
            $this->assertSame('Active', $this->activeLien($loan->id)->lien_status, $status);
            $this->assertEquals('100000.00', $fd->fresh()->lien_amount, $status);
        }
    }

    public function test_editing_the_application_keeps_the_fd_collateral(): void
    {
        $fd = $this->fd();
        $loan = $this->createLoan($fd);

        $this->putJson("/api/loans/{$loan->id}", [
            'branch_id' => $this->branchId, 'customer_id' => $this->customerId, 'loan_type_id' => $this->loanTypeId,
            'principal_amount' => 80000, 'term_months' => 10, 'installment_frequency' => 'monthly',
            'collateral' => [['collateral_type' => 'Other', 'description' => 'Bond']],
        ])->assertStatus(200);

        $this->assertSame(1, DB::table('facility_collateral')->where('credit_facility_id', $loan->id)->where('collateral_type', 'FixedDeposit')->count());
        $this->assertSame('Active', $this->activeLien($loan->id)->lien_status);
        $this->assertEquals('100000.00', $fd->fresh()->lien_amount);
        // the edit form may not reset the policy rate
        $this->assertEquals('10.000', $loan->fresh()->interest_rate_percent);
        $this->assertSame('annual', $loan->fresh()->rate_period);
    }

    public function test_editing_cannot_raise_the_amount_past_the_fd_limit_or_swap_customer(): void
    {
        $fd = $this->fd();
        $loan = $this->createLoan($fd);
        $payload = [
            'branch_id' => $this->branchId, 'customer_id' => $this->customerId, 'loan_type_id' => $this->loanTypeId,
            'principal_amount' => 95000, 'term_months' => 10, 'installment_frequency' => 'monthly', 'interest_rate_percent' => 1,
        ];
        $this->putJson("/api/loans/{$loan->id}", $payload)->assertStatus(422);

        $other = $this->customer();
        $this->putJson("/api/loans/{$loan->id}", array_merge($payload, ['principal_amount' => 85000, 'customer_id' => $other]))->assertStatus(200);
        $fresh = $loan->fresh();
        $this->assertSame($this->customerId, (int) $fresh->customer_id, 'customer cannot be swapped away from the FD owner');
        $this->assertEquals('10.000', $fresh->interest_rate_percent, 'rate stays the policy rate');
        $this->assertEquals('85000.00', $fresh->principal_amount);
    }

    public function test_plain_loan_api_cannot_forge_an_fd_collateral_row(): void
    {
        $fd = $this->fd();
        $loan = $this->createLoan($fd);

        $r = $this->postJson('/api/loans', [
            'branch_id' => $this->branchId, 'customer_id' => $this->customerId, 'loan_type_id' => $this->loanTypeId,
            'principal_amount' => 1000, 'term_months' => 5, 'installment_frequency' => 'monthly', 'interest_rate_percent' => 5,
            'collateral' => [['collateral_type' => 'FixedDeposit', 'description' => 'fake FD']],
        ]);
        $r->assertStatus(201);
        $this->assertSame(0, DB::table('facility_collateral')->where('credit_facility_id', $r->json('id'))->count());
        $this->assertNotSame($loan->id, $r->json('id'));
    }

    // =============================================================
    // Settlement from FD
    // =============================================================

    public function test_settle_pays_off_the_loan_and_returns_the_excess(): void
    {
        $fd = $this->fd(['start_date' => now()->subYear()->toDateString()]);
        $loan = $this->approveAndDisburse($this->createLoan($fd, ['principal_amount' => 50000, 'term_months' => 10]));
        $fd->update(['maturity_date' => now()->subDay()->toDateString()]); // matured: payout = 100,000 + 5,000 interest

        $balance = LoanPaymentAllocator::outstandingBalance($loan);
        $this->assertGreaterThan(50000, $balance);
        $this->assertLessThan(105000, $balance);
        $tillBefore = (float) $this->till->fresh()->current_balance;

        $r = $this->postJson("/api/loans/{$loan->id}/settle-from-fd", []);
        $r->assertStatus(200);

        $this->assertEqualsWithDelta($balance, $r->json('applied_to_loan'), 0.01);
        $this->assertEqualsWithDelta(105000 - $balance, $r->json('paid_to_customer'), 0.01);
        $this->assertEquals(0, $r->json('remaining_loan_balance'));

        $this->assertSame('Closed', $loan->fresh()->status);
        $this->assertEquals(0, (float) $loan->fresh()->outstanding_balance_cache);

        $fd = $fd->fresh();
        $this->assertSame('Closed', $fd->status);
        $this->assertSame(0, (int) $fd->lien_amount);

        $lien = $this->activeLien($loan->id);
        $this->assertSame('Settled', $lien->lien_status);        // not 'Released', although the loan closed
        $this->assertEqualsWithDelta($balance, (float) $lien->settled_amount, 0.01);

        $w = DB::table('fd_withdrawals')->where('fixed_deposit_id', $fd->id)->first();
        $this->assertSame($loan->id, (int) $w->settled_credit_facility_id);
        $this->assertEquals('105000.00', $w->total_amount);
        $this->assertEqualsWithDelta($balance, (float) $w->adjusted_amount, 0.01);
        $this->assertSame($w->id, (int) $lien->settlement_withdrawal_id);

        $this->assertSame(1, DB::table('facility_payments')->where('credit_facility_id', $loan->id)->where('payment_method', 'FdAdjustment')->count());

        // only the excess leaves the till
        $this->assertEqualsWithDelta(105000 - $balance, $tillBefore - (float) $this->till->fresh()->current_balance, 0.01);

        // one balanced entry: Dr 2010 100,000 + Dr 5100 5,000 = Cr loan buckets + Cr till
        $entry = DB::table('journal_entries')->where('source_type', 'FdWithdrawal')->where('source_id', $w->id)->first();
        $this->assertNotNull($entry);
        $lines = DB::table('journal_lines')->where('journal_entry_id', $entry->id)->get();
        $this->assertEqualsWithDelta(105000, $lines->sum('debit'), 0.01);
        $this->assertEqualsWithDelta($lines->sum('debit'), $lines->sum('credit'), 0.01);
        $this->assertEqualsWithDelta(100000, (float) $lines->firstWhere('account_id', ChartOfAccount::system('2010')->id)->debit, 0.01);
        $this->assertEqualsWithDelta(5000, (float) $lines->firstWhere('account_id', ChartOfAccount::system('5100')->id)->debit, 0.01);
    }

    public function test_settle_with_shortfall_keeps_the_loan_active(): void
    {
        // 30% p.a. fixed rate: 90,000 + 27,000 interest = 117,000 owed against a 100,000 payout
        DB::table('fd_loan_policies')->where('funding_product_id', $this->productId)->update(['rate_mode' => 'fixed', 'fixed_rate_percent' => '30.000']);
        $fd = $this->fd(['start_date' => now()->subYear()->toDateString(), 'total_interest_amount' => '0.00', 'maturity_amount' => '100000.00']);
        $loan = $this->approveAndDisburse($this->createLoan($fd, ['principal_amount' => 90000, 'term_months' => 11]));
        $fd->update(['maturity_date' => now()->subDay()->toDateString()]); // payout = 100,000 only

        $balance = LoanPaymentAllocator::outstandingBalance($loan);
        $this->assertGreaterThan(100000, $balance, 'fixture: 90,000 + interest must exceed the FD payout');

        $r = $this->postJson("/api/loans/{$loan->id}/settle-from-fd", []);
        $r->assertStatus(200);

        $this->assertEqualsWithDelta(100000, $r->json('applied_to_loan'), 0.01);
        $this->assertEquals(0, $r->json('paid_to_customer'));
        $this->assertEqualsWithDelta($balance - 100000, $r->json('remaining_loan_balance'), 0.01);
        $this->assertSame('Active', $loan->fresh()->status);
        $this->assertSame('Settled', $this->activeLien($loan->id)->lien_status);
        $this->assertSame('Closed', $fd->fresh()->status);
        $this->assertEqualsWithDelta($balance - 100000, LoanPaymentAllocator::outstandingBalance($loan->fresh()), 0.01);
    }

    public function test_settle_before_maturity_needs_confirmation_and_forfeits_interest(): void
    {
        $fd = $this->fd();
        $loan = $this->approveAndDisburse($this->createLoan($fd, ['principal_amount' => 50000]));

        $this->postJson("/api/loans/{$loan->id}/settle-from-fd", [])->assertStatus(422);
        $this->assertSame('Active', $fd->fresh()->status);
        $this->assertSame('Active', $this->activeLien($loan->id)->lien_status);

        $this->postJson("/api/loans/{$loan->id}/settle-from-fd", ['allow_premature' => true])->assertStatus(200);
        $w = DB::table('fd_withdrawals')->where('fixed_deposit_id', $fd->id)->first();
        $this->assertEquals('100000.00', $w->total_amount);   // principal only
        $this->assertEquals('0.00', $w->interest_amount);
    }

    public function test_settle_refuses_without_a_lien_or_when_not_active(): void
    {
        $fd = $this->fd();
        $pending = $this->createLoan($fd);
        $this->postJson("/api/loans/{$pending->id}/settle-from-fd", ['allow_premature' => true])->assertStatus(422);

        $plain = $this->approveAndDisburse($this->createLoan($this->fd(), ['principal_amount' => 1000]));
        DB::table('facility_collateral_fd_details')->where('credit_facility_id', $plain->id)->update(['lien_status' => 'Released', 'released_at' => now()]);
        $this->postJson("/api/loans/{$plain->id}/settle-from-fd", ['allow_premature' => true])->assertStatus(422);
    }

    public function test_settlement_cannot_be_voided_from_either_side(): void
    {
        $fd = $this->fd(['start_date' => now()->subYear()->toDateString()]);
        $loan = $this->approveAndDisburse($this->createLoan($fd, ['principal_amount' => 50000]));
        $fd->update(['maturity_date' => now()->subDay()->toDateString()]);
        $this->postJson("/api/loans/{$loan->id}/settle-from-fd", [])->assertStatus(200);

        $this->postJson("/api/fixed-deposits/{$fd->id}/void-withdrawal")->assertStatus(422);
        $paymentId = DB::table('facility_payments')->where('credit_facility_id', $loan->id)->value('id');
        $this->postJson("/api/loan-payments/{$paymentId}/void-request", ['void_reason' => 'x'])->assertStatus(422);
    }

    public function test_second_settle_is_refused(): void
    {
        $fd = $this->fd(['start_date' => now()->subYear()->toDateString()]);
        $loan = $this->approveAndDisburse($this->createLoan($fd, ['principal_amount' => 50000]));
        $fd->update(['maturity_date' => now()->subDay()->toDateString()]);
        $this->postJson("/api/loans/{$loan->id}/settle-from-fd", [])->assertStatus(200);
        $this->postJson("/api/loans/{$loan->id}/settle-from-fd", [])->assertStatus(422);
        $this->assertSame(1, DB::table('fd_withdrawals')->where('fixed_deposit_id', $fd->id)->count());
    }

    // =============================================================
    // Auto-adjust command
    // =============================================================

    public function test_auto_adjust_command_settles_only_opted_in_matured_fds(): void
    {
        $fd = $this->fd(['start_date' => now()->subYear()->toDateString()]);
        $loan = $this->approveAndDisburse($this->createLoan($fd, ['principal_amount' => 50000]));
        $fd->update(['maturity_date' => now()->subDay()->toDateString()]);

        $this->artisan('fd-loans:auto-adjust')->assertExitCode(0);
        $this->assertSame('Active', $this->activeLien($loan->id)->lien_status, 'policy not opted in => untouched');

        DB::table('fd_loan_policies')->where('funding_product_id', $this->productId)->update(['auto_adjust_on_maturity' => 1]);
        $this->artisan('fd-loans:auto-adjust')->assertExitCode(0);

        $this->assertSame('Settled', $this->activeLien($loan->id)->lien_status);
        $this->assertSame('Closed', $loan->fresh()->status);
    }

    // =============================================================
    // Reschedule keeps the FD pledged (transfer)
    // =============================================================

    public function test_transfer_moves_the_lien_to_the_replacement_loan(): void
    {
        $fd = $this->fd();
        $old = $this->createLoan($fd);
        $new = Loan::findOrFail(DB::table('credit_facilities')->insertGetId([
            'public_uuid' => (string) Str::uuid(), 'code' => 'R' . Str::random(8), 'facility_type' => 'Loan', 'branch_id' => $this->branchId,
            'customer_id' => $this->customerId, 'principal_amount' => '1000.00', 'interest_rate_percent' => '10.000',
            'rate_period' => 'annual', 'term_months' => 5, 'status' => 'Active', 'created_at' => now(), 'updated_at' => now(),
        ]));

        FdLienService::transfer($old, $new);
        $old->update(['status' => 'Restructured']);

        $this->assertSame('Active', $this->activeLien($new->id)->lien_status);
        $this->assertSame(0, DB::table('facility_collateral_fd_details')->where('credit_facility_id', $old->id)->count());
        $this->assertEquals('100000.00', $fd->fresh()->lien_amount, 'FD stays pledged through a reschedule');

        // closing the new loan frees it
        $new->update(['status' => 'Closed']);
        $this->assertSame(0, (int) $fd->fresh()->lien_amount);
    }

    // =============================================================
    // Policy API
    // =============================================================

    public function test_policy_save_validates_and_clears_optional_caps(): void
    {
        $url = "/api/fd-loan-policies/{$this->productId}";
        $this->putJson($url, ['max_ltv_percent' => 101, 'rate_mode' => 'spread', 'rate_spread_percent' => 2])->assertStatus(422);
        $this->putJson($url, ['max_ltv_percent' => 80, 'rate_mode' => 'fixed'])->assertStatus(422);
        $this->putJson($url, ['max_ltv_percent' => 80, 'rate_mode' => 'spread', 'rate_spread_percent' => 2, 'min_loan_amount' => 500, 'max_loan_amount' => 100])->assertStatus(422);

        DB::table('fd_loan_policies')->where('funding_product_id', $this->productId)->update(['max_loan_amount' => '1000.00']);
        $this->putJson($url, ['max_ltv_percent' => 80, 'rate_mode' => 'spread', 'rate_spread_percent' => 3, 'max_loan_amount' => null])->assertStatus(200);

        $p = DB::table('fd_loan_policies')->where('funding_product_id', $this->productId)->first();
        $this->assertEquals('80.00', $p->max_ltv_percent);
        $this->assertNull($p->max_loan_amount, 'an explicit null clears the cap');
        $this->assertSame(1, DB::table('fd_loan_policies')->where('funding_product_id', $this->productId)->count());
    }
}
