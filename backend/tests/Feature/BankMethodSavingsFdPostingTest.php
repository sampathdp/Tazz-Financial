<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\ChartOfAccount;
use App\Models\SavingsAccount;
use App\Models\SavingsProduct;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Savings money moved by Bank / Cheque used to post nothing at all (no ledger entry, no bank
 * balance change). With a company bank account on the transaction it now posts like cash does;
 * a receipt with no account named is parked in 1030; a payout with no account is refused.
 */
class BankMethodSavingsFdPostingTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private int $branchId;

    private BankAccount $bank;

    private SavingsAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        ChartOfAccount::where('code', '2050')->exists() ?: $this->markTestSkipped('Savings chart of accounts not seeded.');
        $this->admin = User::whereHas('role', fn ($q) => $q->where('slug', 'admin'))->first() ?? $this->markTestSkipped('No admin user seeded.');
        $this->branchId = DB::table('branches')->insertGetId(['name' => 'BM', 'code' => 'BM' . Str::random(3), 'branch_type' => 'Branch', 'status' => 'Active', 'created_at' => now(), 'updated_at' => now()]);
        $bankRow = DB::table('banks')->insertGetId(['name' => 'BM Bank ' . Str::random(4), 'created_at' => now(), 'updated_at' => now()]);
        $this->bank = BankAccount::create([
            'branch_id' => $this->branchId, 'bank_id' => $bankRow, 'name' => 'BM Acc', 'account_number' => 'BM' . Str::random(8),
            'account_type' => 'Current', 'currency' => 'LKR', 'opening_balance' => 0, 'current_balance' => '50000.00', 'status' => 'Active',
        ]);
        $product = SavingsProduct::create([
            'name' => 'BM Daily ' . Str::random(4), 'code' => 'BMD' . Str::random(3), 'interest_rate_percent' => '12.000',
            'interest_calc_method' => 'min_monthly_balance', 'interest_posting_frequency' => 'monthly',
            'min_opening_deposit' => '0', 'min_balance' => '0', 'withdrawal_fee' => '0', 'ledger_fee' => '0', 'dormancy_days' => 0, 'status' => 'Active',
        ]);
        $customerId = DB::table('customers')->value('id') ?? $this->markTestSkipped('No customer seeded.');
        $this->account = SavingsAccount::create([
            'account_number' => 'SAV-' . Str::random(5), 'branch_id' => $this->branchId, 'customer_id' => $customerId,
            'savings_product_id' => $product->id, 'interest_rate_percent' => '12.000', 'interest_calc_method' => 'min_monthly_balance',
            'interest_posting_frequency' => 'monthly', 'min_balance' => '0', 'withdrawal_fee' => '0',
            'open_date' => now()->startOfMonth()->toDateString(), 'status' => 'Active', 'created_by' => $this->admin->id,
        ]);
        $this->actingAs($this->admin, 'sanctum');
    }

    private function glBalance(int $accountId): float
    {
        ChartOfAccount::flushBalancesCache();
        $r = ChartOfAccount::balancesAsOf(now()->addYears(5)->toDateString())->get($accountId);

        return $r ? (float) $r->balance : 0.0;
    }

    public function test_bank_deposit_with_account_moves_the_bank_balance_and_posts_the_ledger(): void
    {
        $leaf = ChartOfAccount::forBankAccount($this->bank->fresh());
        $before = $this->glBalance($leaf->id);
        $this->postJson("/api/savings-accounts/{$this->account->id}/deposit", [
            'amount' => 4000, 'txn_date' => now()->toDateString(), 'payment_method' => 'Bank', 'bank_id' => DB::table('banks')->value('id'),
            'bank_account_id' => $this->bank->id,
        ])->assertStatus(201);
        $this->assertSame('54000.00', (string) $this->bank->fresh()->current_balance);
        $this->assertEqualsWithDelta(4000.0, $this->glBalance($leaf->id) - $before, 0.001);
    }

    public function test_bank_deposit_without_an_account_is_parked_in_undeposited_funds(): void
    {
        $c1030 = ChartOfAccount::system('1030')->id;
        $before = $this->glBalance($c1030);
        $this->postJson("/api/savings-accounts/{$this->account->id}/deposit", [
            'amount' => 1500, 'txn_date' => now()->toDateString(), 'payment_method' => 'Bank', 'bank_id' => DB::table('banks')->value('id'),
        ])->assertStatus(201);
        $this->assertSame('50000.00', (string) $this->bank->fresh()->current_balance);
        $this->assertEqualsWithDelta(1500.0, $this->glBalance($c1030) - $before, 0.001);
    }

    public function test_bank_withdrawal_needs_the_paying_account_and_then_reduces_it(): void
    {
        $this->postJson("/api/savings-accounts/{$this->account->id}/deposit", [
            'amount' => 6000, 'txn_date' => now()->toDateString(), 'payment_method' => 'Bank', 'bank_id' => DB::table('banks')->value('id'),
            'bank_account_id' => $this->bank->id,
        ])->assertStatus(201);

        $this->postJson("/api/savings-accounts/{$this->account->id}/withdraw", [
            'amount' => 2000, 'txn_date' => now()->toDateString(), 'payment_method' => 'Bank',
        ])->assertStatus(422);

        $this->postJson("/api/savings-accounts/{$this->account->id}/withdraw", [
            'amount' => 2000, 'txn_date' => now()->toDateString(), 'payment_method' => 'Bank', 'bank_account_id' => $this->bank->id,
        ])->assertStatus(201);
        $this->assertSame('54000.00', (string) $this->bank->fresh()->current_balance);
    }
}
