<?php

namespace Tests\Feature;

use App\Models\CashAccount;
use App\Models\ChartOfAccount;
use App\Models\FinanceTransaction;
use App\Models\Loan;
use App\Support\LoanLedgerPoster;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 1b — a cash loan disbursement now moves the branch till's physical
 * current_balance and records a finance_transactions row, not just the GL
 * entry. Wrapped in DatabaseTransactions, rolls back.
 */
class LoanDisbursementPostingTest extends TestCase
{
    use DatabaseTransactions;

    private Loan $loan;

    private CashAccount $till;

    protected function setUp(): void
    {
        parent::setUp();

        if (! ChartOfAccount::where('code', '1100')->exists()) {
            $this->markTestSkipped('Chart of accounts not seeded.');
        }

        $branchId = DB::table('branches')->insertGetId([
            'name' => 'Disb Test Branch', 'code' => 'DTB' . Str::random(3),
            'branch_type' => 'Branch', 'status' => 'Active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $customerId = DB::table('customers')->insertGetId([
            'public_uuid' => (string) Str::uuid(), 'branch_id' => $branchId, 'code' => 'C' . Str::random(6),
            'full_name' => 'Disb Test Customer', 'status' => 'Active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $loanTypeId = DB::table('loan_types')->insertGetId([
            'name' => 'Disb Test Type', 'code' => 'DTT' . Str::random(3),
            'interest_method' => 'flat', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->till = CashAccount::create([
            'code' => 'DTB-CASH-' . Str::random(3), 'branch_id' => $branchId, 'account_type' => 'BranchMain',
            'name' => 'Disb Test Till', 'opening_balance' => 0, 'current_balance' => '500000.00', 'status' => 'Active',
        ]);

        $loanId = DB::table('credit_facilities')->insertGetId([
            'public_uuid' => (string) Str::uuid(), 'code' => 'L' . Str::random(8), 'facility_type' => 'Loan',
            'branch_id' => $branchId, 'customer_id' => $customerId, 'principal_amount' => '200000.00',
            'interest_rate_percent' => '3.000', 'term_months' => 12, 'disbursed_amount' => '200000.00',
            'disbursed_at' => now(), 'status' => 'Active', 'approved_by' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('loan_facility_details')->insert([
            'credit_facility_id' => $loanId, 'loan_type_id' => $loanTypeId, 'interest_method' => 'flat',
            'disbursement_method' => 'Cash',
        ]);

        $this->loan = Loan::findOrFail($loanId);
    }

    public function test_cash_disbursement_moves_the_till_and_records_a_finance_transaction(): void
    {
        LoanLedgerPoster::postDisbursement($this->loan, 1);

        $this->assertSame('300000.00', (string) $this->till->fresh()->current_balance);

        $ftx = FinanceTransaction::where('source_module', 'Loan')->where('source_id', $this->loan->id)->first();
        $this->assertNotNull($ftx);
        $this->assertSame('DISBURSEMENT', $ftx->txn_type);
        $this->assertSame('200000.00', (string) $ftx->amount);
        $this->assertSame($this->till->id, (int) $ftx->source_account_id);

        // The GL entry stays keyed to the loan (purge / reverseDisbursement rely on it).
        $this->assertSame('Loan', $ftx->journalEntry->source_type);
        $this->assertSame($this->loan->id, (int) $ftx->journalEntry->source_id);
    }

    public function test_disbursement_is_idempotent(): void
    {
        LoanLedgerPoster::postDisbursement($this->loan, 1);
        LoanLedgerPoster::postDisbursement($this->loan->fresh(), 1);

        $this->assertSame('300000.00', (string) $this->till->fresh()->current_balance);
        $this->assertSame(1, FinanceTransaction::where('source_module', 'Loan')->where('source_id', $this->loan->id)->count());
    }

    public function test_reverse_disbursement_restores_the_till(): void
    {
        LoanLedgerPoster::postDisbursement($this->loan, 1);
        LoanLedgerPoster::reverseDisbursement($this->loan->fresh(), 1);

        $this->assertSame('500000.00', (string) $this->till->fresh()->current_balance);
        $this->assertSame('Reversed', FinanceTransaction::where('source_module', 'Loan')
            ->where('source_id', $this->loan->id)->where('txn_type', 'DISBURSEMENT')->value('status'));
    }
}
