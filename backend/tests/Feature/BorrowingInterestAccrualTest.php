<?php

namespace Tests\Feature;

use App\Models\Borrowing;
use App\Models\CashAccount;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Borrowing interest accrues to 2220 (Dr 5110 / Cr 2220) and an interest
 * payment clears 2220 before it touches 5110. Real chart / users from the
 * cloned working data; dates are anchored in the past so day counts are exact.
 */
class BorrowingInterestAccrualTest extends TestCase
{
    use DatabaseTransactions;

    private CashAccount $till;

    private int $branchId;

    private string $start;

    protected function setUp(): void
    {
        parent::setUp();
        ChartOfAccount::where('code', '2220')->exists() ?: $this->markTestSkipped('Chart lacks 2220 - run the borrowing accrual migration on the test DB.');
        $admin = User::whereHas('role', fn ($q) => $q->where('slug', 'admin'))->first() ?? $this->markTestSkipped('No admin user.');
        $this->actingAs($admin, 'sanctum');
        $this->branchId = DB::table('branches')->insertGetId(['name' => 'IA', 'code' => 'IA' . Str::random(3), 'branch_type' => 'Branch', 'status' => 'Active', 'created_at' => now(), 'updated_at' => now()]);
        $this->till = CashAccount::create(['code' => 'IA-CASH-' . Str::random(3), 'branch_id' => $this->branchId, 'account_type' => 'BranchMain', 'name' => 'IA Till', 'opening_balance' => 0, 'current_balance' => '50000.00', 'status' => 'Active']);
        $this->start = now()->subDays(61)->toDateString();
    }

    private function bal(string $code): string
    {
        $r = DB::table('journal_lines')->where('account_id', ChartOfAccount::system($code)->id)
            ->selectRaw('COALESCE(SUM(debit),0) d, COALESCE(SUM(credit),0) c')->first();

        return bcsub((string) $r->d, (string) $r->c, 2);
    }

    private function h(): array
    {
        return ['Idempotency-Key' => (string) Str::uuid()];
    }

    private function borrow(array $o = []): Borrowing
    {
        $res = $this->postJson('/api/borrowings', $o + [
            'branch_id' => $this->branchId, 'type' => 'Party', 'lender_name' => 'ACME Finance PLC', 'principal_amount' => '10000.00',
            'interest_rate_percent' => 12, 'term_months' => 12, 'borrowing_date' => $this->start,
            'receipt_method' => 'Cash', 'receipt_cash_account_id' => $this->till->id,
        ], $this->h());
        $res->assertStatus(201);

        return Borrowing::find($res->json('id'));
    }

    private function pay(Borrowing $b, string $kind, string $amount, ?string $date = null)
    {
        return $this->postJson("/api/borrowings/{$b->id}/transactions", [
            'kind' => $kind, 'amount' => $amount, 'txn_date' => $date ?? now()->toDateString(),
            'payment_method' => 'Cash', 'cash_account_id' => $this->till->id,
        ], $this->h());
    }

    public function test_monthly_accrual_is_exact_idempotent_and_posts_dr_5110_cr_2220(): void
    {
        $b = $this->borrow();
        $this->assertSame($this->start, $b->interest_accrued_through->toDateString());
        [$e0, $p0] = [$this->bal('5110'), $this->bal('2220')];

        $mid = now()->subDays(31)->toDateString(); // 30 days after start
        Artisan::call('borrowings:accrue-interest', ['--through' => $mid]);
        $this->assertSame('98.63', (string) $b->fresh()->accrued_interest_unpaid, '10000 x 12% x 30/365');
        $this->assertSame(bcadd($e0, '98.63', 2), $this->bal('5110'));
        $this->assertSame(bcsub($p0, '98.63', 2), $this->bal('2220'), '2220 is credit-normal');

        Artisan::call('borrowings:accrue-interest', ['--through' => $mid]); // re-run: nothing new
        $this->assertSame('98.63', (string) $b->fresh()->accrued_interest_unpaid);
        $this->assertSame(bcadd($e0, '98.63', 2), $this->bal('5110'));

        Artisan::call('borrowings:accrue-interest', ['--through' => now()->toDateString()]); // next 31 days
        $this->assertSame('101.92', bcsub((string) $b->fresh()->accrued_interest_unpaid, '98.63', 2), '10000 x 12% x 31/365');
        $je = JournalEntry::where('source_type', 'BorrowingAccrual')->where('source_id', $b->id)->get();
        $this->assertCount(2, $je);
        $this->assertSame('Posted', $je->first()->status);
    }

    public function test_interest_payment_clears_2220_first_then_expenses_the_rest_and_void_restores(): void
    {
        $b = $this->borrow();
        Artisan::call('borrowings:accrue-interest', ['--through' => now()->toDateString()]); // 61 days
        $this->assertSame('200.55', (string) $b->fresh()->accrued_interest_unpaid, '10000 x 12% x 61/365');
        [$e0, $p0, $c0] = [$this->bal('5110'), $this->bal('2220'), (string) $this->till->fresh()->current_balance];

        // pay less than accrued: all of it clears 2220, no new expense
        $this->pay($b, 'InterestPayment', '150.00')->assertStatus(201);
        $this->assertSame($e0, $this->bal('5110'));
        $this->assertSame(bcadd($p0, '150.00', 2), $this->bal('2220'));
        $this->assertSame('50.55', (string) $b->fresh()->accrued_interest_unpaid);

        // pay more than what is left: 50.55 clears 2220, 49.45 is new expense
        $res = $this->pay($b, 'InterestPayment', '100.00');
        $res->assertStatus(201);
        $this->assertSame(bcadd($e0, '49.45', 2), $this->bal('5110'));
        $this->assertSame(bcadd($p0, '200.55', 2), $this->bal('2220'));
        $this->assertSame('0.00', (string) $b->fresh()->accrued_interest_unpaid);
        $this->assertSame(bcsub($c0, '250.00', 2), (string) $this->till->fresh()->current_balance);

        // void the latest payment: unpaid, 5110, 2220 and the till all go back
        $lastId = collect($res->json('transactions'))->max('id');
        $this->postJson("/api/borrowing-transactions/{$lastId}/void")->assertOk();
        $this->assertSame('50.55', (string) $b->fresh()->accrued_interest_unpaid);
        $this->assertSame($e0, $this->bal('5110'));
        $this->assertSame(bcadd($p0, '150.00', 2), $this->bal('2220'));
        $this->assertSame(bcsub($c0, '150.00', 2), (string) $this->till->fresh()->current_balance);
    }

    public function test_principal_change_accrues_old_balance_first_and_renewal_accrues_before_rate_change(): void
    {
        $b = $this->borrow();
        // repay 4 000 on day 30: the first 30 days are earned on 10 000, afterwards on 6 000
        $this->pay($b, 'PrincipalRepayment', '4000.00', now()->subDays(31)->toDateString())->assertStatus(201);
        $this->assertSame('98.63', (string) $b->fresh()->accrued_interest_unpaid);

        // renew on day 41 at 24%: 11 days at the old 12% on 6 000 are booked before the rate changes
        $this->postJson("/api/borrowings/{$b->id}/renew", [
            'renewed_on' => now()->subDays(20)->toDateString(), 'new_maturity_date' => now()->addYear()->toDateString(), 'new_interest_rate_percent' => 24,
        ], $this->h())->assertOk();
        $this->assertSame('120.33', (string) $b->fresh()->accrued_interest_unpaid, '98.63 + 6000 x 12% x 11/365 (21.70)');
        $this->assertSame('24.000', (string) $b->fresh()->interest_rate_percent);
    }

    public function test_delete_reverses_the_accruals(): void
    {
        $b = $this->borrow();
        [$e0, $p0] = [$this->bal('5110'), $this->bal('2220')];
        Artisan::call('borrowings:accrue-interest', ['--through' => now()->toDateString()]);
        $this->assertNotSame($e0, $this->bal('5110'));

        $this->deleteJson("/api/borrowings/{$b->id}")->assertStatus(204);
        $this->assertSame($e0, $this->bal('5110'));
        $this->assertSame($p0, $this->bal('2220'));
    }

    public function test_overdraft_is_not_accrued(): void
    {
        $bank = \App\Models\BankAccount::where('account_type', 'Current')->where('status', 'Active')->first() ?? $this->markTestSkipped('No Current bank account.');
        $res = $this->postJson('/api/borrowings', [
            'branch_id' => $this->branchId, 'type' => 'Overdraft', 'lender_name' => 'Bank', 'principal_amount' => '1000.00',
            'interest_rate_percent' => 20, 'borrowing_date' => $this->start, 'bank_account_id' => $bank->id,
        ], $this->h());
        $res->assertStatus(201);
        $e0 = $this->bal('5110');
        Artisan::call('borrowings:accrue-interest', ['--through' => now()->toDateString()]);
        $this->assertSame($e0, $this->bal('5110'));
        $this->assertNull(Borrowing::find($res->json('id'))->interest_accrued_through);
    }
}
