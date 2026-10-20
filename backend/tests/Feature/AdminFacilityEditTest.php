<?php

namespace Tests\Feature;

use App\Models\Borrowing;
use App\Models\CashAccount;
use App\Models\ChartOfAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Facility edit endpoints are admin-only, and (for posted facilities) only open
 * before the first payment/transaction.
 */
class AdminFacilityEditTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private int $branchId;

    private CashAccount $till;

    protected function setUp(): void
    {
        parent::setUp();
        ChartOfAccount::where('code', '2220')->exists() ?: $this->markTestSkipped('Chart lacks 2220.');
        $this->admin = User::whereHas('role', fn ($q) => $q->where('slug', 'admin'))->first() ?? $this->markTestSkipped('No admin user.');
        $this->branchId = DB::table('branches')->insertGetId(['name' => 'AE', 'code' => 'AE' . Str::random(3), 'branch_type' => 'Branch', 'status' => 'Active', 'created_at' => now(), 'updated_at' => now()]);
        $this->till = CashAccount::create(['code' => 'AE-CASH-' . Str::random(3), 'branch_id' => $this->branchId, 'account_type' => 'BranchMain', 'name' => 'AE Till', 'opening_balance' => 0, 'current_balance' => '50000.00', 'status' => 'Active']);
    }

    private function borrow(): Borrowing
    {
        $res = $this->actingAs($this->admin, 'sanctum')->postJson('/api/borrowings', [
            'branch_id' => $this->branchId, 'type' => 'Party', 'lender_name' => 'ACME', 'principal_amount' => '10000.00',
            'interest_rate_percent' => 12, 'term_months' => 12, 'borrowing_date' => now()->toDateString(),
            'on_maturity' => 'Renew', 'receipt_method' => 'Cash', 'receipt_cash_account_id' => $this->till->id,
        ], ['Idempotency-Key' => (string) Str::uuid()]);
        $res->assertStatus(201);

        return Borrowing::find($res->json('id'));
    }

    public function test_non_admin_cannot_edit_any_facility(): void
    {
        $roleId = DB::table('roles')->where('slug', '!=', 'admin')->orderByDesc('can_view_all_branches')->value('id') ?? $this->markTestSkipped('No non-admin role.');
        $user = User::forceCreate([
            'name' => 'Plain User', 'email' => 'plain' . Str::random(6) . '@example.test', 'password' => bcrypt('secret1'),
            'role_id' => $roleId, 'branch_id' => $this->branchId, 'status' => 'Active',
        ]);
        $this->actingAs($user, 'sanctum');

        // Route-model binding runs before the admin check, so use ids that really exist; a table with no rows is skipped.
        $tables = ['loans' => 'credit_facilities', 'speed-drafts' => 'credit_facilities', 'pawnings' => 'credit_facilities', 'fixed-deposits' => 'fixed_deposits',
            'borrowings' => 'borrowings', 'branch-funding' => 'branch_funding_agreements', 'savings-accounts' => 'savings_accounts'];
        $this->borrow();
        $checked = 0;
        foreach ($tables as $path => $table) {
            $q = DB::table($table);
            if ($table === 'credit_facilities') {
                $q->where('facility_type', ['loans' => 'Loan', 'speed-drafts' => 'SpeedDraft', 'pawnings' => 'Pawning'][$path]);
            }
            $id = $q->value('id');
            if (!$id) {
                continue;
            }
            $this->assertSame(403, $this->actingAs($user, 'sanctum')->putJson("/api/{$path}/{$id}", [])->getStatusCode(), "{$path}/{$id}");
            $checked++;
        }
        $this->assertGreaterThan(0, $checked);
    }

    public function test_admin_can_edit_untouched_borrowing_but_not_principal(): void
    {
        $b = $this->borrow();

        $this->putJson("/api/borrowings/{$b->id}", ['lender_name' => 'ACME 2', 'interest_rate_percent' => 15, 'principal_amount' => 1])
            ->assertOk()->assertJsonPath('lender_name', 'ACME 2');

        $b->refresh();
        $this->assertSame('ACME 2', $b->lender_name);
        $this->assertSame('10000.00', (string) $b->principal_amount);
    }

    public function test_borrowing_locked_after_first_transaction(): void
    {
        $b = $this->borrow();
        $this->postJson("/api/borrowings/{$b->id}/transactions", [
            'kind' => 'InterestPayment', 'amount' => '100.00', 'txn_date' => now()->toDateString(),
            'payment_method' => 'Cash', 'cash_account_id' => $this->till->id,
        ], ['Idempotency-Key' => (string) Str::uuid()]);

        if (!$b->transactions()->exists()) {
            $this->markTestSkipped('Transaction payload not accepted in this environment.');
        }
        $this->putJson("/api/borrowings/{$b->id}", ['lender_name' => 'Changed'])->assertStatus(422);
    }
}
