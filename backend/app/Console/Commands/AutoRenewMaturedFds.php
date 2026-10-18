<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\FixedDepositController;
use App\Models\FixedDeposit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Daily: renews every matured, unpledged FD whose customer left an auto-renew instruction.
 * principal_only pays an interest-at-maturity FD's interest out in cash; principal_and_interest
 * capitalises it into the new principal. Monthly-method FDs have nothing to settle (interest was
 * paid as it fell due). Each FD is its own transaction, so one failure never blocks the rest.
 */
class AutoRenewMaturedFds extends Command
{
    protected $signature = 'fd:auto-renew';

    protected $description = 'Renew matured Fixed Deposits that have an auto-renew instruction';

    public function handle(FixedDepositController $controller): int
    {
        $fds = FixedDeposit::withoutGlobalScopes()
            ->where('status', 'Active')
            ->where('auto_renew', true)
            ->whereDate('maturity_date', '<=', now())
            ->where(fn ($q) => $q->whereNull('lien_amount')->orWhere('lien_amount', '<=', 0))
            ->get();

        $done = 0;
        foreach ($fds as $fd) {
            try {
                $treatment = 'none';
                if ($fd->interest_payment_method === 'maturity') {
                    $treatment = $fd->auto_renew_mode === 'principal_and_interest' ? 'capitalise' : 'payout';
                }
                DB::transaction(fn () => $controller->performRenewal(
                    $fd,
                    (int) ($fd->auto_renew_term_months ?: $fd->term_months),
                    null,
                    'Auto-renewed on maturity',
                    $treatment,
                    (int) ($fd->created_by ?? 1),
                    true,
                    [
                        'auto_renew' => true,
                        'auto_renew_mode' => $fd->auto_renew_mode,
                        'auto_renew_term_months' => $fd->auto_renew_term_months,
                    ],
                ));
                $done++;
            } catch (\Throwable $e) {
                $this->error("FD {$fd->fd_number}: " . ($e instanceof \Illuminate\Validation\ValidationException ? collect($e->errors())->flatten()->first() : $e->getMessage()));
            }
        }
        $this->info("Auto-renewed {$done} FD(s).");

        return self::SUCCESS;
    }
}
