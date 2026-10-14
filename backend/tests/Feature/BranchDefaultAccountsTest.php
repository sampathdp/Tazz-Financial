<?php

namespace Tests\Feature;

use App\Models\CashAccount;
use App\Models\ChartOfAccount;
use App\Models\User;
use App\Support\BranchProvisioner;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** A new branch must come with the accounts every module needs, so no cash movement is ever skipped for want of a till. */
class BranchDefaultAccountsTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::whereHas('role', fn ($q) => $q->where('slug', 'admin'))->first() ?? $this->markTestSkipped('no admin'), 'sanctum');
    }

    public function test_creating_a_branch_creates_its_main_till_with_a_ledger_account(): void
    {
        $code = 'N' . strtoupper(Str::random(4));
        $id = $this->postJson('/api/branches', ['name' => 'New Branch ' . $code, 'code' => $code])->assertStatus(201)->json('id');

        $till = CashAccount::withoutGlobalScopes()->where('branch_id', $id)->get();
        $this->assertCount(1, $till, 'one main till is created');
        $t = $till->first();
        $this->assertSame('BranchMain', $t->account_type);
        $this->assertSame('General', $t->purpose, 'a General till serves every purpose until a dedicated one is added');
        $this->assertSame('Active', $t->status);
        $this->assertNotEmpty($t->code);
        $leaf = ChartOfAccount::find($t->chart_of_account_id);
        $this->assertNotNull($leaf, 'the till has its ledger account');
        $this->assertSame('Active', $leaf->status);
        // Every module resolves this till, whatever the purpose.
        foreach ([CashAccount::PURPOSE_COLLECTIONS, CashAccount::PURPOSE_DISBURSEMENTS, CashAccount::PURPOSE_FD, CashAccount::PURPOSE_SAVINGS, CashAccount::PURPOSE_PAWNING, CashAccount::PURPOSE_DOCUMENT_CHARGES] as $purpose) {
            $this->assertSame($t->id, CashAccount::branchPrimary($id, $purpose)->id, $purpose);
        }
    }

    public function test_the_head_office_gets_a_head_office_till_and_a_center_gets_its_own(): void
    {
        $ho = $this->postJson('/api/branches', ['name' => 'HO2 ' . Str::random(4), 'code' => 'H' . strtoupper(Str::random(4)), 'is_head_office' => true])->assertStatus(201)->json('id');
        $this->assertSame('HeadOfficeMain', CashAccount::withoutGlobalScopes()->where('branch_id', $ho)->value('account_type'));

        $parent = DB::table('branches')->value('id');
        $center = $this->postJson('/api/branches', ['name' => 'Ctr ' . Str::random(4), 'code' => 'C' . strtoupper(Str::random(4)), 'branch_type' => 'Center', 'parent_id' => $parent])->assertStatus(201)->json('id');
        $this->assertSame(1, CashAccount::withoutGlobalScopes()->where('branch_id', $center)->count());
    }

    public function test_provisioning_is_idempotent_and_leaves_an_existing_till_alone(): void
    {
        $branch = \App\Models\Branch::create(['name' => 'Idem ' . Str::random(4), 'code' => 'I' . strtoupper(Str::random(4)), 'branch_type' => 'Branch', 'status' => 'Active']);
        BranchProvisioner::provision($branch);
        BranchProvisioner::provision($branch);
        $this->assertSame(1, CashAccount::withoutGlobalScopes()->where('branch_id', $branch->id)->count());

        $existing = \App\Models\Branch::create(['name' => 'Has ' . Str::random(4), 'code' => 'X' . strtoupper(Str::random(4)), 'branch_type' => 'Branch', 'status' => 'Active']);
        CashAccount::create(['code' => 'EX-' . Str::random(8), 'branch_id' => $existing->id, 'account_type' => 'BranchMain', 'name' => 'Own till', 'opening_balance' => 0, 'current_balance' => 0, 'status' => 'Active']);
        BranchProvisioner::provision($existing);
        $this->assertSame(1, CashAccount::withoutGlobalScopes()->where('branch_id', $existing->id)->count(), 'a branch that already has a main till gets no second one');
    }

    public function test_the_setup_command_adds_the_missing_till_to_existing_branches(): void
    {
        $branch = \App\Models\Branch::create(['name' => 'Old ' . Str::random(4), 'code' => 'O' . strtoupper(Str::random(4)), 'branch_type' => 'Branch', 'status' => 'Active']);
        $this->artisan('accounts:apply-standard-setup')->assertExitCode(0);
        $this->assertSame(0, CashAccount::withoutGlobalScopes()->where('branch_id', $branch->id)->count(), 'a dry run changes nothing');
        $this->artisan('accounts:apply-standard-setup', ['--apply' => true])->assertExitCode(0);
        $this->assertSame(1, CashAccount::withoutGlobalScopes()->where('branch_id', $branch->id)->count());
    }

    public function test_an_untouched_branch_can_be_deleted_with_its_default_till_but_a_used_one_cannot(): void
    {
        $code = 'D' . strtoupper(Str::random(4));
        $id = $this->postJson('/api/branches', ['name' => 'Del ' . $code, 'code' => $code])->assertStatus(201)->json('id');
        $till = CashAccount::withoutGlobalScopes()->where('branch_id', $id)->first();
        $leafId = $till->chart_of_account_id;
        $this->deleteJson("/api/branches/{$id}")->assertNoContent();
        $this->assertNull(CashAccount::withoutGlobalScopes()->find($till->id));
        $this->assertNull(ChartOfAccount::find($leafId));

        $code2 = 'E' . strtoupper(Str::random(4));
        $id2 = $this->postJson('/api/branches', ['name' => 'Use ' . $code2, 'code' => $code2])->assertStatus(201)->json('id');
        CashAccount::withoutGlobalScopes()->where('branch_id', $id2)->update(['current_balance' => 50]);
        $this->deleteJson("/api/branches/{$id2}")->assertStatus(422);
    }
}
