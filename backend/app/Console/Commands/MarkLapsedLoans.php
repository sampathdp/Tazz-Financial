<?php

namespace App\Console\Commands;

use App\Models\Loan;
use App\Support\LoanProvisioning;
use App\Support\LoanSummary;
use Illuminate\Console\Command;

/**
 * Daily: an Active loan whose last installment due date has passed and that
 * still owes money becomes Lapsed. Fully paid loans that merely haven't been
 * closed yet are left alone (manual Close handles those).
 */
class MarkLapsedLoans extends Command
{
    protected $signature = 'loans:mark-lapsed';

    protected $description = 'Mark Active loans past their final installment due date (with a balance) as Lapsed';

    public function handle(): int
    {
        $today = now()->toDateString();
        $count = 0;

        Loan::where('status', 'Active')
            ->whereNotNull('disbursed_at')
            ->whereHas('schedules', fn ($q) => $q->where('status', '!=', 'Paid'))
            ->whereDoesntHave('schedules', fn ($q) => $q->where('due_date', '>=', $today))
            ->chunkById(100, function ($loans) use (&$count) {
                foreach ($loans as $loan) {
                    $loan->load(['schedules.allocations.payment:id,status,approval_status', 'charges', 'waivers']);
                    $balance = collect(LoanSummary::build($loan)['dueBreakdown'])->sum('balance');
                    if (round($balance, 2) <= 0.01) {
                        continue;
                    }
                    $loan->update(['status' => 'Lapsed']);
                    LoanProvisioning::refreshCache($loan);
                    $count++;
                }
            });

        $this->info("Marked {$count} loan(s) as Lapsed.");

        return self::SUCCESS;
    }
}
