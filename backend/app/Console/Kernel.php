<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        // $schedule->command('inspire')->hourly();
        $schedule->command('loans:recompute-provisioning')->dailyAt('01:00');
        // Re-assert cash/bank cache == GL every morning; logs + a
        // finance_audit_event on any drift (see CheckLedgerDrift).
        $schedule->command('finance:check-drift')->dailyAt('02:00');
        // Re-assert every borrowing and the borrowing liability accounts == GL (see CheckBorrowingLedger).
        $schedule->command('borrowings:check-ledger')->dailyAt('02:10');
        // Matured pledged FDs are applied to their loans where the FD policy allows it.
        $schedule->command('fd-loans:auto-adjust')->dailyAt('01:30');
        // Matured FDs with a customer auto-renew instruction roll over into a new term.
        $schedule->command('fd:auto-renew')->dailyAt('01:15');
        // Month-end interest on branch-to-branch funding agreements (monthly accrual mode).
        $schedule->command('funding:accrue')->lastDayOfMonth('23:30');
        // Matured company borrowings set to renew roll over into a new term.
        $schedule->command('borrowings:auto-renew')->dailyAt('01:20');
        // Interest payable on company borrowings (Dr 5110 / Cr 2220), booked daily so the expense shows
        // as it is earned, not only at month-end. Re-runs are safe (each borrowing remembers its accrued-to date).
        $schedule->command('borrowings:accrue-interest')->dailyAt('00:30');
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
