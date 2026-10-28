<?php

namespace Tests\Feature;

use App\Models\AssetCategory;
use App\Models\CashAccount;
use App\Models\ChartOfAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Fixed assets: purchase posting, monthly straight-line depreciation (idempotent, capped at residual),
 * opening-balance assets, and disposal gain / loss.
 */
class FixedAssetTest extends TestCase
{
    use DatabaseTransactions;

    private int $branchId;

    private CashAccount $till;

    private AssetCategory $category;

    protected function setUp(): void
    {
        parent::setUp();
        if (! ChartOfAccount::where('code', '1390')->exists()) {
            $this->markTestSkipped('Fixed asset migration not run.');
        }
        $admin = User::whereHas('role', fn ($q) => $q->where('slug', 'admin'))->first()
            ?? $this->markTestSkipped('No admin user seeded.');

        $this->branchId = DB::table('branches')->insertGetId([
            'name' => 'FA Branch', 'code' => 'A' . Str::random(2), 'branch_type' => 'Branch', 'status' => 'Active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->till = CashAccount::create([
            'code' => 'FA-CASH-' . Str::random(6), 'branch_id' => $this->branchId, 'account_type' => 'BranchMain',
            'name' => 'FA Till', 'opening_balance' => 0, 'current_balance' => '1000000.00', 'status' => 'Active',
        ]);
        ChartOfAccount::forCashTill($this->till->fresh());
        $this->category = AssetCategory::where('name', 'Office Equipment')->firstOrFail();

        $this->actingAs($admin, 'sanctum');
    }

    private function buy(array $extra = []): array
    {
        return $this->postJson('/api/fixed-assets', array_merge([
            'name' => 'Laptop', 'asset_category_id' => $this->category->id, 'branch_id' => $this->branchId,
            'purchase_date' => now()->subMonths(3)->startOfMonth()->toDateString(),
            'cost' => 120000, 'residual_value' => 0, 'useful_life_months' => 12,
            'acquisition_type' => 'Purchase', 'payment_method' => 'Cash', 'cash_account_id' => $this->till->id,
        ], $extra))->assertStatus(201)->json();
    }

    public function test_purchase_moves_the_till_and_posts_to_the_category_account(): void
    {
        $before = (float) $this->till->fresh()->current_balance;
        $asset = $this->buy();

        $this->assertSame('Active', $asset['status']);
        $this->assertEqualsWithDelta(120000, $before - (float) $this->till->fresh()->current_balance, 0.001);
        $this->assertNotNull($asset['journal_entry_id']);
    }

    public function test_depreciation_run_is_monthly_straight_line_and_idempotent(): void
    {
        $asset = $this->buy();
        $through = now()->format('Y-m');

        $preview = $this->postJson('/api/fixed-assets/depreciation/preview', ['through' => $through])->assertOk();
        $this->assertCount(4, collect($preview->json('rows'))->where('asset_id', $asset['id'])); // 3 months back + this one

        $this->postJson('/api/fixed-assets/depreciation/run', ['through' => $through, 'branch_id' => $this->branchId])->assertOk();
        $shown = $this->getJson("/api/fixed-assets/{$asset['id']}")->assertOk();
        $this->assertSame('40000.00', $shown->json('accumulated_depreciation'));
        $this->assertSame('80000.00', $shown->json('net_book_value'));

        // A second run for the same month posts nothing.
        $again = $this->postJson('/api/fixed-assets/depreciation/run', ['through' => $through, 'branch_id' => $this->branchId])->assertOk();
        $this->assertCount(0, collect($again->json('rows'))->where('asset_id', $asset['id']));
        $this->assertSame('40000.00', $this->getJson("/api/fixed-assets/{$asset['id']}")->json('accumulated_depreciation'));
    }

    public function test_depreciation_never_goes_past_cost_less_residual(): void
    {
        $asset = $this->buy(['useful_life_months' => 2, 'residual_value' => 20000]);
        $this->postJson('/api/fixed-assets/depreciation/run', ['through' => now()->format('Y-m'), 'branch_id' => $this->branchId])->assertOk();

        $shown = $this->getJson("/api/fixed-assets/{$asset['id']}")->assertOk();
        $this->assertSame('100000.00', $shown->json('accumulated_depreciation'));
        $this->assertSame('FullyDepreciated', $shown->json('status'));
    }

    public function test_opening_asset_posts_to_opening_equity_and_resumes_after_its_depreciated_through_month(): void
    {
        if (! ChartOfAccount::where('code', '3015')->exists()) {
            $this->markTestSkipped('Opening balance equity account missing.');
        }
        $asset = $this->postJson('/api/fixed-assets', [
            'name' => 'Old Furniture', 'asset_category_id' => $this->category->id, 'branch_id' => $this->branchId,
            'purchase_date' => now()->subMonths(14)->toDateString(), 'cost' => 60000, 'residual_value' => 0, 'useful_life_months' => 60,
            'acquisition_type' => 'Opening', 'opening_accumulated_depreciation' => 24000,
            'opening_depreciated_through' => now()->subMonth()->toDateString(),
        ])->assertStatus(201)->json();

        $this->assertSame('24000.00', $asset['accumulated_depreciation']);
        $this->postJson('/api/fixed-assets/depreciation/run', ['through' => now()->format('Y-m'), 'branch_id' => $this->branchId])->assertOk();
        $this->assertSame('25000.00', $this->getJson("/api/fixed-assets/{$asset['id']}")->json('accumulated_depreciation'));
    }

    public function test_disposal_with_a_gain_and_a_loss_balances_and_closes_the_asset(): void
    {
        $asset = $this->buy();
        $this->postJson('/api/fixed-assets/depreciation/run', ['through' => now()->format('Y-m'), 'branch_id' => $this->branchId])->assertOk();

        // Book value is 80,000; selling for 90,000 is a 10,000 gain.
        $before = (float) $this->till->fresh()->current_balance;
        $this->postJson("/api/fixed-assets/{$asset['id']}/dispose", [
            'disposal_date' => now()->toDateString(), 'proceeds' => 90000, 'payment_method' => 'Cash', 'cash_account_id' => $this->till->id,
        ])->assertStatus(201)->assertJsonPath('gain_loss', '10000.00');
        $this->assertEqualsWithDelta(90000, (float) $this->till->fresh()->current_balance - $before, 0.001);
        $this->assertSame('Disposed', $this->getJson("/api/fixed-assets/{$asset['id']}")->json('status'));

        $this->postJson("/api/fixed-assets/{$asset['id']}/dispose", ['disposal_date' => now()->toDateString(), 'proceeds' => 0])->assertStatus(422);

        // A scrapped asset (no proceeds) books its net book value as a loss.
        $second = $this->buy();
        $this->postJson('/api/fixed-assets/depreciation/run', ['through' => now()->format('Y-m'), 'branch_id' => $this->branchId])->assertOk();
        $this->postJson("/api/fixed-assets/{$second['id']}/dispose", ['disposal_date' => now()->toDateString(), 'proceeds' => 0])
            ->assertStatus(201)->assertJsonPath('gain_loss', '-80000.00');
    }

    public function test_an_asset_with_depreciation_cannot_be_deleted_but_a_fresh_one_can(): void
    {
        $fresh = $this->buy();
        $before = (float) $this->till->fresh()->current_balance;
        $this->deleteJson("/api/fixed-assets/{$fresh['id']}")->assertOk();
        $this->assertEqualsWithDelta(120000, (float) $this->till->fresh()->current_balance - $before, 0.001);

        $used = $this->buy();
        $this->postJson('/api/fixed-assets/depreciation/run', ['through' => now()->format('Y-m'), 'branch_id' => $this->branchId])->assertOk();
        $this->deleteJson("/api/fixed-assets/{$used['id']}")->assertStatus(422);
    }
}
