<?php

namespace Tests\Feature;

use App\Exceptions\FinancePostingException;
use App\Models\FacilityPayment;
use App\Models\Loan;
use App\Models\Pawning;
use App\Models\SpeedDraft;
use App\Support\LoanLedgerPoster;
use App\Support\PawningLedgerPoster;
use App\Support\SpeedDraftLedgerPoster;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Audit finding C-8: when a branch has no usable till, a cash movement used to be skipped silently while the
 * loan / ticket / draft still went live with no ledger entry. It must now fail loudly (422) instead.
 */
class NoTillPostingTest extends TestCase
{
    use DatabaseTransactions;

    private int $branchId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->branchId = DB::table('branches')->insertGetId([
            'name' => 'NoTill ' . Str::random(5), 'code' => 'N' . Str::random(4), 'branch_type' => 'Branch', 'status' => 'Active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_cash_loan_disbursement_without_a_till_is_refused(): void
    {
        $loan = new Loan(['branch_id' => $this->branchId, 'disbursed_amount' => 1000]);
        $loan->setRelation('loanDetail', new \App\Models\LoanFacilityDetail(['disbursement_method' => 'Cash']));
        $this->expectException(FinancePostingException::class);
        LoanLedgerPoster::postDisbursement($loan);
    }

    public function test_cash_loan_receipt_without_a_till_is_refused(): void
    {
        $payment = new FacilityPayment(['branch_id' => $this->branchId, 'amount' => 500, 'payment_method' => 'Cash', 'payment_type' => 'Regular']);
        $this->expectException(FinancePostingException::class);
        LoanLedgerPoster::postCashReceipt($payment);
    }

    public function test_pawning_advance_without_a_till_is_refused(): void
    {
        $pawning = new Pawning(['branch_id' => $this->branchId, 'disbursed_amount' => 1000]);
        $this->expectException(FinancePostingException::class);
        PawningLedgerPoster::postDisbursement($pawning);
    }

    public function test_speed_draft_disbursement_without_a_till_is_refused(): void
    {
        $this->expectException(FinancePostingException::class);
        SpeedDraftLedgerPoster::postDisbursement(new SpeedDraft(['branch_id' => $this->branchId, 'disbursed_amount' => 1000]));
    }
}
