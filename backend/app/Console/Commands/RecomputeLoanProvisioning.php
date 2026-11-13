<?php

namespace App\Console\Commands;

use App\Models\Loan;
use App\Support\LoanPaymentAllocator;
use App\Support\LoanProvisioning;
use Illuminate\Console\Command;

/**
 * Daily re-evaluation of every Active loan's NPL/impairment classification —
 * a loan can cross an overdue-installment slab purely by the clock passing,
 * with no payment event to trigger LoanPaymentAllocator's inline recompute.
 * Only refreshes the cache columns (npl_flag/provision_required_*); actual
 * ledger posting stays a manual step via LoanProvisioning::post() from the
 * Loan Provisioning screen.
 */
class RecomputeLoanProvisioning extends Command
{
    protected $signature = 'loans:recompute-provisioning';

    protected $description = "Refresh every Active loan's NPL flag and required impairment provision";

    public function handle(): int
    {
        $count = 0;
        Loan::with('loanDetail.loanType')->whereIn('status', ['Active', 'Lapsed'])
            ->chunkById(100, function ($loans) use (&$count) {
                foreach ($loans as $loan) {
                    // Penalty accrues daily with no payment event, so the balance cache drifts without this.
                    $loan->update(['outstanding_balance_cache' => LoanPaymentAllocator::outstandingBalance($loan)]);
                    LoanProvisioning::refreshCache($loan);
                    $count++;
                }
            });

        $this->info("Recomputed provisioning for {$count} active loan(s).");

        return self::SUCCESS;
    }
}
