<?php

namespace Tests\Feature;

use App\Models\CashAccount;
use App\Models\ChartOfAccount;
use App\Models\FdWithdrawal;
use App\Models\FixedDeposit;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Full early closure of an FD (POST /fixed-deposits/{id}/premature-withdraw): the whole principal earns
 * the product's premature rate for the days held, monthly interest already paid is netted off, and the
 * ledger + till move by exactly the amount paid out.
 *
 * Fixture: FD of 100,000 started 100 days ago, premature rate 4% => entitlement 100000 x 4% x 100/365 = 1,095.89.
 */
class FdPrematureWithdrawalTest extends TestCase
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

    public function test_full_early_closure_pays_principal_plus_premature_interest_and_moves_the_till(): void
    {
        $fd = $this->fd();
        $before = (float) $this->till->fresh()->current_balance;

        $this->getJson("/api/fixed-deposits/{$fd->id}/premature-preview")
            ->assertOk()->assertJsonPath('entitlement', 1095.89)->assertJsonPath('total', 101095.89);

        $this->postJson("/api/fixed-deposits/{$fd->id}/premature-withdraw", ['payment_method' => 'Cash'])
            ->assertStatus(201)->assertJsonPath('is_premature', true)->assertJsonPath('total_amount', '101095.89');

        $this->assertSame('Closed', $fd->fresh()->status);
        $this->assertEqualsWithDelta(101095.89, $before - (float) $this->till->fresh()->current_balance, 0.001);
        $this->assertSame(1, FdWithdrawal::where('fixed_deposit_id', $fd->id)->where('is_premature', true)->count());
    }

    public function test_monthly_interest_already_paid_beyond_entitlement_is_clawed_back(): void
    {
        $fd = $this->fd(['interest_payment_method' => 'monthly', 'monthly_interest_amount' => '666.67']);
        DB::table('fd_interest_payments')->insert([
            'fixed_deposit_id' => $fd->id, 'installment_no' => 1, 'due_date' => now()->subDays(70)->toDateString(),
            'amount' => '2000.00', 'status' => 'Paid', 'paid_amount' => '2000.00', 'paid_at' => now()->subDays(70)->toDateString(),
            'payment_method' => 'Cash', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->postJson("/api/fixed-deposits/{$fd->id}/premature-withdraw", ['payment_method' => 'Cash'])
            ->assertStatus(201)->assertJsonPath('premature_clawback', '904.11')->assertJsonPath('total_amount', '99095.89');
    }

    public function test_cannot_close_early_without_a_premature_rate_when_matured_or_when_closed(): void
    {
        $noRate = $this->fd(['funding_product_id' => $this->product(null)]);
        $this->postJson("/api/fixed-deposits/{$noRate->id}/premature-withdraw", ['payment_method' => 'Cash'])->assertStatus(422);

        $matured = $this->fd(['start_date' => now()->subYear()->toDateString(), 'maturity_date' => now()->subDay()->toDateString()]);
        $this->postJson("/api/fixed-deposits/{$matured->id}/premature-withdraw", ['payment_method' => 'Cash'])->assertStatus(422);

        $fd = $this->fd();
        $this->postJson("/api/fixed-deposits/{$fd->id}/premature-withdraw", ['payment_method' => 'Cash'])->assertStatus(201);
        $this->postJson("/api/fixed-deposits/{$fd->id}/premature-withdraw", ['payment_method' => 'Cash'])->assertStatus(422);
    }

    public function test_voiding_the_early_closure_restores_the_fd_and_the_till(): void
    {
        $fd = $this->fd();
        $before = (float) $this->till->fresh()->current_balance;
        $this->postJson("/api/fixed-deposits/{$fd->id}/premature-withdraw", ['payment_method' => 'Cash'])->assertStatus(201);

        $this->postJson("/api/fixed-deposits/{$fd->id}/void-withdrawal")->assertOk();

        $this->assertSame('Active', $fd->fresh()->status);
        $this->assertEqualsWithDelta($before, (float) $this->till->fresh()->current_balance, 0.001);
    }
}
