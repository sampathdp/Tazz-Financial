<?php

namespace Tests\Feature;

use App\Models\CashAccount;
use App\Models\ChartOfAccount;
use App\Models\FacilityPayment;
use App\Models\FinanceTransaction;
use App\Models\Loan;
use App\Support\LoanLedgerPoster;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 7 — a cash loan collection now goes through FinancePostingService
 * (txn_type COLLECTION): the till current_balance moves, a
 * finance_transactions row and a cashbook mirror are written, and a void
 * reverses all of it. DatabaseTransactions.
 */
class LoanCollectionFeedTest extends TestCase
{
    use DatabaseTransactions;

    private Loan $loan;

    private CashAccount $till;

    private ChartOfAccount $principal;

    private FacilityPayment $payment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->principal = ChartOfAccount::where('code', '1100')->first()
            ?? $this->markTestSkipped('Chart of accounts not seeded.');

        $branchId = DB::table('branches')->insertGetId(['name' => 'LC', 'code' => 'LC' . Str::random(3), 'branch_type' => 'Branch', 'status' => 'Active', 'created_at' => now(), 'updated_at' => now()]);
        $customerId = DB::table('customers')->insertGetId(['public_uuid' => (string) Str::uuid(), 'branch_id' => $branchId, 'code' => 'C' . Str::random(6), 'full_name' => 'LC Cust', 'status' => 'Active', 'created_at' => now(), 'updated_at' => now()]);

        $this->till = CashAccount::create(['code' => 'LC-CASH-' . Str::random(3), 'branch_id' => $branchId, 'account_type' => 'BranchMain', 'name' => 'LC Till', 'opening_balance' => 0, 'current_balance' => '10000.00', 'status' => 'Active']);

        $loanId = DB::table('credit_facilities')->insertGetId([
            'public_uuid' => (string) Str::uuid(), 'code' => 'L' . Str::random(8), 'facility_type' => 'Loan',
            'branch_id' => $branchId, 'customer_id' => $customerId, 'principal_amount' => '100000.00',
            'interest_rate_percent' => '3.000', 'term_months' => 6, 'disbursed_amount' => '100000.00',
            'outstanding_balance_cache' => '100000.00', 'status' => 'Active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->loan = Loan::findOrFail($loanId);

        $payId = DB::table('facility_payments')->insertGetId([
            'code' => 'PMT-' . Str::random(6), 'credit_facility_id' => $loanId, 'branch_id' => $branchId,
            'amount' => '8000.00', 'payment_type' => 'Regular', 'payment_method' => 'Cash', 'status' => 'Active',
            'approval_status' => 'Approved', 'received_by' => 1, 'paid_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('facility_payment_allocations')->insert([
            'facility_payment_id' => $payId, 'principal_applied' => '6500.00', 'interest_applied' => '1500.00', 'penalty_applied' => '0.00',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->payment = FacilityPayment::findOrFail($payId);
    }

    public function test_cash_collection_moves_the_till_and_writes_a_mirror(): void
    {
        LoanLedgerPoster::postCashReceipt($this->payment);

        $this->assertSame('18000.00', (string) $this->till->fresh()->current_balance);

        $txn = FinanceTransaction::where('source_module', 'LoanPayment')->where('source_id', $this->payment->id)->first();
        $this->assertNotNull($txn);
        $this->assertSame('COLLECTION', $txn->txn_type);
        $this->assertSame('In', $txn->direction);
        $this->assertSame('8000.00', (string) $txn->amount);

        $mirror = DB::table('cashbook_entries')->where('source_type', 'FinanceTransaction')->where('source_id', $txn->id)->first();
        $this->assertNotNull($mirror);
        $this->assertSame('In', $mirror->direction);
        $this->assertSame('Loan Collection', $mirror->category);
        $this->assertSame('18000.00', (string) $mirror->balance_after);

        // GL: Dr till leaf 8000 / Cr 1100 6500 / Cr 4010 1500
        $this->assertSame('Loan payment ' . $this->payment->code, $txn->journalEntry->description);
    }

    public function test_reverse_all_for_undoes_the_collection(): void
    {
        LoanLedgerPoster::postCashReceipt($this->payment);
        LoanLedgerPoster::reverseAllFor($this->payment->fresh(), 1);

        $this->assertSame('10000.00', (string) $this->till->fresh()->current_balance);
        $this->assertSame('Reversed', FinanceTransaction::where('source_module', 'LoanPayment')
            ->where('source_id', $this->payment->id)->where('txn_type', 'COLLECTION')->value('status'));
    }

    public function test_collection_is_idempotent(): void
    {
        LoanLedgerPoster::postCashReceipt($this->payment);
        LoanLedgerPoster::postCashReceipt($this->payment->fresh());

        $this->assertSame('18000.00', (string) $this->till->fresh()->current_balance);
        $this->assertSame(1, FinanceTransaction::where('source_module', 'LoanPayment')->where('source_id', $this->payment->id)->count());
    }
}
