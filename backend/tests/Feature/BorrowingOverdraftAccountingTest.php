<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\ChartOfAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Overdraft facility end to end: register the facility, draw down through the
 * Bank module, and check the ledger, the live utilisation on the borrowing
 * screen and the balance sheet (overdrawn bank shown as a liability) all agree.
 * Interest paid on the facility goes Dr 5110 / Cr the bank.
 */
class BorrowingOverdraftAccountingTest extends TestCase
{
    use DatabaseTransactions;

    private BankAccount $bank;

    private int $branchId;

    protected function setUp(): void
    {
        parent::setUp();
        ChartOfAccount::where('code', '2210')->exists() ?: $this->markTestSkipped('Chart of accounts not seeded.');
        $admin = User::whereHas('role', fn ($q) => $q->where('slug', 'admin'))->first()
            ?? $this->markTestSkipped('No admin user seeded.');
        $this->actingAs($admin, 'sanctum');

        $this->branchId = DB::table('branches')->insertGetId(['name' => 'OD', 'code' => 'OD' . Str::random(3), 'branch_type' => 'Branch', 'status' => 'Active', 'created_at' => now(), 'updated_at' => now()]);
        $this->bank = BankAccount::create([
            'branch_id' => $this->branchId, 'bank_id' => DB::table('banks')->value('id'), 'name' => 'OD Current ' . Str::random(3),
            'account_number' => 'OD' . random_int(100000, 999999), 'account_type' => 'Current',
            'opening_balance' => 0, 'current_balance' => 0, 'status' => 'Active',
        ]);
        ChartOfAccount::forBankAccount($this->bank);
    }

    private function h(): array
    {
        return ['Idempotency-Key' => (string) Str::uuid()];
    }

    private function leaf(): string
    {
        $r = DB::table('journal_lines')->where('account_id', ChartOfAccount::forBankAccount($this->bank->fresh())->id)
            ->selectRaw('COALESCE(SUM(debit),0) d, COALESCE(SUM(credit),0) c')->first();

        return bcsub((string) $r->d, (string) $r->c, 2);
    }

    public function test_overdraft_drawdown_utilisation_balance_sheet_and_interest_agree(): void
    {
        $res = $this->postJson('/api/borrowings', [
            'branch_id' => $this->branchId, 'type' => 'Overdraft', 'lender_name' => 'Commercial Bank',
            'principal_amount' => '50000.00', 'interest_rate_percent' => 14, 'borrowing_date' => now()->toDateString(),
            'bank_account_id' => $this->bank->id, 'set_overdraft_limit' => true,
        ], $this->h());
        $res->assertStatus(201);
        $id = $res->json('id');
        $this->assertSame('50000.00', (string) $this->bank->fresh()->overdraft_limit);
        $this->assertSame('0.00', (string) $res->json('outstanding_amount'));
        $this->assertSame(0, DB::table('journal_entries')->where('source_type', 'Borrowing')->where('source_id', $id)->count(), 'registering posts nothing');

        // draw 30 000 through the Bank module -> account goes to -30 000
        $this->postJson('/api/bank-transactions', [
            'bank_account_id' => $this->bank->id, 'txn_date' => now()->toDateString(), 'transaction_type' => 'Withdrawal',
            'amount' => '30000.00', 'contra_kind' => 'Ledger', 'contra_chart_of_account_id' => ChartOfAccount::system('5010')->id,
        ], $this->h())->assertStatus(201);

        $this->assertSame('-30000.00', (string) $this->bank->fresh()->current_balance);
        $this->assertSame('-30000.00', $this->leaf(), 'ledger leaf agrees with the cached balance');
        $show = $this->getJson("/api/borrowings/{$id}")->assertOk();
        $this->assertSame('30000.00', (string) $show->json('outstanding_amount'), 'live utilisation == drawn');

        // beyond the sanctioned limit is refused
        $this->postJson('/api/bank-transactions', [
            'bank_account_id' => $this->bank->id, 'txn_date' => now()->toDateString(), 'transaction_type' => 'Withdrawal',
            'amount' => '25000.00', 'contra_kind' => 'Ledger', 'contra_chart_of_account_id' => ChartOfAccount::system('5010')->id,
        ], $this->h())->assertStatus(422);

        // balance sheet: overdrawn bank is a liability, and A = L + E still holds
        $bs = $this->getJson('/api/accounting-reports/balance-sheet')->assertOk()->json();
        $row = collect($bs['liabilities'])->firstWhere('account_id', ChartOfAccount::forBankAccount($this->bank)->id);
        $this->assertNotNull($row, 'overdraft appears under liabilities');
        $this->assertSame('30000.00', (string) $row['balance']);
        $this->assertNull(collect($bs['assets'])->firstWhere('account_id', ChartOfAccount::forBankAccount($this->bank)->id));
        $this->assertTrue((bool) $bs['balanced']);

        // interest paid to the bank from the overdraft account: Dr 5110 / Cr bank (deeper overdraft)
        $this->postJson("/api/borrowings/{$id}/transactions", [
            'kind' => 'InterestPayment', 'amount' => '350.00', 'txn_date' => now()->toDateString(),
            'payment_method' => 'Bank', 'bank_account_id' => $this->bank->id,
        ], $this->h())->assertStatus(201);
        $this->assertSame('-30350.00', $this->leaf());
        $this->assertSame('-30350.00', (string) $this->bank->fresh()->current_balance);
        $this->assertSame('30350.00', (string) $this->getJson("/api/borrowings/{$id}")->json('outstanding_amount'));

        // principal draw/repay are not recorded on the facility screen
        $this->postJson("/api/borrowings/{$id}/transactions", [
            'kind' => 'PrincipalRepayment', 'amount' => '100.00', 'txn_date' => now()->toDateString(), 'payment_method' => 'Bank', 'bank_account_id' => $this->bank->id,
        ], $this->h())->assertStatus(422);
    }
}
