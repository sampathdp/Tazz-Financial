<?php

namespace Tests\Feature;

use App\Models\CashAccount;
use App\Models\ChartOfAccount;
use App\Models\Expense;
use App\Models\FinanceTransaction;
use App\Models\PettyCashFund;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Plan: docs/audit/test-plans/expenses.md
 * Findings: docs/audit/failures.md
 *
 * Already covered by ExpensePaymentRequestFlowTest (not re-implemented):
 *   EXP-03 (request path), EXP-08 (void of a paid expense), direct-pay-blocked-
 *   while-request-pending.
 * This file: EXP-01/02 (exact GL lines), EXP-04, EXP-05, EXP-06, EXP-07,
 *   EXP-10, EXP-11, EXP-12, EXP-13, EXP-14.
 */
class ExpenseLedgerTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private int $branchId;

    private CashAccount $till;

    private ChartOfAccount $tillLeaf;

    private int $categoryId;

    protected function setUp(): void
    {
        parent::setUp();
        if (! ChartOfAccount::where('code', '5010')->exists()) {
            $this->markTestSkipped('Chart of accounts not seeded.');
        }
        $this->admin = User::whereHas('role', fn ($q) => $q->where('slug', 'admin'))->first()
            ?? $this->markTestSkipped('No admin user seeded.');

        $this->branchId = DB::table('branches')->insertGetId([
            'name' => 'EXP Branch', 'code' => 'EXP' . Str::random(3),
            'branch_type' => 'Branch', 'status' => 'Active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->till = CashAccount::create([
            'code' => 'EXP-CASH-' . Str::random(6), 'branch_id' => $this->branchId, 'account_type' => 'BranchMain',
            'name' => 'EXP Till', 'opening_balance' => 0, 'current_balance' => '2000000.00', 'status' => 'Active',
        ]);
        $this->tillLeaf = ChartOfAccount::forCashTill($this->till->fresh());

        $this->categoryId = DB::table('expense_categories')->insertGetId([
            'name' => 'EXP Cat ' . Str::random(4), 'code' => 'EC' . Str::random(5),
            'chart_of_account_id' => $this->acct('5010'),
            'status' => 'Active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($this->admin, 'sanctum');
    }

    private function acct(string $code): int
    {
        return ChartOfAccount::where('code', $code)->value('id');
    }

    private function glBalance(int $accountId): string
    {
        ChartOfAccount::flushBalancesCache();
        $r = ChartOfAccount::balancesAsOf(now()->addYears(5)->toDateString())->get($accountId);

        return $r ? (string) $r->balance : '0.00';
    }

    private function assertBc(string $e, string $a, string $m = ''): void
    {
        $this->assertSame(0, bccomp($e, $a, 2), $m ?: "expected {$e} got {$a}");
    }

    private function approvedExpense(string $amount, ?string $date = null): Expense
    {
        return Expense::create([
            'code' => 'EX' . Str::random(8), 'branch_id' => $this->branchId,
            'expense_category_id' => $this->categoryId, 'amount' => $amount,
            'expense_date' => $date ?? now()->toDateString(), 'description' => 'test expense',
            'status' => 'Approved', 'requested_by' => $this->admin->id,
            'approved_by' => $this->admin->id, 'approved_at' => now(),
        ]);
    }

    /** A petty cash fund started at 0 and funded to $balance via a real replenishment
     *  from the till (so its GL leaf and current_balance stay in step, as in production). */
    private function pettyFund(string $limit, string $balance): PettyCashFund
    {
        $fund = PettyCashFund::create([
            'code' => 'PCF' . Str::random(5), 'branch_id' => $this->branchId, 'custodian_id' => $this->admin->id,
            'name' => 'EXP Petty', 'float_limit' => $limit, 'current_balance' => '0.00', 'status' => 'Active',
        ]);
        if (bccomp($balance, '0.00', 2) > 0) {
            $this->postJson('/api/petty-cash-replenishments', [
                'petty_cash_fund_id' => $fund->id, 'amount' => (float) $balance,
                'funding_method' => 'Cash', 'cash_account_id' => $this->till->id,
            ])->assertStatus(201);
        }

        return $fund->fresh();
    }

    private function linesFor(string $sourceType, int $sourceId): array
    {
        $rows = DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.source_type', $sourceType)->where('journal_entries.source_id', $sourceId)
            ->get(['journal_lines.account_id', 'journal_lines.debit', 'journal_lines.credit']);
        $out = [];
        foreach ($rows as $r) {
            $out[$r->account_id] = ['debit' => (string) $r->debit, 'credit' => (string) $r->credit];
        }

        return $out;
    }

    // =============================================================
    // EXP-01 — direct expense payment, Cash: exact GL lines
    // =============================================================
    public function test_exp01_direct_expense_payment_cash(): void
    {
        $c5010 = $this->acct('5010');
        $before5010 = $this->glBalance($c5010);
        $tillCashBefore = (string) $this->till->fresh()->current_balance;

        $expense = $this->approvedExpense('50000.00');
        $this->postJson("/api/expenses/{$expense->id}/pay", [
            'payment_method' => 'Cash', 'cash_account_id' => $this->till->id,
        ])->assertStatus(201);

        $lines = $this->linesFor('Expense', $expense->id);
        $this->assertBc('50000.00', $lines[$c5010]['debit'], 'Dr 5010');
        $this->assertBc('50000.00', $lines[$this->tillLeaf->id]['credit'], 'Cr till leaf');

        $this->assertBc('50000.00', bcsub($this->glBalance($c5010), $before5010, 2));
        $this->assertBc(bcsub($tillCashBefore, '50000.00', 2), (string) $this->till->fresh()->current_balance);

        $ftx = FinanceTransaction::where('source_module', 'Expense')->where('source_id', $expense->id)->first();
        $this->assertSame('EXPENSE_PAYMENT', $ftx->txn_type);
        $this->assertSame('expense-payment-' . $expense->id, $ftx->idempotency_key);
        $this->assertSame('Paid', $expense->fresh()->status);
    }

    // =============================================================
    // EXP-02 — direct expense payment, Bank
    // =============================================================
    public function test_exp02_direct_expense_payment_bank(): void
    {
        $bankRowId = DB::table('banks')->insertGetId(['name' => 'EXP Bank', 'created_at' => now(), 'updated_at' => now()]);
        $bank = \App\Models\BankAccount::create([
            'branch_id' => $this->branchId, 'bank_id' => $bankRowId, 'name' => 'EXP Bank Acc',
            'account_number' => 'ACC' . Str::random(10), 'account_type' => 'Current', 'currency' => 'LKR',
            'opening_balance' => 0, 'current_balance' => '1000000.00', 'status' => 'Active',
        ]);
        $bankLeaf = ChartOfAccount::forBankAccount($bank->fresh());
        $c5010 = $this->acct('5010');

        $expense = $this->approvedExpense('50000.00');
        $this->postJson("/api/expenses/{$expense->id}/pay", [
            'payment_method' => 'Bank', 'bank_account_id' => $bank->id,
        ])->assertStatus(201);

        $lines = $this->linesFor('Expense', $expense->id);
        $this->assertBc('50000.00', $lines[$c5010]['debit']);
        $this->assertBc('50000.00', $lines[$bankLeaf->id]['credit']);
        $this->assertBc('950000.00', (string) $bank->fresh()->current_balance,
            'expense Bank payments DO post (not subject to Risk 1) — regression sentinel');
    }

    // =============================================================
    // EXP-04 — petty cash spend: fund cache == 1015-x leaf
    // =============================================================
    public function test_exp04_petty_cash_spend(): void
    {
        $fund = $this->pettyFund('50000.00', '50000.00');
        $fundLeaf = ChartOfAccount::forPettyCashFund($fund->fresh());
        $c5010 = $this->acct('5010');
        $before5010 = $this->glBalance($c5010);

        $this->postJson('/api/petty-cash-vouchers', [
            'petty_cash_fund_id' => $fund->id, 'expense_category_id' => $this->categoryId, 'amount' => 3500,
        ])->assertStatus(201);

        $this->assertBc('46500.00', (string) $fund->fresh()->current_balance, 'fund cache 50,000 - 3,500');
        $this->assertBc('46500.00', $this->glBalance($fundLeaf->id), 'fund GL leaf == cache (top-25 #14)');
        $this->assertBc('3500.00', bcsub($this->glBalance($c5010), $before5010, 2), 'Dr 5010 3,500');
    }

    // =============================================================
    // EXP-05 — petty cash spend beyond the fund balance is rejected
    // =============================================================
    public function test_exp05_petty_cash_spend_beyond_balance_rejected(): void
    {
        $fund = $this->pettyFund('50000.00', '3000.00');
        $entriesBefore = DB::table('journal_entries')->count();

        $this->postJson('/api/petty-cash-vouchers', [
            'petty_cash_fund_id' => $fund->id, 'expense_category_id' => $this->categoryId, 'amount' => 3500,
        ])->assertStatus(422);

        $this->assertBc('3000.00', (string) $fund->fresh()->current_balance, 'unchanged');
        $this->assertSame($entriesBefore, DB::table('journal_entries')->count(), 'no entry');
    }

    // =============================================================
    // EXP-06 — an approved-but-unpaid expense is a real liability, but 2300 is never posted (F-12)
    // =============================================================
    public function test_exp06_approved_unpaid_expense_never_hits_2300(): void
    {
        $c2300 = $this->acct('2300');
        $before2300 = $this->glBalance($c2300);
        $entriesBefore = DB::table('journal_entries')->count();

        $this->approvedExpense('50000.00');

        // Between approval and payment: no entries at all.
        $this->assertSame($entriesBefore, DB::table('journal_entries')->count());

        // Correct (accrual basis): 2300 Accrued Expenses should carry the 50,000 owed.
        $this->assertBc('50000.00', bcsub($this->glBalance($c2300), $before2300, 2),
            'F-12: an Approved-but-unpaid expense never posts to 2300 — the Balance Sheet understates payables. See docs/audit/failures.md#f-12');
    }

    // =============================================================
    // EXP-07 — no tax / withholding split exists (F-13)
    // =============================================================
    public function test_exp07_no_withholding_tax_split(): void
    {
        // A 100,000 invoice with 10% WHT: 10,000 withheld, 90,000 paid to the vendor.
        $expense = $this->approvedExpense('100000.00');
        $this->postJson("/api/expenses/{$expense->id}/pay", [
            'payment_method' => 'Cash', 'cash_account_id' => $this->till->id,
        ])->assertStatus(201);

        $entryId = DB::table('journal_entries')->where('source_type', 'Expense')->where('source_id', $expense->id)->value('id');
        $lineCount = DB::table('journal_lines')->where('journal_entry_id', $entryId)->count();

        // Current: 2 lines (gross), the full 100,000 leaves the till.
        // Correct: 3 lines — Dr 5010 100,000 / Cr <WHT payable> 10,000 / Cr till 90,000.
        $this->assertSame(3, $lineCount,
            'F-13: no WHT/VAT split — the full gross amount is expensed and paid, no withholding-payable leg. See docs/audit/failures.md#f-13');
    }

    // =============================================================
    // EXP-10 — backdated expense payment, no closed-period block
    // =============================================================
    public function test_exp10_backdated_expense_payment_accepted(): void
    {
        $c5010 = $this->acct('5010');
        $baseline2025 = $this->glBalance($c5010); // as-of far future includes everything; use a dated query
        $expense = $this->approvedExpense('7500.00');

        $this->postJson("/api/expenses/{$expense->id}/pay", [
            'payment_method' => 'Cash', 'cash_account_id' => $this->till->id, 'paid_at' => '2025-01-15',
        ])->assertStatus(201);

        $entry = DB::table('journal_entries')->where('source_type', 'Expense')->where('source_id', $expense->id)->first();
        $this->assertSame('2025-01-15', Carbon::parse($entry->entry_date)->toDateString(),
            'a backdated expense payment posts into a prior period with no block');
    }

    // =============================================================
    // EXP-11 — paying a Pending (not-yet-Approved) expense is rejected
    // =============================================================
    public function test_exp11_pay_pending_expense_rejected(): void
    {
        $expense = Expense::create([
            'code' => 'EX' . Str::random(8), 'branch_id' => $this->branchId,
            'expense_category_id' => $this->categoryId, 'amount' => '5000.00',
            'expense_date' => now()->toDateString(), 'status' => 'Pending', 'requested_by' => $this->admin->id,
        ]);
        $entriesBefore = DB::table('journal_entries')->count();

        $this->postJson("/api/expenses/{$expense->id}/pay", [
            'payment_method' => 'Cash', 'cash_account_id' => $this->till->id,
        ])->assertStatus(422);

        $this->assertSame($entriesBefore, DB::table('journal_entries')->count());
    }

    // =============================================================
    // EXP-12 — retrying pay() after it succeeded is rejected (expenses ARE guarded)
    // =============================================================
    public function test_exp12_retry_pay_after_success_is_rejected(): void
    {
        $expense = $this->approvedExpense('5000.00');
        $this->postJson("/api/expenses/{$expense->id}/pay", [
            'payment_method' => 'Cash', 'cash_account_id' => $this->till->id,
        ])->assertStatus(201);

        $tillAfterFirst = (string) $this->till->fresh()->current_balance;

        // status is now 'Paid' -> second call 422s.
        $this->postJson("/api/expenses/{$expense->id}/pay", [
            'payment_method' => 'Cash', 'cash_account_id' => $this->till->id,
        ])->assertStatus(422);

        $this->assertBc($tillAfterFirst, (string) $this->till->fresh()->current_balance, 'no second movement');
        $this->assertSame(1, FinanceTransaction::where('source_module', 'Expense')->where('source_id', $expense->id)->count());
    }

    // =============================================================
    // EXP-13 — petty cash top-up (replenishment)
    // =============================================================
    public function test_exp13_petty_cash_top_up(): void
    {
        $fund = $this->pettyFund('50000.00', '20000.00');
        $fundLeaf = ChartOfAccount::forPettyCashFund($fund->fresh());
        $tillCashBefore = (string) $this->till->fresh()->current_balance;

        $this->postJson('/api/petty-cash-replenishments', [
            'petty_cash_fund_id' => $fund->id, 'amount' => 25000,
            'funding_method' => 'Cash', 'cash_account_id' => $this->till->id,
        ])->assertStatus(201);

        $this->assertBc('45000.00', (string) $fund->fresh()->current_balance);
        $this->assertBc(bcsub($tillCashBefore, '25000.00', 2), (string) $this->till->fresh()->current_balance);
        $this->assertBc('45000.00', $this->glBalance($fundLeaf->id), 'fund leaf 20,000 + 25,000');
    }

    // =============================================================
    // EXP-14 — top-up above float_limit rejected
    // =============================================================
    public function test_exp14_top_up_above_float_limit_rejected(): void
    {
        $fund = $this->pettyFund('50000.00', '45000.00');
        $entriesBefore = DB::table('journal_entries')->count();

        $this->postJson('/api/petty-cash-replenishments', [
            'petty_cash_fund_id' => $fund->id, 'amount' => 10000, // 45,000 + 10,000 = 55,000 > 50,000
            'funding_method' => 'Cash', 'cash_account_id' => $this->till->id,
        ])->assertStatus(422);

        $this->assertBc('45000.00', (string) $fund->fresh()->current_balance);
        $this->assertSame($entriesBefore, DB::table('journal_entries')->count());
    }
}
