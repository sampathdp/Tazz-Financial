<?php

namespace Tests\Feature;

use App\Models\CashAccount;
use App\Models\ChartOfAccount;
use App\Models\FdCommission;
use App\Models\FixedDeposit;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Commission set by hand on an FD (to staff or an outsider), paid manually afterwards through the money
 * writer, plus joint holder / nominee validation and the optional payout account.
 */
class FdCommissionTest extends TestCase
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
        if (! ChartOfAccount::where('code', '5200')->exists()) {
            $this->markTestSkipped('Chart of accounts not seeded.');
        }
        $this->admin = User::whereHas('role', fn ($q) => $q->where('slug', 'admin'))->first()
            ?? $this->markTestSkipped('No admin user seeded.');

        $this->branchId = DB::table('branches')->insertGetId([
            'name' => 'FC Branch', 'code' => 'F' . Str::random(2), 'branch_type' => 'Branch', 'status' => 'Active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->customerId = $this->customer('FC Customer');
        $this->till = CashAccount::create([
            'code' => 'FC-CASH-' . Str::random(6), 'branch_id' => $this->branchId, 'account_type' => 'BranchMain',
            'name' => 'FC Till', 'opening_balance' => 0, 'current_balance' => '1000000.00', 'status' => 'Active',
        ]);
        ChartOfAccount::forCashTill($this->till->fresh());
        $this->productId = DB::table('funding_products')->insertGetId([
            'name' => 'FC Product ' . Str::random(4), 'code' => 'P' . Str::random(2), 'interest_method' => 'maturity', 'annual_rate_percent' => '20.000',
            'allowed_terms_months' => json_encode([12]), 'min_deposit_amount' => '1000.00', 'status' => 'Active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($this->admin, 'sanctum');
    }

    private function customer(string $name): int
    {
        return DB::table('customers')->insertGetId([
            'public_uuid' => (string) Str::uuid(), 'branch_id' => $this->branchId, 'code' => 'C' . Str::random(7),
            'full_name' => $name, 'status' => 'Active', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function availableDeposit(): int
    {
        return DB::table('fd_deposits')->insertGetId([
            'deposit_number' => 'DEP' . Str::random(8), 'branch_id' => $this->branchId, 'customer_id' => $this->customerId,
            'deposit_date' => now()->toDateString(), 'amount' => '100000.00', 'payment_method' => 'Cash', 'status' => 'Available',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function createBody(array $extra = []): array
    {
        return array_merge([
            'deposit_id' => $this->availableDeposit(), 'funding_product_id' => $this->productId,
            'term_months' => 12, 'start_date' => now()->toDateString(),
        ], $extra);
    }

    public function test_fd_can_be_created_without_a_payout_account_and_with_a_lower_customer_rate(): void
    {
        $res = $this->postJson('/api/fixed-deposits', $this->createBody(['interest_rate_percent' => 17.5]))->assertStatus(201);

        $this->assertNull($res->json('customer_bank_detail_id'));
        $this->assertEquals(17.5, (float) $res->json('interest_rate_percent'));
        $this->assertEquals(17500.0, (float) $res->json('total_interest_amount'));
    }

    public function test_manual_commission_joint_holder_and_nominee_are_saved_with_the_fd(): void
    {
        $joint = $this->customer('FC Joint');
        $res = $this->postJson('/api/fixed-deposits', $this->createBody([
            'commission' => ['recipient_type' => 'outsider', 'recipient_name' => 'Agent A', 'commission_type' => 'percentage', 'commission_value' => 2],
            'joint_holders' => [['customer_id' => $joint, 'relationship' => 'Spouse']],
            'nominees' => [['full_name' => 'Nominee One', 'relationship' => 'Son']],
        ]))->assertStatus(201);

        $res->assertJsonPath('commissions.0.recipient_name', 'Agent A')
            ->assertJsonPath('commissions.0.commission_amount', '2000.00')
            ->assertJsonPath('joint_holders.0.customer_id', $joint)
            ->assertJsonPath('nominees.0.share_percent', '100.00');
    }

    public function test_joint_holder_cannot_be_the_primary_customer_and_nominee_shares_must_total_100(): void
    {
        $this->postJson('/api/fixed-deposits', $this->createBody(['joint_holders' => [['customer_id' => $this->customerId]]]))
            ->assertStatus(422);
        $this->postJson('/api/fixed-deposits', $this->createBody(['nominees' => [
            ['full_name' => 'A', 'share_percent' => 60], ['full_name' => 'B', 'share_percent' => 30],
        ]]))->assertStatus(422);
    }

    public function test_commission_is_paid_manually_moves_the_till_and_can_be_voided(): void
    {
        $fdId = $this->postJson('/api/fixed-deposits', $this->createBody())->assertStatus(201)->json('id');
        $c = $this->postJson("/api/fixed-deposits/{$fdId}/commissions", [
            'recipient_type' => 'outsider', 'recipient_name' => 'Agent B', 'commission_type' => 'fixed', 'commission_value' => 1500,
        ])->assertStatus(201);
        $this->assertSame('Pending', $c->json('commission_status'));
        $id = $c->json('id');

        $before = (float) $this->till->fresh()->current_balance;
        $this->postJson("/api/fd-commissions/{$id}/pay", [
            'payment_date' => now()->toDateString(), 'payment_amount' => 1500, 'payment_method' => 'Cash', 'cash_account_id' => $this->till->id,
        ])->assertOk()->assertJsonPath('commission_status', 'Paid');
        $this->assertEqualsWithDelta(1500, $before - (float) $this->till->fresh()->current_balance, 0.001);

        $this->postJson("/api/fd-commissions/{$id}/pay", [
            'payment_date' => now()->toDateString(), 'payment_amount' => 1500, 'payment_method' => 'Cash', 'cash_account_id' => $this->till->id,
        ])->assertStatus(422);

        $this->postJson("/api/fd-commissions/{$id}/void")->assertOk()->assertJsonPath('commission_status', 'Pending');
        $this->assertEqualsWithDelta($before, (float) $this->till->fresh()->current_balance, 0.001);
    }

    public function test_renewal_can_carry_a_new_commission_and_keeps_joint_holders_and_nominees(): void
    {
        $joint = $this->customer('FC Joint 2');
        $fd = $this->postJson('/api/fixed-deposits', $this->createBody([
            'start_date' => now()->subYear()->toDateString(),
            'joint_holders' => [['customer_id' => $joint]],
            'nominees' => [['full_name' => 'Nominee Two']],
        ]))->assertStatus(201)->json('id');
        FixedDeposit::whereKey($fd)->update(['maturity_date' => now()->subDay()->toDateString()]);

        $res = $this->postJson("/api/fixed-deposits/{$fd}/renew", [
            'term_months' => 12, 'interest_treatment' => 'capitalise', 'interest_rate_percent' => 15,
            'commission' => ['recipient_type' => 'outsider', 'recipient_name' => 'Agent C', 'commission_type' => 'percentage', 'commission_value' => 1],
        ])->assertStatus(201);

        $new = $res->json('fixed_deposit');
        $this->assertEquals(15.0, (float) $new['interest_rate_percent']);
        $this->assertSame('Agent C', $new['commissions'][0]['recipient_name']);
        $this->assertSame($joint, $new['joint_holders'][0]['customer_id']);
        $this->assertSame('Nominee Two', $new['nominees'][0]['full_name']);
        $this->assertSame(1, FdCommission::where('fixed_deposit_id', $new['id'])->count());
    }
}
