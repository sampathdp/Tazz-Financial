<?php

namespace Tests\Feature;

use App\Models\BankReconciliation;
use App\Models\BorrowingTransaction;
use App\Models\CashFloatSettlement;
use App\Models\FacilityCollateral;
use App\Models\FacilityDiaryNote;
use App\Models\FdInterestPayment;
use App\Models\FdPartPayment;
use App\Models\PettyCashReplenishment;
use App\Models\PettyCashVoucher;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Child records with no branch_id of their own must not be reachable by bare id across branches
 * (security audit Phase 2, item 3). Each child is built under a parent in branch B and fetched as a
 * branch-A user; the parent-derived scope has to hide it.
 */
class BranchChildIsolationTest extends TestCase
{
    use DatabaseTransactions;

    private int $branchA;

    private int $branchB;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['A', 'B'] as $k) {
            $id = DB::table('branches')->insertGetId([
                'name' => "Ch {$k} " . Str::random(5), 'code' => 'C' . $k . Str::random(3), 'branch_type' => 'Branch', 'status' => 'Active',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $k === 'A' ? $this->branchA = $id : $this->branchB = $id;
        }
    }

    /** Insert a row, filling every NOT NULL column that has no default; FK checks are off for the insert. */
    private function row(string $table, array $values): int
    {
        $cols = DB::select(
            'select column_name c, data_type t, column_type ct, is_nullable n, column_default d, extra e from information_schema.columns where table_schema = database() and table_name = ?',
            [$table]
        );
        $row = [];
        foreach ($cols as $c) {
            if (array_key_exists($c->c, $values)) {
                $row[$c->c] = $values[$c->c];
            } elseif ($c->n === 'NO' && $c->d === null && !str_contains((string) $c->e, 'auto_increment') && !str_contains((string) $c->e, 'GENERATED')) {
                $row[$c->c] = match (true) {
                    in_array($c->t, ['int', 'bigint', 'tinyint', 'smallint', 'mediumint', 'decimal', 'double', 'float'], true) => 1,
                    in_array($c->t, ['datetime', 'timestamp'], true) => now(),
                    $c->t === 'date' => now()->toDateString(),
                    $c->t === 'enum' => explode("','", trim(substr($c->ct, 5, -1), "'"))[0],
                    $c->t === 'json' => '[]',
                    default => 'Z' . Str::random(8),
                };
            }
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        try {
            return (int) DB::table($table)->insertGetId($row);
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }
    }

    private function actAs(bool $allBranches): void
    {
        $roleId = DB::table('roles')->insertGetId([
            'name' => 'ChRole' . Str::random(4), 'slug' => 'ch_' . Str::random(6), 'is_system' => 0,
            'can_view_all_branches' => $allBranches ? 1 : 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach (DB::table('permissions')->pluck('id') as $pid) {
            DB::table('role_permissions')->insert(['role_id' => $roleId, 'permission_id' => $pid, 'created_at' => now()]);
        }
        $user = User::forceCreate([
            'name' => 'Ch User', 'email' => 'ch' . Str::random(6) . '@example.test', 'password' => Hash::make('x'),
            'role_id' => $roleId, 'branch_id' => $this->branchA, 'status' => 'Active',
        ])->fresh();
        $this->actingAs($user, 'sanctum');
    }

    /** @return array<string, array{0:class-string,1:int}> model name => [class, child id under a branch-B parent] */
    private function otherBranchChildren(): array
    {
        $b = $this->branchB;
        $loan = $this->row('credit_facilities', ['branch_id' => $b, 'deleted_at' => null]);
        $bank = $this->row('bank_accounts', ['branch_id' => $b]);
        $cash = $this->row('cash_accounts', ['branch_id' => $b]);
        $borrowing = $this->row('borrowings', ['branch_id' => $b]);
        $fund = $this->row('petty_cash_funds', ['branch_id' => $b]);
        $fd = $this->row('fixed_deposits', ['branch_id' => $b]);

        return [
            'FacilityCollateral' => [FacilityCollateral::class, $this->row('facility_collateral', ['credit_facility_id' => $loan])],
            'FacilityDiaryNote' => [FacilityDiaryNote::class, $this->row('facility_diary_notes', ['credit_facility_id' => $loan])],
            'BankReconciliation' => [BankReconciliation::class, $this->row('bank_reconciliations', ['bank_account_id' => $bank])],
            'CashFloatSettlement' => [CashFloatSettlement::class, $this->row('cash_float_settlements', ['cash_account_id' => $cash])],
            'BorrowingTransaction' => [BorrowingTransaction::class, $this->row('borrowing_transactions', ['borrowing_id' => $borrowing])],
            'PettyCashVoucher' => [PettyCashVoucher::class, $this->row('petty_cash_vouchers', ['petty_cash_fund_id' => $fund])],
            'PettyCashReplenishment' => [PettyCashReplenishment::class, $this->row('petty_cash_replenishments', ['petty_cash_fund_id' => $fund, 'funding_method' => 'Cash', 'cash_account_id' => $cash])],
            'FdInterestPayment' => [FdInterestPayment::class, $this->row('fd_interest_payments', ['fixed_deposit_id' => $fd])],
            'FdPartPayment' => [FdPartPayment::class, $this->row('fd_part_payments', ['fixed_deposit_id' => $fd])],
        ];
    }

    public function test_child_records_of_another_branch_are_invisible_to_a_branch_user(): void
    {
        $children = $this->otherBranchChildren();
        $this->actAs(false);

        foreach ($children as $name => [$class, $id]) {
            $this->assertNull($class::find($id), "{$name} {$id} of another branch is reachable by id");
        }
    }

    public function test_child_records_are_visible_to_an_all_branch_user(): void
    {
        $children = $this->otherBranchChildren();
        $this->actAs(true);

        foreach ($children as $name => [$class, $id]) {
            $this->assertNotNull($class::find($id), "{$name} {$id} hidden from an all-branch user");
        }
    }

    public function test_diary_note_and_speed_draft_collateral_routes_404_across_branches(): void
    {
        $children = $this->otherBranchChildren();
        $this->actAs(false);

        $this->deleteJson('/api/loan-diary-notes/' . $children['FacilityDiaryNote'][1])->assertStatus(404);
        $this->putJson('/api/speed-draft-collateral/' . $children['FacilityCollateral'][1], ['description' => 'x'])->assertStatus(404);
        $this->deleteJson('/api/speed-draft-collateral/' . $children['FacilityCollateral'][1])->assertStatus(404);
        $this->assertSame(1, DB::table('facility_diary_notes')->where('id', $children['FacilityDiaryNote'][1])->count());
    }
}
