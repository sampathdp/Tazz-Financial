<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\ChartOfAccount;
use App\Models\User;
use App\Support\LedgerPoster;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Audit finding C-7: receipts parked in 1030 Undeposited Funds had no way to be cleared into a bank account. */
class UndepositedFundsTest extends TestCase
{
    use DatabaseTransactions;

    private BankAccount $bank;

    protected function setUp(): void
    {
        parent::setUp();
        $branch = DB::table('branches')->insertGetId(['name' => 'Und ' . Str::random(4), 'code' => 'U' . Str::random(4), 'branch_type' => 'Branch', 'status' => 'Active', 'created_at' => now(), 'updated_at' => now()]);
        $bankId = DB::table('banks')->insertGetId(['name' => 'UBank ' . Str::random(4), 'created_at' => now(), 'updated_at' => now()]);
        $this->bank = BankAccount::create(['branch_id' => $branch, 'bank_id' => $bankId, 'name' => 'UBA ' . Str::random(4), 'account_number' => 'N' . Str::random(8),
            'account_type' => 'Current', 'currency' => 'LKR', 'opening_balance' => 0, 'current_balance' => 0, 'status' => 'Active']);
        ChartOfAccount::forBankAccount($this->bank->fresh());
        $this->actingAs(User::whereHas('role', fn ($q) => $q->where('slug', 'admin'))->first() ?? $this->markTestSkipped('no admin'), 'sanctum');
        ChartOfAccount::flushBalancesCache();
    }

    private function park(string $amount): void
    {
        // A bank-method loan receipt: Dr 1030 / Cr loan interest income.
        LedgerPoster::post([
            ['account_id' => ChartOfAccount::system('1030')->id, 'debit' => $amount],
            ['account_id' => ChartOfAccount::system('4010')->id, 'credit' => $amount],
        ], now()->toDateString(), ['source_type' => 'ManualAdjustment', 'description' => 'parked']);
        ChartOfAccount::flushBalancesCache();
    }

    public function test_the_undeposited_balance_is_reported_and_can_be_deposited_into_a_bank_account(): void
    {
        $before = (float) $this->getJson('/api/undeposited-funds')->assertOk()->json('balance');
        $this->park('700.00');
        $this->assertEquals($before + 700.0, (float) $this->getJson('/api/undeposited-funds')->json('balance'));

        $this->postJson('/api/undeposited-funds/deposit', ['bank_account_id' => $this->bank->id, 'amount' => 300, 'txn_date' => now()->toDateString(), 'reference_no' => 'SLIP1'])->assertStatus(201);

        $this->assertEquals(300.0, (float) $this->bank->fresh()->current_balance, 'the bank account balance moves');
        $this->assertEquals($before + 400.0, (float) $this->getJson('/api/undeposited-funds')->json('balance'), '1030 is reduced by the deposit');
    }

    public function test_depositing_more_than_the_undeposited_balance_is_refused(): void
    {
        $current = (float) $this->getJson('/api/undeposited-funds')->json('balance');
        $this->postJson('/api/undeposited-funds/deposit', ['bank_account_id' => $this->bank->id, 'amount' => $current + 1000, 'txn_date' => now()->toDateString()])->assertStatus(422);
        $this->assertEquals(0.0, (float) $this->bank->fresh()->current_balance);
    }
}
