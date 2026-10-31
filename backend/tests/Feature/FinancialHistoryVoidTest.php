<?php

namespace Tests\Feature;

use App\Models\CashAccount;
use App\Models\ChartOfAccount;
use App\Models\FdPartPayment;
use App\Models\FdWithdrawal;
use App\Models\FixedDeposit;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Financial history is voided, never deleted: a voided withdrawal / part payment stays in its table with
 * who/when/why, normal reads no longer see it, and the action can be redone. Also: a void is refused when the
 * FD has moved on, and the second of two identical actions is refused instead of applied twice.
 */
class FinancialHistoryVoidTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private int $branchId;

    private int $customerId;

    private int $productId;

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
            'name' => 'PW Branch', 'code' => 'PW' . Str::random(3), 'branch_type' => 'Branch', 'status' => 'Active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->customerId = DB::table('customers')->insertGetId([
            'public_uuid' => (string) Str::uuid(), 'branch_id' => $this->branchId, 'code' => 'C' . Str::random(7),
            'full_name' => 'PW Customer', 'status' => 'Active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->till = CashAccount::create([
            'code' => 'PW-CASH-' . Str::random(6), 'branch_id' => $this->branchId, 'account_type' => 'BranchMain',
            'name' => 'PW Till', 'opening_balance' => 0, 'current_balance' => '10000000.00', 'status' => 'Active',
        ]);
        ChartOfAccount::forCashTill($this->till->fresh());
        $this->productId = $this->product('4.000');

        $this->actingAs($this->admin, 'sanctum');
    }

    private function product(?string $prematureRate): int
    {
        return DB::table('funding_products')->insertGetId([
            'name' => 'PW Product ' . Str::random(4), 'code' => 'PP' . Str::random(5), 'interest_method' => 'maturity', 'annual_rate_percent' => '8.000',
            'allowed_terms_months' => json_encode([12]), 'min_deposit_amount' => '1000.00', 'status' => 'Active',
            'premature_withdrawal_rate_percent' => $prematureRate,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function fd(array $o = []): FixedDeposit
    {
        $id = DB::table('fixed_deposits')->insertGetId(array_merge([
            'public_uuid' => (string) Str::uuid(), 'fd_number' => 'FD' . Str::random(8), 'branch_id' => $this->branchId,
            'customer_id' => $this->customerId, 'funding_product_id' => $this->productId,
            'principal_amount' => '100000.00', 'interest_rate_percent' => '8.000', 'interest_payment_method' => 'maturity',
            'term_months' => 12, 'start_date' => now()->subDays(100)->toDateString(), 'maturity_date' => now()->addDays(265)->toDateString(),
            'total_interest_amount' => '8000.00', 'maturity_amount' => '108000.00', 'status' => 'Active',
            'created_at' => now(), 'updated_at' => now(),
        ], $o));

        return FixedDeposit::findOrFail($id);
    }

    public function test_voiding_a_withdrawal_keeps_the_row_and_allows_withdrawing_again(): void
    {
        $fd = $this->fd();
        $this->postJson("/api/fixed-deposits/{$fd->id}/premature-withdraw", ['payment_method' => 'Cash'])->assertStatus(201);
        $this->postJson("/api/fixed-deposits/{$fd->id}/void-withdrawal")->assertOk();

        $this->assertSame(0, FdWithdrawal::where('fixed_deposit_id', $fd->id)->count(), 'live reads must not see the voided row');
        $voided = FdWithdrawal::withVoided()->where('fixed_deposit_id', $fd->id)->first();
        $this->assertNotNull($voided, 'the voided row must be kept');
        $this->assertNotNull($voided->voided_at);
        $this->assertSame($this->admin->id, (int) $voided->voided_by);

        // The one-live-withdrawal-per-FD key ignores voided rows, so the FD can be closed again.
        $this->postJson("/api/fixed-deposits/{$fd->id}/premature-withdraw", ['payment_method' => 'Cash'])->assertStatus(201);
        $this->assertSame(1, FdWithdrawal::where('fixed_deposit_id', $fd->id)->count());
        $this->assertSame(2, FdWithdrawal::withVoided()->where('fixed_deposit_id', $fd->id)->count());
    }

    public function test_part_payment_void_keeps_the_row_and_is_refused_twice_and_after_the_fd_closed(): void
    {
        $fd = $this->fd();
        $this->postJson("/api/fixed-deposits/{$fd->id}/part-payment", ['amount' => 10000, 'payment_method' => 'Cash'])->assertStatus(201);
        $pp = FdPartPayment::where('fixed_deposit_id', $fd->id)->firstOrFail();
        $this->assertEqualsWithDelta(90000.0, (float) $fd->fresh()->principal_amount, 0.001);

        $this->postJson("/api/fd-part-payments/{$pp->id}/void")->assertOk();
        $this->assertEqualsWithDelta(100000.0, (float) $fd->fresh()->principal_amount, 0.001);
        $this->assertSame(0, FdPartPayment::where('fixed_deposit_id', $fd->id)->count());
        $this->assertNotNull(FdPartPayment::withVoided()->find($pp->id)?->voided_at);

        // A voided row is invisible to route binding (404): principal cannot be restored a second time.
        $this->postJson("/api/fd-part-payments/{$pp->id}/void")->assertStatus(404);
        $this->assertEqualsWithDelta(100000.0, (float) $fd->fresh()->principal_amount, 0.001);
    }

    public function test_a_part_payment_cannot_be_voided_once_the_fd_is_closed(): void
    {
        $fd = $this->fd();
        $this->postJson("/api/fixed-deposits/{$fd->id}/part-payment", ['amount' => 10000, 'payment_method' => 'Cash'])->assertStatus(201);
        $pp = FdPartPayment::where('fixed_deposit_id', $fd->id)->firstOrFail();
        $this->postJson("/api/fixed-deposits/{$fd->id}/premature-withdraw", ['payment_method' => 'Cash'])->assertStatus(201);
        $closedPrincipal = (float) $fd->fresh()->principal_amount;

        $this->postJson("/api/fd-part-payments/{$pp->id}/void")->assertStatus(422);
        $this->assertEqualsWithDelta($closedPrincipal, (float) $fd->fresh()->principal_amount, 0.001);
        $this->assertSame('Closed', $fd->fresh()->status);
    }

    public function test_a_second_part_payment_that_would_use_a_stale_principal_is_refused(): void
    {
        $fd = $this->fd();
        // Simulates the race: the request read principal 100000, but another part payment committed first.
        $this->postJson("/api/fixed-deposits/{$fd->id}/part-payment", ['amount' => 60000, 'payment_method' => 'Cash'])->assertStatus(201);
        $this->postJson("/api/fixed-deposits/{$fd->id}/part-payment", ['amount' => 60000, 'payment_method' => 'Cash'])->assertStatus(422);
        $this->assertEqualsWithDelta(40000.0, (float) $fd->fresh()->principal_amount, 0.001);
        $this->assertSame(1, FdPartPayment::where('fixed_deposit_id', $fd->id)->count());
    }

    public function test_audit_events_are_written_for_a_successful_write_without_secrets(): void
    {
        $fd = $this->fd();
        $this->postJson("/api/fixed-deposits/{$fd->id}/part-payment", ['amount' => 1000, 'payment_method' => 'Cash', 'password' => 'secret'])->assertStatus(201);
        $row = DB::table('audit_events')->where('path', "api/fixed-deposits/{$fd->id}/part-payment")->latest('id')->first();
        $this->assertNotNull($row);
        $this->assertSame((int) $this->admin->id, (int) $row->user_id);
        $this->assertStringNotContainsString('secret', (string) $row->payload);
    }
}
