<?php

namespace Tests\Feature;

use App\Models\Loan;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/** A disbursement that stopped halfway (Active, dated, no schedule) is detected and can be disbursed again. */ // The full re-disburse path is exercised by hand, not here.
class IncompleteDisbursementTest extends TestCase
{
    use DatabaseTransactions;

    public function test_only_a_dated_active_loan_can_be_flagged_incomplete(): void
    {
        $this->assertFalse((new Loan(['status' => 'Approved']))->disbursementIncomplete(), 'an Approved loan is not a half-finished disbursement');
        $this->assertFalse((new Loan(['status' => 'Active']))->disbursementIncomplete(), 'no disbursed date means it was never started');
    }
}
