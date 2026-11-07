<?php

namespace App\Support;

use App\Models\Loan;
use App\Models\LoanProvision;
use Illuminate\Support\Facades\DB;

/**
 * NPL classification and impairment-provision slabs for a Loan — the CBSL-
 * style pattern: >=3 installments overdue is NPL, >=6 needs 50% provisioned,
 * >=12 needs 100%. Reuses LoanSummary::build()'s overdueInstallments (already
 * fractional/decimal-precise, so 5.99 vs 6.10 resolves correctly without any
 * new precision logic) and rentalArrears (overdue principal+interest, net of
 * waivers) as the provisioning base, so this can never disagree with what the
 * Loan Detail contract-status card already shows for the same loan.
 *
 * Classification (evaluate()/refreshCache()) is always live — refreshed
 * daily by the loans:recompute-provisioning command and inline after every
 * payment (LoanPaymentAllocator::finalizeApplied()). The actual ledger
 * entry only moves when a user calls post() — required vs. posted can
 * legitimately disagree between recomputes, same as a Settlement sitting
 * PendingApproval before it's actually booked.
 */
class LoanProvisioning
{
    // schedules.allocations.payment is required by LoanSummary::activePenaltyPaid()
    // / collectedInterest(); without it those methods lazy-load `payment` per
    // allocation — a silent N+1 in production and a hard LazyLoadingViolation
    // in every non-production environment.
    private const RELATIONS = ['schedules.allocations.payment:id,status,approval_status', 'charges', 'payments', 'waivers', 'loanDetail.loanType:id,name,code,default_charge_rate_percent,grace_period_days'];

    /** Pure calc, no DB writes. $loan is reloaded with exactly what LoanSummary::build() needs, so this never uses a caller's possibly-stale eager-loaded relations. */
    public static function evaluate(Loan $loan): array
    {
        $loan->load(self::RELATIONS);
        $summary = LoanSummary::build($loan);
        $overdueInstallments = (float) $summary['arrears']['overdueInstallments'];
        $baseAmount = (float) $summary['arrears']['rentalArrears'];

        $requiredPct = $overdueInstallments >= 12 ? 100 : ($overdueInstallments >= 6 ? 50 : 0);
        $nplFlag = $overdueInstallments >= 3;
        $requiredAmount = round($baseAmount * $requiredPct / 100, 2);

        return [
            'nplFlag' => $nplFlag,
            'requiredPct' => $requiredPct,
            'requiredAmount' => $requiredAmount,
            'baseAmount' => $baseAmount,
            'overdueInstallments' => $overdueInstallments,
            'daysOverdue' => (int) $summary['arrears']['daysOverdue'],
        ];
    }

    /** Refreshes the cache columns only — no journal entry. Called by the daily command and by the payment-allocator hook. A no-longer-Active loan (closed/written off) is zeroed rather than evaluated, since it no longer has a meaningful "overdue" concept. */
    public static function refreshCache(Loan $loan): void
    {
        if (!in_array($loan->status, ['Active', 'Lapsed'], true)) {
            $loan->update([
                'npl_flag' => false,
                'provision_required_pct' => 0,
                'provision_required_amount' => 0,
                'provision_last_evaluated_at' => now(),
            ]);

            return;
        }

        $data = self::evaluate($loan);
        $loan->update([
            'npl_flag' => $data['nplFlag'],
            'provision_required_pct' => $data['requiredPct'],
            'provision_required_amount' => $data['requiredAmount'],
            'provision_last_evaluated_at' => now(),
        ]);
    }

    /** Books the delta between what's required and what's already posted — Dr 5300/Cr 1190 to build up, Dr 1190/Cr 4050 (Bad Debt Recovery Income) when a payment has dropped the requirement. Returns null if there's nothing to book (already in sync). */
    public static function post(Loan $loan, ?int $userId = null): ?LoanProvision
    {
        return DB::transaction(function () use ($loan, $userId) {
            $data = self::evaluate($loan);
            $delta = round($data['requiredAmount'] - (float) $loan->provision_posted_amount, 2);

            $loan->update([
                'npl_flag' => $data['nplFlag'],
                'provision_required_pct' => $data['requiredPct'],
                'provision_required_amount' => $data['requiredAmount'],
                'provision_last_evaluated_at' => now(),
            ]);

            if (abs($delta) <= 0.01) {
                return null;
            }

            LoanLedgerPoster::postProvisionAdjustment($loan, $delta, $userId);

            $loan->update(['provision_posted_amount' => $data['requiredAmount']]);

            return LoanProvision::create([
                'credit_facility_id' => $loan->id,
                'action' => $delta > 0 ? 'Post' : 'Recover',
                'overdue_installments_at_action' => $data['overdueInstallments'],
                'slab_pct' => $data['requiredPct'],
                'base_amount' => $data['baseAmount'],
                'amount' => abs($delta),
                'balance_after' => $data['requiredAmount'],
                'posted_by' => $userId,
            ]);
        });
    }
}
