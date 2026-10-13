<?php

namespace App\Console\Commands;

use App\Models\FacilityCollateralFdDetail;
use App\Models\FdLoanPolicy;
use App\Support\FdLienService;
use Illuminate\Console\Command;

/**
 * Daily: for loans against an FD whose policy has auto_adjust_on_maturity, once the
 * pledged FD matures its proceeds are applied to the loan (FdLienService::settle).
 * Each loan is its own transaction, so one failure never blocks the rest.
 */
class AdjustMaturedFdLoans extends Command
{
    protected $signature = 'fd-loans:auto-adjust';

    protected $description = 'Apply matured pledged FDs to their loans where the FD policy allows auto-adjustment';

    public function handle(): int
    {
        $liens = FacilityCollateralFdDetail::where('lien_status', 'Active')
            ->whereIn('fd_loan_policy_id', FdLoanPolicy::where('auto_adjust_on_maturity', true)->pluck('id'))
            ->whereHas('fixedDeposit', fn ($q) => $q->where('status', 'Active')->whereDate('maturity_date', '<=', now()))
            ->with('facility')
            ->get();

        $done = 0;
        foreach ($liens as $lien) {
            if (!$lien->facility || $lien->facility->status !== 'Active') {
                continue;
            }
            try {
                FdLienService::settle($lien->facility, (int) ($lien->marked_by ?? 1), ['notes' => 'Auto-adjusted on FD maturity']);
                $done++;
            } catch (\Throwable $e) {
                $this->error("Loan {$lien->facility->code}: " . ($e instanceof \Illuminate\Validation\ValidationException ? collect($e->errors())->flatten()->first() : $e->getMessage()));
            }
        }
        $this->info("Adjusted {$done} loan(s).");

        return self::SUCCESS;
    }
}
