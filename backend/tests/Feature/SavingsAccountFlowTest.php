<?php

namespace Tests\Feature;

use App\Models\CashAccount;
use App\Models\ChartOfAccount;
use App\Models\FinanceTransaction;
use App\Models\SavingsAccount;
use App\Models\SavingsInterestRun;
use App\Models\SavingsProduct;
use App\Models\User;
use App\Support\SavingsAccountService;
use App\Support\SavingsInterestCalculator;
use App\Support\SavingsLedgerPoster;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Daily Savings — a Cash deposit / withdrawal moves the branch till through
 * FinancePostingService and posts Dr/Cr 2050; an interest run capitalises
 * Dr 5120 / Cr 2050 with no cash movement; the interest calculator agrees
 * with a hand computation. DatabaseTransactions.
 */
class SavingsAccountFlowTest extends TestCase
{
    use DatabaseTransactions;

    private CashAccount $till;

    private int $branchId;

    private User $admin;

    private SavingsProduct $product;

    protected function setUp(): void
    {
        parent::setUp();
        ChartOfAccount::where('code', '2050')->exists() ?: $this->markTestSkipped('Savings chart of accounts not seeded.');
        $this->admin = User::whereHas('role', fn ($q) => $q->where('slug', 'admin'))->first()
            ?? $this->markTestSkipped('No admin user seeded.');

        $this->branchId = DB::table('branches')->insertGetId(['name' => 'SV', 'code' => 'SV' . Str::random(3), 'branch_type' => 'Branch', 'status' => 'Active', 'created_at' => now(), 'updated_at' => now()]);
        $this->till = CashAccount::create(['code' => 'SV-CASH-' . Str::random(3), 'branch_id' => $this->branchId, 'account_type' => 'BranchMain', 'name' => 'SV Till', 'opening_balance' => 0, 'current_balance' => '10000.00', 'status' => 'Active']);
        $this->product = SavingsProduct::create([
            'name' => 'Daily', 'code' => 'DLY' . Str::random(3), 'interest_rate_percent' => '12.000',
            'interest_calc_method' => 'min_monthly_balance', 'interest_posting_frequency' => 'monthly',
            'min_opening_deposit' => '0', 'min_balance' => '0', 'withdrawal_fee' => '0', 'ledger_fee' => '0',
            'dormancy_days' => 0, 'status' => 'Active',
        ]);
    }

    private function account(): SavingsAccount
    {
        $customerId = DB::table('customers')->value('id') ?? $this->markTestSkipped('No customer seeded.');
        $account = SavingsAccount::create([
            'account_number' => 'SAV-' . Str::random(5), 'branch_id' => $this->branchId, 'customer_id' => $customerId,
            'savings_product_id' => $this->product->id, 'interest_rate_percent' => '12.000',
            'interest_calc_method' => 'min_monthly_balance', 'interest_posting_frequency' => 'monthly',
            'min_balance' => '0', 'withdrawal_fee' => '0', 'open_date' => now()->startOfMonth()->toDateString(),
            'status' => 'Active', 'created_by' => $this->admin->id,
        ]);
        $account->setRelation('savingsProduct', $this->product);
        $account->setRelation('branch', DB::table('branches')->find($this->branchId));

        return $account;
    }

    public function test_cash_deposit_and_withdrawal_move_the_till(): void
    {
        $account = $this->account();

        $dep = SavingsAccountService::record($account, ['type' => 'Deposit', 'direction' => 'In', 'amount' => '3000.00', 'txn_date' => now()->toDateString(), 'payment_method' => 'Cash', 'created_by' => $this->admin->id]);
        $dep->setRelation('savingsAccount', $account);
        SavingsLedgerPoster::postDeposit($dep);

        $this->assertSame('13000.00', (string) $this->till->fresh()->current_balance);
        $this->assertSame('3000.00', (string) $account->fresh()->current_balance);

        $txn = FinanceTransaction::where('source_module', 'SavingsTransaction')->where('source_id', $dep->id)->first();
        $this->assertNotNull($txn);
        $this->assertSame('CASH_IN', $txn->txn_type);
        $this->assertDatabaseHas('cashbook_entries', ['source_type' => 'FinanceTransaction', 'source_id' => $txn->id, 'category' => 'Savings', 'direction' => 'In']);
        $this->assertDatabaseHas('journal_entries', ['id' => $txn->journal_entry_id, 'source_type' => 'SavingsTransaction']);

        $wd = SavingsAccountService::record($account->fresh()->setRelation('branch', DB::table('branches')->find($this->branchId)), ['type' => 'Withdrawal', 'direction' => 'Out', 'amount' => '1000.00', 'txn_date' => now()->toDateString(), 'payment_method' => 'Cash', 'created_by' => $this->admin->id]);
        $wd->setRelation('savingsAccount', $account->fresh());
        SavingsLedgerPoster::postWithdrawal($wd);

        $this->assertSame('12000.00', (string) $this->till->fresh()->current_balance);
        $this->assertSame('2000.00', (string) $account->fresh()->current_balance);
    }

    public function test_interest_run_capitalises_without_cash(): void
    {
        $account = $this->account();
        $dep = SavingsAccountService::record($account, ['type' => 'Deposit', 'direction' => 'In', 'amount' => '12000.00', 'txn_date' => now()->startOfMonth()->toDateString(), 'payment_method' => 'Cash', 'created_by' => $this->admin->id]);
        $dep->setRelation('savingsAccount', $account);
        SavingsLedgerPoster::postDeposit($dep);
        $tillAfterDeposit = (string) $this->till->fresh()->current_balance;

        $from = now()->startOfMonth()->toDateString();
        $to = now()->endOfMonth()->toDateString();
        $calc = SavingsInterestCalculator::forAccount($account->fresh(), $from, $to);
        // 12000 * 12% / 12 = 120.00 for the one month at min balance 12000.
        $this->assertSame('120.00', $calc['interest']);

        $run = SavingsInterestRun::create(['run_number' => 'SIR-' . Str::random(4), 'period_from' => $from, 'period_to' => $to, 'status' => 'Draft', 'created_by' => $this->admin->id]);
        SavingsLedgerPoster::postInterestRun($run, $calc['interest'], $this->admin->id);

        // No till movement for the interest leg.
        $this->assertSame($tillAfterDeposit, (string) $this->till->fresh()->current_balance);
        $expense = ChartOfAccount::system('5120')->id;
        $this->assertDatabaseHas('journal_lines', ['account_id' => $expense, 'debit' => '120.00']);
    }
}
