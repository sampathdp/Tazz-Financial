<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\BorrowingController;
use App\Models\Borrowing;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Daily: rolls over every matured, active borrowing marked on_maturity='Renew'
 * for another term at the same rate. Interest is booked up to the old maturity
 * date first, and none is paid in cash — what accrued simply stays payable in
 * 2220 until someone records the interest payment. Borrowings marked 'Settle'
 * (or with no instruction) are left alone: they show as Matured on the screen
 * for a person to settle, because repaying needs a cash decision. Each
 * borrowing is its own transaction so one failure never blocks the rest.
 */
class AutoRenewMaturedBorrowings extends Command
{
    protected $signature = 'borrowings:auto-renew {--today= : treat this date as today}';

    protected $description = 'Renew matured company borrowings that are set to renew on maturity';

    public function handle(BorrowingController $controller): int
    {
        $today = Carbon::parse($this->option('today') ?: now())->startOfDay();
        $borrowings = Borrowing::withoutGlobalScopes()
            ->where('status', 'Active')->where('type', '!=', 'Overdraft')->where('on_maturity', 'Renew')
            ->whereNotNull('maturity_date')->whereDate('maturity_date', '<=', $today)
            ->get();

        $done = 0;
        foreach ($borrowings as $b) {
            try {
                DB::transaction(function () use ($b, $controller, $today, &$done) {
                    $term = (int) ($b->term_months ?: max(1, (int) round(Carbon::parse($b->borrowing_date)->floatDiffInMonths(Carbon::parse($b->maturity_date)))));
                    // Catch up one term at a time, so a long outage still leaves one renewal row per term.
                    for ($i = 0; $i < 120 && Carbon::parse($b->maturity_date)->lte($today); $i++) {
                        $old = Carbon::parse($b->maturity_date);
                        $controller->performRenewal($b, [
                            'renewed_on' => $old->toDateString(),
                            'new_maturity_date' => $old->copy()->addMonthsNoOverflow($term)->toDateString(),
                            'new_term_months' => $term,
                            'notes' => 'Auto-renewed on maturity',
                        ], (int) ($b->created_by ?? 1));
                        $b->refresh();
                        $done++;
                    }
                });
            } catch (\Throwable $e) {
                $this->error("Borrowing {$b->borrowing_number}: " . ($e instanceof \Illuminate\Validation\ValidationException ? collect($e->errors())->flatten()->first() : $e->getMessage()));
            }
        }
        $this->info("Auto-renewed {$done} term(s) across " . $borrowings->count() . ' borrowing(s).');

        return self::SUCCESS;
    }
}
