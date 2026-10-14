<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\CashAccount;
use App\Models\ChartOfAccount;
use App\Models\User;
use App\Support\LedgerPoster;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Audit findings C-4 (header accounts postable, no roll-up), C-9 (cash/bank edits drift from the ledger),
 * C-10 (accounts re-typed after use) and C-12 (duplicate till codes).
 */
class ChartOfAccountGuardsTest extends TestCase
{
    use DatabaseTransactions;

    private int $branchId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->branchId = DB::table('branches')->insertGetId([
            'name' => 'Coa ' . Str::random(5), 'code' => 'C' . Str::random(4), 'branch_type' => 'Branch', 'status' => 'Active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $admin = User::whereHas('role', fn ($q) => $q->where('slug', 'admin'))->first() ?? $this->markTestSkipped('no admin');
        $this->actingAs($admin, 'sanctum');
        ChartOfAccount::flushBalancesCache();
    }

    private function account(string $code, string $type = 'Expense', string $normal = 'Debit', string $status = 'Active'): ChartOfAccount
    {
        return ChartOfAccount::create(['code' => $code . Str::random(3), 'name' => 'T ' . $code, 'account_type' => $type, 'normal_balance' => $normal, 'is_system' => false, 'status' => $status]);
    }

    private function entry(ChartOfAccount $dr, ChartOfAccount $cr, string $amount = '10.00')
    {
        return LedgerPoster::post([
            ['account_id' => $dr->id, 'debit' => $amount], ['account_id' => $cr->id, 'credit' => $amount],
        ], now()->toDateString(), ['source_type' => 'ManualAdjustment', 'description' => 'guard test']);
    }

    public function test_a_header_account_cannot_be_posted_to(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/header|postable/i');
        $this->entry(ChartOfAccount::system('1010'), ChartOfAccount::system('3015'));
    }

    public function test_an_inactive_account_cannot_be_posted_to_but_an_existing_entry_can_still_be_reversed(): void
    {
        $exp = $this->account('9X1');
        $inc = $this->account('9X2', 'Income', 'Credit');
        $entry = $this->entry($exp, $inc);

        $exp->update(['status' => 'Inactive']);
        try {
            $this->entry($exp, $inc);
            $this->fail('posting to an inactive account must be refused');
        } catch (\RuntimeException $e) {
            $this->assertMatchesRegularExpression('/inactive/i', $e->getMessage());
        }

        $reversal = LedgerPoster::reverse($entry->fresh(), 'undo');
        $this->assertSame('Posted', $reversal->status, 'a reversal must still be possible after the account is deactivated');
    }

    public function test_code_type_and_normal_side_are_frozen_once_an_account_has_postings(): void
    {
        $exp = $this->account('9Y1');
        $inc = $this->account('9Y2', 'Income', 'Credit');
        $this->putJson("/api/chart-of-accounts/{$exp->id}", ['code' => $exp->code, 'name' => 'Renamed', 'account_type' => 'Expense', 'normal_balance' => 'Debit'])->assertOk();

        $this->entry($exp, $inc);

        $this->putJson("/api/chart-of-accounts/{$exp->id}", ['code' => $exp->code, 'name' => 'Renamed again', 'account_type' => 'Income', 'normal_balance' => 'Credit'])->assertStatus(422);
        $this->putJson("/api/chart-of-accounts/{$exp->id}", ['code' => 'NEWCODE' . Str::random(3), 'name' => 'x', 'account_type' => 'Expense', 'normal_balance' => 'Debit'])->assertStatus(422);
        // A rename alone is still allowed.
        $this->putJson("/api/chart-of-accounts/{$exp->id}", ['code' => $exp->code, 'name' => 'Only a rename', 'account_type' => 'Expense', 'normal_balance' => 'Debit'])->assertOk();
    }

    public function test_chart_list_shows_a_roll_up_balance_for_header_accounts(): void
    {
        $till = CashAccount::create(['code' => 'RU-' . Str::random(6), 'branch_id' => $this->branchId, 'account_type' => 'BranchMain', 'name' => 'RollUp ' . Str::random(3), 'opening_balance' => 0, 'current_balance' => 0, 'status' => 'Active']);
        $leaf = ChartOfAccount::forCashTill($till->fresh());
        $baseline = collect($this->getJson('/api/chart-of-accounts')->json())->firstWhere('code', '1010');

        $this->entry($leaf, $this->account('9Z2', 'Income', 'Credit'), '250.00');
        ChartOfAccount::flushBalancesCache();
        $after = collect($this->getJson('/api/chart-of-accounts')->json())->firstWhere('code', '1010');

        $this->assertEquals((float) $baseline['rollup_balance'] + 250.0, (float) $after['rollup_balance'], '1010 rolls up its till accounts');
        $this->assertEquals((float) $baseline['balance'], (float) $after['balance'], 'the header\'s own balance is unchanged');
    }

    public function test_cash_account_branch_and_opening_balance_lock_after_use_and_rename_syncs_the_ledger_name(): void
    {
        $other = DB::table('branches')->insertGetId(['name' => 'Other ' . Str::random(4), 'code' => 'O' . Str::random(4), 'branch_type' => 'Branch', 'status' => 'Active', 'created_at' => now(), 'updated_at' => now()]);
        $r = $this->postJson('/api/cash-accounts', ['branch_id' => $this->branchId, 'name' => 'Lock Till', 'opening_balance' => 500])->assertStatus(201);
        $id = $r->json('id');
        $this->assertNotEmpty($r->json('code'), 'a new till gets a code');

        $this->putJson("/api/cash-accounts/{$id}", ['branch_id' => $this->branchId, 'name' => 'Renamed Till', 'opening_balance' => 500])->assertOk();
        $leaf = ChartOfAccount::find(CashAccount::find($id)->chart_of_account_id);
        $this->assertStringContainsString('Renamed Till', $leaf->name, 'the ledger account follows a rename');

        // 500 opening balance has been posted, so branch and opening balance are now frozen.
        $this->putJson("/api/cash-accounts/{$id}", ['branch_id' => $other, 'name' => 'Renamed Till', 'opening_balance' => 500])->assertStatus(422);
        $this->putJson("/api/cash-accounts/{$id}", ['branch_id' => $this->branchId, 'name' => 'Renamed Till', 'opening_balance' => 900])->assertStatus(422);
    }

    public function test_two_new_tills_never_share_a_code(): void
    {
        $a = $this->postJson('/api/cash-accounts', ['branch_id' => $this->branchId, 'name' => 'Till A'])->assertStatus(201)->json('code');
        $b = $this->postJson('/api/cash-accounts', ['branch_id' => $this->branchId, 'name' => 'Till B'])->assertStatus(201)->json('code');
        $this->assertNotSame($a, $b);
    }
}
