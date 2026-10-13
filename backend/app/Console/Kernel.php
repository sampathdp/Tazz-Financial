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
        // Matured pledged FDs are applied to their loans where the FD policy allows it.
        $schedule->command('fd-loans:auto-adjust')->dailyAt('01:30');
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
