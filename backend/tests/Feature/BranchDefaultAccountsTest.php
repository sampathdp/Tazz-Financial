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

    public function test_creating_a_branch_creates_a_till_for_every_purpose_each_with_a_ledger_account(): void
    {
        $code = 'N' . strtoupper(Str::random(4));
        $id = $this->postJson('/api/branches', ['name' => 'New Branch ' . $code, 'code' => $code])->assertStatus(201)->json('id');

        $tills = CashAccount::withoutGlobalScopes()->where('branch_id', $id)->get();
        $this->assertSame(count(CashAccount::PURPOSES), $tills->count(), 'General plus one till per dedicated purpose');
        $this->assertEqualsCanonicalizing(CashAccount::PURPOSES, $tills->pluck('purpose')->all());
        foreach ($tills as $t) {
            $this->assertSame('BranchMain', $t->account_type);
            $this->assertSame('Active', $t->status);
            $this->assertNotEmpty($t->code);
            $this->assertSame('Active', ChartOfAccount::find($t->chart_of_account_id)?->status, "{$t->purpose} till has its ledger account");
        }
        config(['finance.strict_till_purposes' => true]);
        // Each module resolves its own till, never another purpose's.
        foreach (CashAccount::DEDICATED_PURPOSES as $purpose) {
            $this->assertSame($purpose, CashAccount::branchPrimary($id, $purpose)->purpose, $purpose);
        }
        $this->assertSame('General', CashAccount::branchPrimary($id)->purpose);
    }

    public function test_a_missing_dedicated_till_is_an_error_not_a_fallback(): void
    {
        config(['finance.strict_till_purposes' => true]);
        $code = 'M' . strtoupper(Str::random(4));
        $id = $this->postJson('/api/branches', ['name' => 'Miss ' . $code, 'code' => $code])->assertStatus(201)->json('id');
        CashAccount::withoutGlobalScopes()->where('branch_id', $id)->where('purpose', CashAccount::PURPOSE_DOCUMENT_CHARGES)->delete();

        $this->expectException(\App\Exceptions\FinancePostingException::class);
        $this->expectExceptionMessage('Document Charges cash account');
        CashAccount::branchPrimary($id, CashAccount::PURPOSE_DOCUMENT_CHARGES);
    }

    public function test_a_branch_cannot_have_two_tills_for_one_purpose(): void
    {
        $code = 'U' . strtoupper(Str::random(4));
        $id = $this->postJson('/api/branches', ['name' => 'Uniq ' . $code, 'code' => $code])->assertStatus(201)->json('id');

        $this->postJson('/api/cash-accounts', ['name' => 'Second FD', 'branch_id' => $id, 'purpose' => 'FixedDeposits'])->assertStatus(422);
        $this->postJson('/api/cash-accounts', ['name' => 'Second general', 'branch_id' => $id, 'purpose' => 'General'])->assertStatus(201);
    }

    public function test_the_head_office_gets_a_head_office_till_and_a_center_gets_its_own(): void
    {
        $ho = $this->postJson('/api/branches', ['name' => 'HO2 ' . Str::random(4), 'code' => 'H' . strtoupper(Str::random(4)), 'is_head_office' => true])->assertStatus(201)->json('id');
        $this->assertSame('HeadOfficeMain', CashAccount::withoutGlobalScopes()->where('branch_id', $ho)->value('account_type'));

        $parent = DB::table('branches')->value('id');
        $center = $this->postJson('/api/branches', ['name' => 'Ctr ' . Str::random(4), 'code' => 'C' . strtoupper(Str::random(4)), 'branch_type' => 'Center', 'parent_id' => $parent])->assertStatus(201)->json('id');
        $this->assertSame(count(CashAccount::PURPOSES), CashAccount::withoutGlobalScopes()->where('branch_id', $center)->count());
    }

    public function test_provisioning_is_idempotent_and_leaves_an_existing_till_alone(): void
    {
        $branch = \App\Models\Branch::create(['name' => 'Idem ' . Str::random(4), 'code' => 'I' . strtoupper(Str::random(4)), 'branch_type' => 'Branch', 'status' => 'Active']);
        BranchProvisioner::provision($branch);
        BranchProvisioner::provision($branch);
        $this->assertSame(count(CashAccount::PURPOSES), CashAccount::withoutGlobalScopes()->where('branch_id', $branch->id)->count());

        $existing = \App\Models\Branch::find(DB::table('branches')->insertGetId(['name' => 'Has ' . Str::random(4), 'code' => 'X' . strtoupper(Str::random(4)), 'branch_type' => 'Branch', 'status' => 'Active', 'created_at' => now(), 'updated_at' => now()]));
        CashAccount::create(['code' => 'EX-' . Str::random(8), 'branch_id' => $existing->id, 'account_type' => 'BranchMain', 'name' => 'Own till', 'opening_balance' => 0, 'current_balance' => 0, 'status' => 'Active']);
        BranchProvisioner::provision($existing);
        $this->assertSame(1, CashAccount::withoutGlobalScopes()->where('branch_id', $existing->id)->where('purpose', 'General')->count(), 'a branch that already has a General till gets no second one');
    }

    public function test_the_setup_command_adds_the_missing_till_to_existing_branches(): void
    {
        $branch = \App\Models\Branch::find(DB::table('branches')->insertGetId(['name' => 'Old ' . Str::random(4), 'code' => 'O' . strtoupper(Str::random(4)), 'branch_type' => 'Branch', 'status' => 'Active', 'created_at' => now(), 'updated_at' => now()]));
        $this->artisan('accounts:apply-standard-setup')->assertExitCode(0);
        $this->assertSame(0, CashAccount::withoutGlobalScopes()->where('branch_id', $branch->id)->count(), 'a dry run changes nothing');
        $this->artisan('accounts:apply-standard-setup', ['--apply' => true])->assertExitCode(0);
        $this->assertSame(count(CashAccount::PURPOSES), CashAccount::withoutGlobalScopes()->where('branch_id', $branch->id)->count());
    }

    public function test_an_untouched_branch_can_be_deleted_with_its_default_till_but_a_used_one_cannot(): void
    {
        $code = 'D' . strtoupper(Str::random(4));
        $id = $this->postJson('/api/branches', ['name' => 'Del ' . $code, 'code' => $code])->assertStatus(201)->json('id');
        $till = CashAccount::withoutGlobalScopes()->where('branch_id', $id)->first();
        $leafId = $till->chart_of_account_id;
        $this->deleteJson("/api/branches/{$id}")->assertNoContent();
        $this->assertSame(0, CashAccount::withoutGlobalScopes()->where('branch_id', $id)->count());
        $this->assertNull(ChartOfAccount::find($leafId));

        $code2 = 'E' . strtoupper(Str::random(4));
        $id2 = $this->postJson('/api/branches', ['name' => 'Use ' . $code2, 'code' => $code2])->assertStatus(201)->json('id');
        CashAccount::withoutGlobalScopes()->where('branch_id', $id2)->update(['current_balance' => 50]);
        $this->deleteJson("/api/branches/{$id2}")->assertStatus(422);
    }
}
