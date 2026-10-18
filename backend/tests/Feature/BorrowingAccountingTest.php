<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\Borrowing;
use App\Models\CashAccount;
use App\Models\ChartOfAccount;
use App\Models\FinanceTransaction;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Borrowings -> general ledger, end to end through the HTTP API against the
 * cloned working data (real chart of accounts, users and bank accounts).
 * Covers every payment method, not just Cash. DatabaseTransactions.
 */
class BorrowingAccountingTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private int $branchId;

    private CashAccount $till;

    private BankAccount $bank;

    protected function setUp(): void
    {
        parent::setUp();
        ChartOfAccount::where('code', '2200')->exists() ?: $this->markTestSkipped('Chart of accounts not seeded.');
        $this->admin = User::whereHas('role', fn ($q) => $q->where('slug', 'admin'))->first()
            ?? $this->markTestSkipped('No admin user seeded.');
        $this->actingAs($this->admin, 'sanctum');

        $this->branchId = DB::table('branches')->insertGetId(['name' => 'BW', 'code' => 'BW' . Str::random(3), 'branch_type' => 'Branch', 'status' => 'Active', 'created_at' => now(), 'updated_at' => now()]);
        $this->till = CashAccount::create(['code' => 'BW-CASH-' . Str::random(3), 'branch_id' => $this->branchId, 'account_type' => 'BranchMain', 'name' => 'BW Till', 'opening_balance' => 0, 'current_balance' => '20000.00', 'status' => 'Active']);
        $this->bank = BankAccount::where('account_type', 'Current')->where('status', 'Active')->first()
            ?? $this->markTestSkipped('No active Current bank account in the working data.');
    }

    private function glBalance(string $code): string
    {
        $id = ChartOfAccount::system($code)->id;
        $r = DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_lines.account_id', $id)->where('journal_entries.status', '!=', 'Draft')
            ->selectRaw('COALESCE(SUM(debit),0) d, COALESCE(SUM(credit),0) c')->first();

        return bcsub((string) $r->d, (string) $r->c, 2);
    }

    private function leafBalance(ChartOfAccount $leaf): string
    {
        $r = DB::table('journal_lines')->where('account_id', $leaf->id)
            ->selectRaw('COALESCE(SUM(debit),0) d, COALESCE(SUM(credit),0) c')->first();

        return bcsub((string) $r->d, (string) $r->c, 2);
    }

    private function create(array $over = []): array
    {
        $res = $this->postJson('/api/borrowings', $over + [
            'branch_id' => $this->branchId, 'type' => 'Party', 'lender_name' => 'ACME Finance PLC',
            'principal_amount' => '10000.00', 'interest_rate_percent' => 12, 'term_months' => 6,
            'borrowing_date' => now()->toDateString(), 'on_maturity' => 'Settle',
            'receipt_method' => 'Cash', 'receipt_cash_account_id' => $this->till->id,
        ], ['Idempotency-Key' => (string) Str::uuid()]);

        return [$res, $res->json()];
    }

    private function txn(int $id, array $body)
    {
        return $this->postJson("/api/borrowings/{$id}/transactions", $body + ['txn_date' => now()->toDateString()], ['Idempotency-Key' => (string) Str::uuid()]);
    }

    public function test_bank_receipt_moves_the_bank_account_and_the_liability(): void
    {
        $bankLeaf = ChartOfAccount::forBankAccount($this->bank);
        $bankBefore = (string) $this->bank->fresh()->current_balance;
        $glBefore = $this->leafBalance($bankLeaf);
        $liabBefore = $this->glBalance('2200');

        [$res, $b] = $this->create(['receipt_method' => 'Bank', 'receipt_bank_account_id' => $this->bank->id, 'receipt_cash_account_id' => null]);
        $res->assertStatus(201);

        $this->assertSame(bcadd($bankBefore, '10000.00', 2), (string) $this->bank->fresh()->current_balance);
        $this->assertSame(bcadd($glBefore, '10000.00', 2), $this->leafBalance($bankLeaf), 'bank ledger leaf must rise');
        $this->assertSame(bcsub($liabBefore, '10000.00', 2), $this->glBalance('2200'), '2200 liability is credit-normal');
        $ftx = FinanceTransaction::where('source_module', 'Borrowing')->where('source_id', $b['id'])->first();
        $this->assertNotNull($ftx);
        $this->assertSame('BANK_DEPOSIT', $ftx->txn_type);
        $this->assertSame('20000.00', (string) $this->till->fresh()->current_balance, 'till untouched');

        // delete reverses everything
        $this->deleteJson("/api/borrowings/{$b['id']}")->assertStatus(204);
        $this->assertSame($bankBefore, (string) $this->bank->fresh()->current_balance);
        $this->assertSame($glBefore, $this->leafBalance($bankLeaf));
        $this->assertSame($liabBefore, $this->glBalance('2200'));
    }

    public function test_unbanked_cheque_receipt_parks_in_undeposited_funds_and_reverses(): void
    {
        $u0 = $this->glBalance('1030');
        $l0 = $this->glBalance('2200');
        [$res, $b] = $this->create(['receipt_method' => 'Cheque', 'receipt_cash_account_id' => null]);
        $res->assertStatus(201);

        $this->assertSame(bcadd($u0, '10000.00', 2), $this->glBalance('1030'));
        $this->assertSame(bcsub($l0, '10000.00', 2), $this->glBalance('2200'));
        $this->assertSame('20000.00', (string) $this->till->fresh()->current_balance);

        $this->deleteJson("/api/borrowings/{$b['id']}")->assertStatus(204);
        $this->assertSame($u0, $this->glBalance('1030'));
        $this->assertSame($l0, $this->glBalance('2200'));
    }

    public function test_full_lifecycle_with_bank_repayments_balances_to_zero(): void
    {
        [, $b] = $this->create(); // cash in 10 000
        $this->assertSame('30000.00', (string) $this->till->fresh()->current_balance);
        $bankLeaf = ChartOfAccount::forBankAccount($this->bank);
        $bankBefore = (string) $this->bank->fresh()->current_balance;
        $bankGlBefore = $this->leafBalance($bankLeaf);
        $expense0 = $this->glBalance('5110');

        // interest by bank, part principal by cheque drawn on the bank, then settle in cash
        $this->txn($b['id'], ['kind' => 'InterestPayment', 'amount' => '600.00', 'payment_method' => 'Bank', 'bank_account_id' => $this->bank->id])->assertStatus(201);
        $this->txn($b['id'], ['kind' => 'PrincipalRepayment', 'amount' => '4000.00', 'payment_method' => 'Cheque', 'bank_account_id' => $this->bank->id])->assertStatus(201);
        $this->assertSame(bcsub($bankBefore, '4600.00', 2), (string) $this->bank->fresh()->current_balance);
        $this->assertSame(bcsub($bankGlBefore, '4600.00', 2), $this->leafBalance($bankLeaf));
        $this->assertSame(bcadd($expense0, '600.00', 2), $this->glBalance('5110'));

        $last = $this->txn($b['id'], ['kind' => 'Settlement', 'amount' => '6000.00', 'payment_method' => 'Cash', 'cash_account_id' => $this->till->id]);
        $last->assertStatus(201);
        $this->assertSame('Settled', $last->json('status'));
        $this->assertSame('0.00', (string) $last->json('outstanding_amount'));
        $this->assertSame('24000.00', (string) $this->till->fresh()->current_balance);

        // liability for this borrowing nets to zero: every JE tied to it balances and 2200 delta is 0
        $txnIds = collect($last->json("transactions"))->pluck("id")->all();
        $je = JournalEntry::where(fn ($q) => $q->where("source_type", "Borrowing")->where("source_id", $b["id"])->orWhere(fn ($q2) => $q2->where("source_type", "BorrowingTransaction")->whereIn("source_id", $txnIds)))->get();
        // 1 drawdown + 3 txns; the two bank-paid txns each add a mirror entry for the bank's own branch (branch 1 vs the borrowing branch)
        $this->assertCount(6, $je);
        foreach ($je as $e) {
            $this->assertSame(
                (string) DB::table('journal_lines')->where('journal_entry_id', $e->id)->sum('debit'),
                (string) DB::table('journal_lines')->where('journal_entry_id', $e->id)->sum('credit')
            );
        }
        $liabLines = DB::table('journal_lines')->whereIn('journal_entry_id', $je->pluck('id'))->where('account_id', ChartOfAccount::system('2200')->id)
            ->selectRaw('SUM(debit) d, SUM(credit) c')->first();
        $this->assertSame('0.00', bcsub((string) $liabLines->d, (string) $liabLines->c, 2));

        // void latest (the settlement) restores balance and re-opens
        $settleId = collect($last->json('transactions'))->max('id');
        $v = $this->postJson("/api/borrowing-transactions/{$settleId}/void");
        $v->assertStatus(200);
        $this->assertSame('Active', $v->json('status'));
        $this->assertSame('30000.00', (string) $this->till->fresh()->current_balance);
    }

    public function test_topup_drawdown_by_bank_and_outgoing_cheque_needs_a_bank_account(): void
    {
        [, $b] = $this->create();
        $bankBefore = (string) $this->bank->fresh()->current_balance;

        $this->txn($b['id'], ['kind' => 'Drawdown', 'amount' => '2500.00', 'payment_method' => 'Bank', 'bank_account_id' => $this->bank->id])->assertStatus(201);
        $this->assertSame(bcadd($bankBefore, '2500.00', 2), (string) $this->bank->fresh()->current_balance);

        $this->txn($b['id'], ['kind' => 'InterestPayment', 'amount' => '100.00', 'payment_method' => 'Cheque'])
            ->assertStatus(422)->assertJsonValidationErrors('bank_account_id');
        $this->txn($b['id'], ['kind' => 'InterestPayment', 'amount' => '100.00', 'payment_method' => 'Bank'])
            ->assertStatus(422)->assertJsonValidationErrors('bank_account_id');
    }

    public function test_failed_posting_leaves_balance_and_ledger_untouched(): void
    {
        [, $b] = $this->create();
        // bank can't pay out more than it holds (Current w/o overdraft) -> whole request rolls back
        $this->bank->update(['current_balance' => '50.00', 'overdraft_limit' => 0]);
        $res = $this->txn($b['id'], ['kind' => 'PrincipalRepayment', 'amount' => '5000.00', 'payment_method' => 'Bank', 'bank_account_id' => $this->bank->id]);
        $this->assertGreaterThanOrEqual(400, $res->status());
        $this->assertSame('10000.00', (string) Borrowing::find($b['id'])->outstanding_amount);
        $this->assertSame(0, DB::table('borrowing_transactions')->where('borrowing_id', $b['id'])->count());
        $this->assertSame('50.00', (string) $this->bank->fresh()->current_balance);
    }
}
