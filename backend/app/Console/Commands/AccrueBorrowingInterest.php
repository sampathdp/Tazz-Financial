<?php

namespace App\Console\Commands;

use App\Models\Borrowing;
use App\Support\BorrowingInterestAccrual;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Month-end interest on company borrowings: Dr 5110 / Cr 2220 for every active,
 * interest-bearing, non-overdraft borrowing, up to --through (default today).
 * Safe to re-run — each borrowing remembers the date it has been accrued to.
 */
class AccrueBorrowingInterest extends Command
{
    protected $signature = 'borrowings:accrue-interest {--through= : accrue up to this date (default today)}';

    protected $description = 'Book accrued interest payable on active company borrowings';

    public function handle(): int
    {
        $through = $this->option('through') ?: now()->toDateString();
        $count = 0;
        $total = '0.00';
        $ids = Borrowing::withoutGlobalScopes()->where('status', 'Active')->where('type', '!=', 'Overdraft')
            ->whereNotNull('interest_accrued_through')->where('interest_rate_percent', '>', 0)->pluck('id');
        foreach ($ids as $id) {
            DB::transaction(function () use ($id, $through, &$count, &$total) {
                $b = Borrowing::withoutGlobalScopes()->lockForUpdate()->find($id);
                $amount = $b ? BorrowingInterestAccrual::accrue($b, $through) : '0.00';
                if (bccomp($amount, '0', 2) > 0) {
                    $count++;
                    $total = bcadd($total, $amount, 2);
                }
            });
        }
        $this->info("Accrued {$total} interest on {$count} borrowing(s) through {$through}.");

        return self::SUCCESS;
    }
}
