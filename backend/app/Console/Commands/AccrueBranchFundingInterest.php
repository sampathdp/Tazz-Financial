<?php

namespace App\Console\Commands;

use App\Models\BranchFundingAgreement;
use App\Services\BranchFundingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Month-end interest on branch funding agreements set to "monthly" accrual. Agreements set to "on_repayment"
 * are left alone: their interest is booked when a repayment is made.
 */
class AccrueBranchFundingInterest extends Command
{
    protected $signature = 'funding:accrue {--through= : accrue up to this date (default today)}';

    protected $description = 'Book interest on active monthly-accrual branch funding agreements';

    public function handle(): int
    {
        $through = $this->option('through') ?: now()->toDateString();
        $count = 0;
        foreach (BranchFundingAgreement::where('status', 'Active')->where('accrual_mode', 'monthly')->pluck('id') as $id) {
            DB::transaction(function () use ($id, $through, &$count) {
                $a = BranchFundingAgreement::lockForUpdate()->find($id);
                if ($a && BranchFundingService::accrue($a, $through, null)) {
                    $count++;
                }
            });
        }
        $this->info("Accrued interest on {$count} branch funding agreement(s) through {$through}.");

        return self::SUCCESS;
    }
}
