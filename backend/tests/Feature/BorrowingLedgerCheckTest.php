<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\Borrowing;
use App\Models\CashAccount;
use App\Models\ChartOfAccount;
use App\Models\User;
use App\Support\BorrowingLedgerCheck;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The borrowings-vs-ledger reconciliation: quiet after a full real lifecycle
 * (every method, accrual, interest payment, renewal, void), loud when a
 * borrowing's balance is changed behind the ledger's back or a borrowing was
 * recorded without its legs ever posting.
 */
class BorrowingLedgerCheckTest extends TestCase
{
    use DatabaseTransactions;

    private CashAccount $till;

    private BankAccount $bank;

    private int $branchId;

    protected function setUp(): void
    {
        parent::setUp();
        ChartOfAccount::where('code', '2220')->exists() ?: $this->markTestSkipped('Chart lacks 2220 - run the borrowing accrual migration on the test DB.');
        $admin = User::whereHas('role', fn ($q) => $q->where('slug', 'admin'))->first() ?? $this->markTestSkipped('No admin user.');
        $this->actingAs($admin, 'sanctum');
        $this->branchId = DB::table('branches')->insertGetId(['name' => 'LC', 'code' => 'LC' . Str::random(3), 'branch_type' => 'Branch', 'status' => 'Active', 'created_at' => now(), 'updated_at' => now()]);
        $this->till = CashAccount::create(['code' => 'LC-CASH-' . Str::random(3), 'branch_id' => $this->branchId, 'account_type' => 'BranchMain', 'name' => 'LC Till', 'opening_balance' => 0, 'current_balance' => '50000.00', 'status' => 'Active']);
        $this->bank = BankAccount::where('account_type', 'Current')->where('status', 'Active')->first() ?? $this->markTestSkipped('No Current bank account.');
    }

    private function h(): array
    {
        return ['Idempotency-Key' => (string) Str::uuid()];
    }

    private function findingsFor(int $borrowingId): array
    {
        return array_values(array_filter(BorrowingLedgerCheck::run()['borrowings'], fn ($r) => $r['id'] === $borrowingId));
    }

    private function lifecycle(): Borrowing
    {
        $res = $this->postJson('/api/borrowings', [
            'branch_id' => $this->branchId, 'type' => 'Party', 'lender_name' => 'ACME Finance PLC', 'principal_amount' => '10000.00',
            'interest_rate_percent' => 12, 'term_months' => 12, 'borrowing_date' => now()->subDays(61)->toDateString(),
            'receipt_method' => 'Bank', 'receipt_bank_account_id' => $this->bank->id,
        ], $this->h());
        $res->assertStatus(201);
        $b = Borrowing::find($res->json('id'));

        $this->artisan('borrowings:accrue-interest', ['--through' => now()->subDays(31)->toDateString()]);
        $t = fn (array $x) => $this->postJson("/api/borrowings/{$b->id}/transactions", $x + ['txn_date' => now()->subDays(20)->toDateString()], $this->h())->assertStatus(201);
        $t(['kind' => 'Drawdown', 'amount' => '2000.00', 'payment_method' => 'Cash', 'cash_account_id' => $this->till->id]);
        $t(['kind' => 'InterestPayment', 'amount' => '150.00', 'payment_method' => 'Bank', 'bank_account_id' => $this->bank->id]);
        $t(['kind' => 'PrincipalRepayment', 'amount' => '3000.00', 'payment_method' => 'Cheque', 'bank_account_id' => $this->bank->id]);
        $this->postJson("/api/borrowings/{$b->id}/renew", [
            'renewed_on' => now()->toDateString(), 'new_maturity_date' => now()->addMonths(6)->toDateString(),
            'interest_paid_amount' => '50.00', 'payment_method' => 'Cash', 'cash_account_id' => $this->till->id,
        ], $this->h())->assertOk();

        return $b->fresh();
    }

    public function test_a_full_lifecycle_reconciles_exactly(): void
    {
        $b = $this->lifecycle();
        $this->assertSame([], $this->findingsFor($b->id));
        $this->assertGreaterThan(0, (float) $b->outstanding_amount);

        // void the latest payment and delete-path stays reconciled
        $last = DB::table('borrowing_transactions')->where('borrowing_id', $b->id)->max('id');
        $this->postJson("/api/borrowing-transactions/{$last}/void")->assertOk();
        $this->assertSame([], $this->findingsFor($b->id));
    }

    public function test_changing_a_balance_behind_the_ledgers_back_is_flagged(): void
    {
        $b = $this->lifecycle();
        DB::table('borrowings')->where('id', $b->id)->update(['outstanding_amount' => DB::raw('outstanding_amount - 500')]);
        DB::table('borrowings')->where('id', $b->id)->update(['accrued_interest_unpaid' => DB::raw('accrued_interest_unpaid + 7.25')]);

        $f = collect($this->findingsFor($b->id))->keyBy('what');
        $this->assertSame('-500.00', $f['Outstanding']['variance']);
        $this->assertSame('7.25', $f['Accrued interest']['variance']);
        $this->assertSame('2200', $f['Outstanding']['gl_code']);
    }

    public function test_a_borrowing_recorded_without_its_legs_posting_is_flagged_and_the_command_fails(): void
    {
        $legacy = Borrowing::create([
            'borrowing_number' => 'LC-LEG-' . Str::random(4), 'branch_id' => $this->branchId, 'type' => 'Party', 'lender_name' => 'Legacy lender',
            'principal_amount' => '8000.00', 'outstanding_amount' => '8000.00', 'borrowing_date' => now()->toDateString(),
            'receipt_method' => 'Bank', 'receipt_bank_account_id' => $this->bank->id, 'status' => 'Active',
        ]);
        $f = $this->findingsFor($legacy->id);
        $this->assertCount(1, $f);
        $this->assertSame('8000.00', $f[0]['variance']);

        $this->assertSame(1, Artisan::call('borrowings:check-ledger'), 'non-zero exit when anything disagrees');
        $this->assertDatabaseHas('finance_audit_events', ['action' => 'ledger.borrowing_drift_detected']);
        $this->assertStringContainsString($legacy->borrowing_number, Artisan::output());

        // the whole-account check sees it too (2200 holds less than the borrowings claim)
        $acc = collect(BorrowingLedgerCheck::run()['accounts'])->firstWhere('gl_code', '2200');
        $this->assertNotNull($acc);
        $this->assertSame(0, bccomp($acc['variance'], '8000.00', 2), 'account-level drift includes the unposted 8000');
    }

    public function test_overdraft_and_voided_borrowings_are_not_checked(): void
    {
        $od = Borrowing::create([
            'borrowing_number' => 'LC-OD-' . Str::random(4), 'branch_id' => $this->branchId, 'type' => 'Overdraft', 'lender_name' => 'Bank',
            'principal_amount' => '5000.00', 'outstanding_amount' => '0', 'borrowing_date' => now()->toDateString(), 'bank_account_id' => $this->bank->id, 'status' => 'Active',
        ]);
        $void = Borrowing::create([
            'borrowing_number' => 'LC-VD-' . Str::random(4), 'branch_id' => $this->branchId, 'type' => 'Party', 'lender_name' => 'X',
            'principal_amount' => '900.00', 'outstanding_amount' => '900.00', 'borrowing_date' => now()->toDateString(), 'status' => 'Void',
        ]);
        $this->assertSame([], $this->findingsFor($od->id));
        $this->assertSame([], $this->findingsFor($void->id));
    }
}
