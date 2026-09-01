<?php

namespace Tests\Feature;

use App\Models\Borrowing;
use App\Models\CashAccount;
use App\Models\ChartOfAccount;
use App\Models\FdDeposit;
use App\Models\FinanceTransaction;
use App\Models\User;
use App\Support\BorrowingLedgerPoster;
use App\Support\FdLedgerPoster;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 9 — FD and Borrowing cash legs now go through FinancePostingService
 * (FdCashbookPoster / BorrowingCashbookPoster are gone). Each Cash-method
 * post must move the till, write a finance_transactions row and a
 * cashbook_entries mirror, all keyed to the FD/Borrowing journal entry.
 * DatabaseTransactions.
 */
class FdBorrowingCashLegTest extends TestCase
{
    use DatabaseTransactions;

    private CashAccount $till;

    private int $branchId;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        ChartOfAccount::where('code', '2010')->exists() ?: $this->markTestSkipped('Chart of accounts not seeded.');
        $this->admin = User::whereHas('role', fn ($q) => $q->where('slug', 'admin'))->first()
            ?? $this->markTestSkipped('No admin user seeded.');

        $this->branchId = DB::table('branches')->insertGetId(['name' => 'FB', 'code' => 'FB' . Str::random(3), 'branch_type' => 'Branch', 'status' => 'Active', 'created_at' => now(), 'updated_at' => now()]);
        $this->till = CashAccount::create(['code' => 'FB-CASH-' . Str::random(3), 'branch_id' => $this->branchId, 'account_type' => 'BranchMain', 'name' => 'FB Till', 'opening_balance' => 0, 'current_balance' => '20000.00', 'status' => 'Active']);
    }

    public function test_cash_fd_deposit_moves_the_till_and_mirrors(): void
    {
        $customerId = DB::table('customers')->value('id') ?? $this->markTestSkipped('No customer seeded.');
        $deposit = FdDeposit::create([
            'deposit_number' => 'FBD-' . Str::random(4), 'branch_id' => $this->branchId, 'customer_id' => $customerId,
            'deposit_date' => now()->toDateString(), 'amount' => '5000.00', 'payment_method' => 'Cash',
            'status' => 'Available', 'created_by' => $this->admin->id,
        ]);

        FdLedgerPoster::postDepositReceived($deposit);

        $this->assertSame('25000.00', (string) $this->till->fresh()->current_balance);

        $txn = FinanceTransaction::where('source_module', 'FdDeposit')->where('source_id', $deposit->id)->first();
        $this->assertNotNull($txn);
        $this->assertSame('CASH_IN', $txn->txn_type);
        $this->assertDatabaseHas('cashbook_entries', ['source_type' => 'FinanceTransaction', 'source_id' => $txn->id, 'direction' => 'In']);
        $this->assertDatabaseHas('journal_entries', ['id' => $txn->journal_entry_id, 'source_type' => 'FdDeposit', 'source_id' => $deposit->id]);

        // Idempotent — a re-post returns the same transaction, no second movement.
        FdLedgerPoster::postDepositReceived($deposit);
        $this->assertSame('25000.00', (string) $this->till->fresh()->current_balance);

        FdLedgerPoster::reverseDepositReceived($deposit, $this->admin->id);
        $this->assertSame('20000.00', (string) $this->till->fresh()->current_balance);
        $this->assertSame('Reversed', $txn->fresh()->status);
    }

    public function test_cash_borrowing_drawdown_moves_the_till_and_mirrors(): void
    {
        $borrowing = Borrowing::create([
            'borrowing_number' => 'FBB-' . Str::random(4), 'branch_id' => $this->branchId, 'type' => 'Party',
            'lender_name' => 'ACME', 'source' => 'Director', 'principal_amount' => '8000.00', 'outstanding_amount' => '8000.00',
            'interest_rate_percent' => '12.00', 'term_months' => 6, 'borrowing_date' => now()->toDateString(),
            'maturity_date' => now()->addMonths(6)->toDateString(), 'on_maturity' => 'Renew',
            'receipt_method' => 'Cash', 'receipt_cash_account_id' => $this->till->id,
            'status' => 'Active', 'created_by' => $this->admin->id,
        ]);

        BorrowingLedgerPoster::postDrawdown($borrowing);

        $this->assertSame('28000.00', (string) $this->till->fresh()->current_balance);
        $txn = FinanceTransaction::where('source_module', 'Borrowing')->where('source_id', $borrowing->id)->first();
        $this->assertNotNull($txn);
        $this->assertDatabaseHas('cashbook_entries', ['source_type' => 'FinanceTransaction', 'source_id' => $txn->id, 'direction' => 'In']);

        BorrowingLedgerPoster::reverseDrawdown($borrowing, $this->admin->id);
        $this->assertSame('20000.00', (string) $this->till->fresh()->current_balance);
    }
}
