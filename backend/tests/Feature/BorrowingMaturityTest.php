<?php

namespace Tests\Feature;

use App\Models\Borrowing;
use App\Models\CashAccount;
use App\Models\ChartOfAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Maturity handling: a renewal that pays interest records a real InterestPayment,
 * the daily auto-renew command rolls 'Renew' borrowings over (catching up missed
 * terms) without moving cash, and 'Settle' borrowings are only flagged Matured.
 */
class BorrowingMaturityTest extends TestCase
{
    use DatabaseTransactions;

    private CashAccount $till;

    private int $branchId;

    protected function setUp(): void
    {
        parent::setUp();
        ChartOfAccount::where('code', '2220')->exists() ?: $this->markTestSkipped('Chart lacks 2220 - run the borrowing accrual migration on the test DB.');
        $admin = User::whereHas('role', fn ($q) => $q->where('slug', 'admin'))->first() ?? $this->markTestSkipped('No admin user.');
        $this->actingAs($admin, 'sanctum');
        $this->branchId = DB::table('branches')->insertGetId(['name' => 'MT', 'code' => 'MT' . Str::random(3), 'branch_type' => 'Branch', 'status' => 'Active', 'created_at' => now(), 'updated_at' => now()]);
        $this->till = CashAccount::create(['code' => 'MT-CASH-' . Str::random(3), 'branch_id' => $this->branchId, 'account_type' => 'BranchMain', 'name' => 'MT Till', 'opening_balance' => 0, 'current_balance' => '50000.00', 'status' => 'Active']);
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
            'interest_rate_percent' => 12, 'term_months' => 12, 'borrowing_date' => now()->subDays(61)->toDateString(),
            'on_maturity' => 'Renew', 'receipt_method' => 'Cash', 'receipt_cash_account_id' => $this->till->id,
        ], $this->h());
        $res->assertStatus(201);

        return Borrowing::find($res->json('id'));
    }

    private function renew(Borrowing $b, array $o = [])
    {
        return $this->postJson("/api/borrowings/{$b->id}/renew", $o + [
            'renewed_on' => now()->toDateString(), 'new_maturity_date' => now()->addMonths(6)->toDateString(),
        ], $this->h());
    }

    public function test_renewal_that_pays_interest_records_a_real_payment_clearing_2220(): void
    {
        $b = $this->borrow();
        [$e0, $p0, $c0] = [$this->bal('5110'), $this->bal('2220'), (string) $this->till->fresh()->current_balance];

        $res = $this->renew($b, ['interest_paid_amount' => '200.55', 'payment_method' => 'Cash', 'cash_account_id' => $this->till->id]);
        $res->assertOk();

        // 61 days accrued at renewal (10000 x 12% x 61/365 = 200.55), all of it paid, so 2220 nets to its start
        $this->assertSame(bcadd($e0, '200.55', 2), $this->bal('5110'));
        $this->assertSame($p0, $this->bal('2220'));
        $this->assertSame('0.00', (string) $b->fresh()->accrued_interest_unpaid);
        $this->assertSame(bcsub($c0, '200.55', 2), (string) $this->till->fresh()->current_balance);
        $this->assertSame('InterestPayment', $res->json('transactions.0.kind'));
        $this->assertSame('200.55', (string) $res->json('renewals.0.interest_paid_amount'));
    }

    public function test_renewal_paying_interest_without_a_method_is_refused_and_nothing_is_booked(): void
    {
        $b = $this->borrow();
        $this->renew($b, ['interest_paid_amount' => '100.00'])->assertStatus(422)->assertJsonValidationErrors('payment_method');
        $this->assertSame(0, DB::table('borrowing_renewals')->where('borrowing_id', $b->id)->count());

        // payment that cannot post rolls the whole renewal back
        $this->till->update(['current_balance' => '1.00']);
        $res = $this->renew($b, ['interest_paid_amount' => '100.00', 'payment_method' => 'Cash', 'cash_account_id' => $this->till->id]);
        $this->assertGreaterThanOrEqual(400, $res->status());
        $this->assertSame(0, DB::table('borrowing_renewals')->where('borrowing_id', $b->id)->count());
        $this->assertSame(0, DB::table('borrowing_transactions')->where('borrowing_id', $b->id)->count());
        $this->assertSame('0.00', (string) $b->fresh()->accrued_interest_unpaid, 'accrual rolled back too');
    }

    public function test_auto_renew_rolls_matured_renew_borrowings_catching_up_terms_and_moves_no_cash(): void
    {
        // 1-month term started 61 days ago: matured ~31 days ago, and a second term matured today or tomorrow
        $b = $this->borrow(['term_months' => 1]);
        $settle = $this->borrow(['term_months' => 1, 'on_maturity' => 'Settle']);
        $cash0 = (string) $this->till->fresh()->current_balance;
        $oldMaturity = $b->maturity_date->toDateString();

        Artisan::call('borrowings:auto-renew');

        $b->refresh();
        $this->assertTrue($b->maturity_date->gt(now()->startOfDay()), 'rolled forward past today');
        $this->assertGreaterThanOrEqual(1, DB::table('borrowing_renewals')->where('borrowing_id', $b->id)->count());
        $first = DB::table('borrowing_renewals')->where('borrowing_id', $b->id)->orderBy('id')->first();
        $this->assertSame($oldMaturity, $first->renewed_on, 'first renewal is dated at the maturity it replaced');
        $this->assertSame($cash0, (string) $this->till->fresh()->current_balance, 'no cash moves');
        $this->assertSame(0, DB::table('borrowing_transactions')->where('borrowing_id', $b->id)->where('kind', 'InterestPayment')->count());
        $this->assertGreaterThan(0, (float) $b->accrued_interest_unpaid, 'interest to the old maturity stays payable');

        // second run is a no-op
        $before = DB::table('borrowing_renewals')->where('borrowing_id', $b->id)->count();
        Artisan::call('borrowings:auto-renew');
        $this->assertSame($before, DB::table('borrowing_renewals')->where('borrowing_id', $b->id)->count());

        // 'Settle' borrowing is untouched but flagged as matured for a person to settle
        $this->assertSame(0, DB::table('borrowing_renewals')->where('borrowing_id', $settle->id)->count());
        $show = $this->getJson("/api/borrowings/{$settle->id}")->assertOk();
        $this->assertTrue($show->json('is_matured'));
        $this->assertFalse($this->getJson("/api/borrowings/{$b->id}")->json('is_matured'));
    }
}
