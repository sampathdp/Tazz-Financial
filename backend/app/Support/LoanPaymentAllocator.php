<?php

namespace App\Support;

use App\Models\FacilityChargeAllocation;
use App\Models\FacilityPayment;
use App\Models\FacilityPaymentAllocation;
use App\Models\Loan;
use Carbon\Carbon;

/**
 * Applies a payment amount to a loan's schedule/charges. Shared by an
 * immediate Cash/Bank payment, an approved settlement, a realized cheque
 * payment, and a realized post-dated cheque — so all four price OD/penalty
 * and settle installments identically.
 */
class LoanPaymentAllocator
{
    /** Standard-practice loan servicing figures for this loan's default charge
     *  config (see LoanSummary::penaltyDueForRow) — shared by outstandingBalance()
     *  and applyAllocation() so both price "OD" identically. */
    public static function penaltyTerms(Loan $loan): array
    {
        $loanType = $loan->loanDetail?->loanType;

        return [
            (int) ($loanType?->grace_period_days ?? 5),
            (float) ($loanType?->default_charge_rate_percent ?? 0),
        ];
    }

    /** Everything this loan owes as of $asOf (defaults to today): unpaid
     *  principal+interest, unpaid penalty/OD (live estimate as of that date,
     *  same formula as the Contract Status tab), and unpaid Document/Legal/
     *  Valuation/Other charges — net of any waivers (write-offs; see
     *  FacilityWaiver and waivedByRow()), so a loan waived down to zero can
     *  still close on its own, the same way the Contract Status tab's Amount
     *  To Be Collected already nets waivers off (see LoanSummary::build). */
    public static function outstandingBalance(Loan $loan, ?Carbon $asOf = null): float
    {
        $schedules = $loan->schedules()->with('allocations.payment:id,status,approval_status')->get();
        [$graceDays, $chargeRate] = self::penaltyTerms($loan);
        $asOf ??= Carbon::today();

        $activeApplied = fn ($row, string $column) => (float) $row->allocations
            ->filter(fn ($a) => $a->payment && $a->payment->status !== 'Voided' && $a->payment->approval_status !== 'PendingApproval')
            ->sum($column);
        $penaltyDueForRow = fn ($row) => $chargeRate > 0 ? LoanSummary::penaltyDueForRow($row, $graceDays, $chargeRate, $asOf) : 0.0;

        // Queried fresh every time (not the cached $loan->charges/$loan->waivers
        // property) — this method runs more than once against the same $loan
        // instance within a single request (e.g. a pre-check in
        // LoanPaymentController::approveSettlement() followed by this same
        // method's own post-allocation call inside applyAllocation() below), and
        // a cached collection from the first call would silently ignore charge
        // payments the second call needs to see.
        $charges = $loan->charges()->get();
        $waived = self::waivedByRow($loan, $schedules, $charges, $activeApplied, $penaltyDueForRow);

        $scheduleBalance = $schedules->where('status', '!=', 'Paid')
            ->sum(fn ($s) => (float) $s->total_due - (float) $s->paid_amount - ($waived['interest'][$s->id] ?? 0) - ($waived['principal'][$s->id] ?? 0));

        $penaltyBalance = $chargeRate > 0
            ? $schedules->sum(function ($s) use ($graceDays, $chargeRate, $asOf, $waived) {
                $due = LoanSummary::penaltyDueForRow($s, $graceDays, $chargeRate, $asOf);
                $paid = LoanSummary::activePenaltyPaid($s);

                return max(0, round($due - $paid - ($waived['penalty'][$s->id] ?? 0), 2));
            })
            : 0.0;

        $chargesBalance = (float) $charges->sum(fn ($c) => max(0, (float) $c->due_amount - (float) $c->paid_amount - ($waived['charge'][$c->id] ?? 0)));

        return max(0, round($scheduleBalance + $penaltyBalance + $chargesBalance, 2));
    }

    /**
     * This loan's loan-level waivers (FacilityWaiver: Interest/Penalty/Charge/
     * Principal — not linked to a specific installment/charge, see its
     * migration) distributed across $schedules/$charges oldest-first, each
     * capped at what's actually still due there after real payments already
     * applied ($activeApplied) — the same floor and the same order a real
     * payment uses.
     *
     * Every method below that answers "what does this row/charge still owe"
     * (outstandingBalance, settlementQuote, outstandingByBucket) and every
     * method that actually collects money (applyAllocation,
     * applyManualAllocation) must net this same result, or a payment sized
     * against the waived-down total under-funds the row-by-row walk by
     * exactly the waived amount — the shortfall then silently lands on
     * whichever bucket is processed last for the last row (principal,
     * normally), while an earlier bucket collects its full un-waived due.
     * Never persisted (unlike applySettlementWaiver()'s accrual-interest
     * forgiveness) — always recomputed fresh from $loan->waivers(), which
     * makes it self-correcting as real payments land, and idempotent: capping
     * every row/charge at its own remaining due means calling this again
     * after a waiver's already been absorbed elsewhere just finds nothing
     * left to take, instead of double-counting it.
     *
     * @param callable $activeApplied (row, 'interest_applied'|'principal_applied'|'penalty_applied') => float already collected
     * @param callable $penaltyDueForRow (row) => float live penalty due as of the caller's $asOf (0 if the loan type has no default-charge rate)
     * @return array{interest: array<int,float>, principal: array<int,float>, penalty: array<int,float>, charge: array<int,float>}
     *   each keyed by facility_schedules.id (charge by facility_charges.id)
     */
    private static function waivedByRow(Loan $loan, $schedules, $charges, callable $activeApplied, callable $penaltyDueForRow): array
    {
        $byType = $loan->waivers()->get()->groupBy('waiver_type');
        $pool = [
            'interest' => (float) ($byType->get('Interest')?->sum('amount') ?? 0),
            'principal' => (float) ($byType->get('Principal')?->sum('amount') ?? 0),
            'penalty' => (float) ($byType->get('Penalty')?->sum('amount') ?? 0),
        ];
        $chargePool = (float) ($byType->get('Charge')?->sum('amount') ?? 0);

        $out = ['interest' => [], 'principal' => [], 'penalty' => [], 'charge' => []];

        foreach ($schedules as $row) {
            if ($pool['interest'] > 0) {
                $due = max(0, round((float) $row->interest_due - $activeApplied($row, 'interest_applied'), 2));
                if ($due > 0) {
                    $take = min($pool['interest'], $due);
                    $out['interest'][$row->id] = $take;
                    $pool['interest'] -= $take;
                }
            }
            if ($pool['penalty'] > 0) {
                $due = max(0, round($penaltyDueForRow($row) - $activeApplied($row, 'penalty_applied'), 2));
                if ($due > 0) {
                    $take = min($pool['penalty'], $due);
                    $out['penalty'][$row->id] = $take;
                    $pool['penalty'] -= $take;
                }
            }
            if ($pool['principal'] > 0) {
                $due = max(0, round((float) $row->principal_due - $activeApplied($row, 'principal_applied'), 2));
                if ($due > 0) {
                    $take = min($pool['principal'], $due);
                    $out['principal'][$row->id] = $take;
                    $pool['principal'] -= $take;
                }
            }
        }

        foreach ($charges as $charge) {
            if ($chargePool <= 0) {
                break;
            }
            $due = max(0, round((float) $charge->due_amount - (float) $charge->paid_amount, 2));
            if ($due <= 0) {
                continue;
            }
            $take = min($chargePool, $due);
            $out['charge'][$charge->id] = $take;
            $chargePool -= $take;
        }

        return $out;
    }

    /**
     * Same shape as outstandingBalance(), but for an early full settlement:
     * each unpaid installment's interest is capped at what's actually
     * accrued by $asOf (see InterestAccrualSchedule::accruedThrough()) —
     * the rest is waived rather than collected, matching the client's early-
     * settlement rule (accrued-to-date interest only, remaining future
     * interest forgiven). Read-only; see applySettlementWaiver() for the
     * write side, applied only once the settlement is actually approved.
     *
     * @return array{outstanding: float, waivedInterest: array<int, float>} waivedInterest is keyed by facility_schedules.id
     */
    public static function settlementQuote(Loan $loan, ?Carbon $asOf = null): array
    {
        $asOf ??= Carbon::today();
        $schedules = $loan->schedules()->orderBy('installment_no')
            ->with('allocations.payment:id,status,approval_status')->get();

        $activeApplied = fn ($row, string $column) => (float) $row->allocations
            ->filter(fn ($a) => $a->payment && $a->payment->status !== 'Voided' && $a->payment->approval_status !== 'PendingApproval')
            ->sum($column);

        // Pass 1: the settlement's own rule, independent of any loan-level
        // waiver — each unpaid row's interest is capped at what's actually
        // accrued by $asOf.
        $periodStart = $loan->disbursed_at ? Carbon::parse($loan->disbursed_at) : null;
        $accrualWaive = [];
        foreach ($schedules as $row) {
            $dueDate = $row->due_date instanceof Carbon ? $row->due_date : Carbon::parse($row->due_date);
            $start = $periodStart ?? $dueDate->copy();
            $periodStart = $dueDate;
            if ($row->status === 'Paid') {
                continue;
            }
            $interestDue = (float) $row->interest_due;
            $accrued = InterestAccrualSchedule::accruedThrough($start, $dueDate, $interestDue, $asOf);
            // Never waive interest that's already been collected on this row.
            $floor = max($accrued, $activeApplied($row, 'interest_applied'));
            $accrualWaive[$row->id] = max(0, round($interestDue - $floor, 2));
        }

        // Pass 2: any loan-level waiver (see waivedByRow()) forgives on top of
        // that for SIZING the settlement amount only — composed via an
        // "already accounted for" closure that folds pass 1's accrual waive
        // into the interest floor, so the waiver pool isn't asked to re-forgive
        // interest accrual already forgave. Deliberately NOT folded into
        // $waivedInterest below (what applySettlementWaiver() will persist):
        // the loan-level waiver is never consumed/deleted from facility_waivers,
        // so applyAllocation() nets the exact same live pool again moments
        // later when it actually collects the payment — persisting it here too
        // would forgive it twice. Baking in pass 1's accrual waive is safe
        // because that IS persisted here and nowhere else.
        [$graceDays, $chargeRate] = self::penaltyTerms($loan);
        $penaltyDueForRow = fn ($row) => $chargeRate > 0 ? LoanSummary::penaltyDueForRow($row, $graceDays, $chargeRate, $asOf) : 0.0;
        $accountedFor = fn ($row, string $column) => $column === 'interest_applied'
            ? $activeApplied($row, $column) + ($accrualWaive[$row->id] ?? 0)
            : $activeApplied($row, $column);
        $charges = $loan->charges()->get();
        $waived = self::waivedByRow($loan, $schedules->where('status', '!=', 'Paid'), $charges, $accountedFor, $penaltyDueForRow);

        $waivedInterest = [];
        $scheduleBalance = 0.0;
        foreach ($schedules as $row) {
            if ($row->status === 'Paid') {
                continue;
            }
            $persistWaive = round($accrualWaive[$row->id] ?? 0, 2);
            if ($persistWaive > 0) {
                $waivedInterest[$row->id] = $persistWaive;
            }
            $sizingWaive = round($persistWaive + ($waived['interest'][$row->id] ?? 0), 2);
            $scheduleBalance += (float) $row->total_due - $sizingWaive - ($waived['principal'][$row->id] ?? 0) - (float) $row->paid_amount;
        }

        $penaltyBalance = $chargeRate > 0
            ? $schedules->sum(function ($s) use ($graceDays, $chargeRate, $asOf, $waived) {
                $due = LoanSummary::penaltyDueForRow($s, $graceDays, $chargeRate, $asOf);
                $paid = LoanSummary::activePenaltyPaid($s);

                return max(0, round($due - $paid - ($waived['penalty'][$s->id] ?? 0), 2));
            })
            : 0.0;

        $chargesBalance = (float) $charges->sum(fn ($c) => max(0, (float) $c->due_amount - (float) $c->paid_amount - ($waived['charge'][$c->id] ?? 0)));

        $outstanding = max(0, round($scheduleBalance + $penaltyBalance + $chargesBalance, 2));

        return ['outstanding' => $outstanding, 'waivedInterest' => $waivedInterest];
    }

    /**
     * Writes the waiver settlementQuote() only quoted — reduces each affected
     * row's interest_due/total_due by its waived amount and records it in
     * waived_interest (audit trail + what LoanPaymentController::destroy()
     * adds back if an approved settlement is later voided). Called once,
     * inside approveSettlement()'s transaction, immediately before
     * applyAllocation() — never against a still-pending request, so a
     * rejected settlement never needed to touch the schedule at all.
     */
    public static function applySettlementWaiver(Loan $loan, array $waivedInterest): void
    {
        if (empty($waivedInterest)) {
            return;
        }
        $rows = $loan->schedules()->whereIn('id', array_keys($waivedInterest))->get();
        foreach ($rows as $row) {
            $waive = $waivedInterest[$row->id];
            $row->update([
                'interest_due' => round((float) $row->interest_due - $waive, 2),
                'total_due' => round((float) $row->total_due - $waive, 2),
                'waived_interest' => round($waive, 2),
            ]);
        }
    }

    /**
     * Applies `$amount` in standard loan-servicing order: Interest, then OD
     * (penalty), then other charges (Document/Legal/Valuation/Other), then
     * Principal — each bucket oldest-first across the loan's unpaid
     * installments/charges — then closes the loan once nothing is owed.
     *
     * $asOf is the date the amount is considered to have landed in the loan
     * (e.g. a cheque's realization/check-in date, not the date it was
     * recorded or the date staff happened to click "Realize") — it drives
     * both the OD/penalty calculation and the settled installment's paid_at,
     * defaulting to today for payment types that don't defer settlement.
     */
    public static function applyAllocation(Loan $loan, FacilityPayment $payment, float $amount, ?Carbon $asOf = null): void
    {
        $asOf ??= Carbon::today();
        $schedules = $loan->schedules()->where('status', '!=', 'Paid')->orderBy('installment_no')
            ->with('allocations.payment:id,status,approval_status')->get();
        [$graceDays, $chargeRate] = self::penaltyTerms($loan);

        // A void doesn't delete allocation rows (kept for audit trail), so every
        // "how much of this row is already paid" lookup below must exclude a
        // voided (or still-pending-approval) payment's rows explicitly — the
        // authoritative-and-already-reversed paid_amount only covers principal+
        // interest combined, not the per-bucket split these passes need.
        $activeApplied = fn ($row, string $column) => (float) $row->allocations
            ->filter(fn ($a) => $a->payment && $a->payment->status !== 'Voided' && $a->payment->approval_status !== 'PendingApproval')
            ->sum($column);

        // Accumulate this payment's contribution per schedule row across all
        // three buckets that touch schedules, so exactly one allocation row is
        // written per installment (mirrors the pre-existing convention).
        $applied = [];
        $touch = function (int $scheduleId, string $bucket, float $amt) use (&$applied) {
            if ($amt <= 0) {
                return;
            }
            $applied[$scheduleId] ??= ['interest' => 0.0, 'penalty' => 0.0, 'principal' => 0.0];
            $applied[$scheduleId][$bucket] += $amt;
        };

        $remaining = $amount;
        $charges = $loan->charges()->orderBy('charged_at')->get();

        // Any loan-level waiver (see waivedByRow()) must be netted off the same
        // "due" this loop collects against — outstandingBalance()/
        // settlementQuote() size the incoming payment against the waived-down
        // total, so collecting the raw un-waived due here would run this
        // payment short by exactly the waived amount, and the shortfall would
        // silently land on whichever bucket/row is processed last.
        $penaltyDueForRow = fn ($row) => $chargeRate > 0 ? LoanSummary::penaltyDueForRow($row, $graceDays, $chargeRate, $asOf) : 0.0;
        $waived = self::waivedByRow($loan, $schedules, $charges, $activeApplied, $penaltyDueForRow);

        $payCharges = function () use (&$remaining, $charges, $payment, $waived) {
            if ($remaining <= 0) {
                return;
            }
            foreach ($charges as $charge) {
                if ($remaining <= 0) {
                    break;
                }
                $due = max(0, round((float) $charge->due_amount - (float) $charge->paid_amount - ($waived['charge'][$charge->id] ?? 0), 2));
                if ($due <= 0) {
                    continue;
                }
                $pay = min($remaining, $due);
                $charge->update(['paid_amount' => round((float) $charge->paid_amount + $pay, 2)]);
                FacilityChargeAllocation::create(['facility_payment_id' => $payment->id, 'facility_charge_id' => $charge->id, 'amount_applied' => $pay]);
                $remaining = round($remaining - $pay, 2);
            }
        };

        // Apply to oldest installment first, fully settling each before moving to the next.
        foreach ($schedules as $row) {
            if ($remaining <= 0) {
                break;
            }

            // 1) Interest
            $due = max(0, round((float) $row->interest_due - $activeApplied($row, 'interest_applied') - ($waived['interest'][$row->id] ?? 0), 2));
            if ($due > 0) {
                $pay = min($remaining, $due);
                $touch($row->id, 'interest', $pay);
                $remaining = round($remaining - $pay, 2);
            }

            // 2) OD / penalty
            if ($remaining > 0 && $chargeRate > 0) {
                $penaltyDue = LoanSummary::penaltyDueForRow($row, $graceDays, $chargeRate, $asOf);
                $due = max(0, round($penaltyDue - $activeApplied($row, 'penalty_applied') - ($waived['penalty'][$row->id] ?? 0), 2));
                if ($due > 0) {
                    $pay = min($remaining, $due);
                    $touch($row->id, 'penalty', $pay);
                    $remaining = round($remaining - $pay, 2);
                }
            }

            // 3) Other charges (Document/Legal/Valuation/Other)
            $payCharges();

            // 4) Principal
            if ($remaining > 0) {
                $due = max(0, round((float) $row->principal_due - $activeApplied($row, 'principal_applied') - ($waived['principal'][$row->id] ?? 0), 2));
                if ($due > 0) {
                    $pay = min($remaining, $due);
                    $touch($row->id, 'principal', $pay);
                    $remaining = round($remaining - $pay, 2);
                }
            }
        }

        // Pay any remaining charges if there were no schedules or schedules were fully paid
        $payCharges();

        self::finalizeApplied($loan, $schedules, $applied, $payment, $asOf);
    }

    /**
     * Applies `$amount` split across explicit buckets the caller (or the
     * cashier, via a payment_manual_allocation approval request) chose,
     * instead of the standard waterfall priority — e.g. only Interest and
     * Capital, skipping OD and other charges entirely, even though OD is due.
     * Each bucket has its own independent remaining amount and is applied
     * oldest-installment/oldest-charge-first, same as applyAllocation()'s
     * per-bucket passes, just not gated behind the buckets before it.
     *
     * $allocations keys: interest, od_charge, capital, other_charges — any
     * missing key is treated as 0.
     */
    public static function applyManualAllocation(Loan $loan, FacilityPayment $payment, array $allocations, ?Carbon $asOf = null): void
    {
        $asOf ??= Carbon::today();
        $schedules = $loan->schedules()->where('status', '!=', 'Paid')->orderBy('installment_no')
            ->with('allocations.payment:id,status,approval_status')->get();
        [$graceDays, $chargeRate] = self::penaltyTerms($loan);

        $activeApplied = fn ($row, string $column) => (float) $row->allocations
            ->filter(fn ($a) => $a->payment && $a->payment->status !== 'Voided' && $a->payment->approval_status !== 'PendingApproval')
            ->sum($column);

        $charges = $loan->charges()->orderBy('charged_at')->get();
        // See waivedByRow()'s docblock (applyAllocation() above) — the cashier's
        // chosen bucket amounts were validated against outstandingByBucket(),
        // which nets the same waivers, so this loop must too or it'll try to
        // collect more than what's actually left owing in a bucket.
        $penaltyDueForRow = fn ($row) => $chargeRate > 0 ? LoanSummary::penaltyDueForRow($row, $graceDays, $chargeRate, $asOf) : 0.0;
        $waived = self::waivedByRow($loan, $schedules, $charges, $activeApplied, $penaltyDueForRow);

        $applied = [];
        $touch = function (int $scheduleId, string $bucket, float $amt) use (&$applied) {
            if ($amt <= 0) {
                return;
            }
            $applied[$scheduleId] ??= ['interest' => 0.0, 'penalty' => 0.0, 'principal' => 0.0];
            $applied[$scheduleId][$bucket] += $amt;
        };

        $interestRemaining = round((float) ($allocations['interest'] ?? 0), 2);
        $penaltyRemaining = round((float) ($allocations['od_charge'] ?? 0), 2);
        $principalRemaining = round((float) ($allocations['capital'] ?? 0), 2);
        $chargeRemaining = round((float) ($allocations['other_charges'] ?? 0), 2);

        foreach ($schedules as $row) {
            if ($interestRemaining > 0) {
                $due = max(0, round((float) $row->interest_due - $activeApplied($row, 'interest_applied') - ($waived['interest'][$row->id] ?? 0), 2));
                if ($due > 0) {
                    $pay = min($interestRemaining, $due);
                    $touch($row->id, 'interest', $pay);
                    $interestRemaining = round($interestRemaining - $pay, 2);
                }
            }

            if ($penaltyRemaining > 0 && $chargeRate > 0) {
                $penaltyDue = LoanSummary::penaltyDueForRow($row, $graceDays, $chargeRate, $asOf);
                $due = max(0, round($penaltyDue - $activeApplied($row, 'penalty_applied') - ($waived['penalty'][$row->id] ?? 0), 2));
                if ($due > 0) {
                    $pay = min($penaltyRemaining, $due);
                    $touch($row->id, 'penalty', $pay);
                    $penaltyRemaining = round($penaltyRemaining - $pay, 2);
                }
            }

            if ($principalRemaining > 0) {
                $due = max(0, round((float) $row->principal_due - $activeApplied($row, 'principal_applied') - ($waived['principal'][$row->id] ?? 0), 2));
                if ($due > 0) {
                    $pay = min($principalRemaining, $due);
                    $touch($row->id, 'principal', $pay);
                    $principalRemaining = round($principalRemaining - $pay, 2);
                }
            }
        }

        if ($chargeRemaining > 0) {
            foreach ($charges as $charge) {
                if ($chargeRemaining <= 0) {
                    break;
                }
                $due = max(0, round((float) $charge->due_amount - (float) $charge->paid_amount - ($waived['charge'][$charge->id] ?? 0), 2));
                if ($due <= 0) {
                    continue;
                }
                $pay = min($chargeRemaining, $due);
                $charge->update(['paid_amount' => round((float) $charge->paid_amount + $pay, 2)]);
                FacilityChargeAllocation::create(['facility_payment_id' => $payment->id, 'facility_charge_id' => $charge->id, 'amount_applied' => $pay]);
                $chargeRemaining = round($chargeRemaining - $pay, 2);
            }
        }

        self::finalizeApplied($loan, $schedules, $applied, $payment, $asOf);
    }

    /** Writes one FacilityPaymentAllocation row per touched installment, updates
     *  each row's paid_amount/status, and refreshes the loan's outstanding-balance
     *  cache/closed status — shared tail for applyAllocation() and
     *  applyManualAllocation(), which only differ in how $applied gets built. */
    private static function finalizeApplied(Loan $loan, $schedules, array $applied, FacilityPayment $payment, Carbon $asOf): void
    {
        foreach ($applied as $scheduleId => $buckets) {
            FacilityPaymentAllocation::create([
                'facility_payment_id' => $payment->id,
                'facility_schedule_id' => $scheduleId,
                'principal_applied' => round($buckets['principal'], 2),
                'interest_applied' => round($buckets['interest'], 2),
                'penalty_applied' => round($buckets['penalty'], 2),
            ]);

            $row = $schedules->firstWhere('id', $scheduleId);
            // Only principal+interest count toward an installment's own total_due/
            // status — penalty is priced and collected separately (see above) and
            // never was, and isn't now, part of that figure (see LoanAmortizer).
            $newPaid = round((float) $row->paid_amount + $buckets['interest'] + $buckets['principal'], 2);
            $row->update([
                'paid_amount' => $newPaid,
                'status' => $newPaid >= (float) $row->total_due ? 'Paid' : 'Partial',
                'paid_at' => $newPaid >= (float) $row->total_due ? $asOf : $row->paid_at,
            ]);
        }

        // Keeps credit_facilities.outstanding_balance_cache in step with this
        // method's own result — every caller that allocates a payment gets a
        // fresh cache for free, rather than each one refreshing it separately.
        $outstanding = self::outstandingBalance($loan);
        $loan->update(['outstanding_balance_cache' => $outstanding]);

        // Only an already-active loan closes on full payoff — an Approved loan
        // paying off a pre-disbursement charge (see LoanController::disburse())
        // has no schedule yet and must stay Approved, ready to disburse.
        if ($loan->status === 'Active' && $outstanding <= 0) {
            $loan->update(['status' => 'Closed', 'closed_at' => $asOf]);
        }

        // A payment can move a loan across an NPL/provisioning slab (or clear
        // it entirely) the moment it lands, not just at tomorrow's daily
        // recompute — see LoanProvisioning. Re-fetches status fresh off
        // $loan since the update() calls above already mutated this instance.
        LoanProvisioning::refreshCache($loan);
    }

    /**
     * Same totals as outstandingBalance(), kept split by bucket instead of
     * summed — lets a manual-allocation request (and its approval-time
     * re-check) validate each chosen bucket amount against what's actually
     * still due for that bucket specifically, e.g. rejecting an Interest
     * amount that exceeds unpaid interest even if the loan's total balance
     * would easily cover it.
     *
     * @return array{interest: float, od_charge: float, other_charges: float, capital: float}
     */
    public static function outstandingByBucket(Loan $loan, ?Carbon $asOf = null): array
    {
        $asOf ??= Carbon::today();
        $schedules = $loan->schedules()->where('status', '!=', 'Paid')
            ->with('allocations.payment:id,status,approval_status')->get();
        [$graceDays, $chargeRate] = self::penaltyTerms($loan);

        $activeApplied = fn ($row, string $column) => (float) $row->allocations
            ->filter(fn ($a) => $a->payment && $a->payment->status !== 'Voided' && $a->payment->approval_status !== 'PendingApproval')
            ->sum($column);
        $penaltyDueForRow = fn ($row) => $chargeRate > 0 ? LoanSummary::penaltyDueForRow($row, $graceDays, $chargeRate, $asOf) : 0.0;
        $charges = $loan->charges()->get();
        // Netted the same way applyManualAllocation() will actually collect
        // (see waivedByRow()) — otherwise a cashier could be shown a bucket cap
        // that includes an amount the loan has already forgiven.
        $waived = self::waivedByRow($loan, $schedules, $charges, $activeApplied, $penaltyDueForRow);

        $interest = $schedules->sum(fn ($row) => max(0, round((float) $row->interest_due - $activeApplied($row, 'interest_applied') - ($waived['interest'][$row->id] ?? 0), 2)));
        $capital = $schedules->sum(fn ($row) => max(0, round((float) $row->principal_due - $activeApplied($row, 'principal_applied') - ($waived['principal'][$row->id] ?? 0), 2)));
        $odCharge = $chargeRate > 0
            ? $schedules->sum(function ($row) use ($graceDays, $chargeRate, $asOf, $waived) {
                $due = LoanSummary::penaltyDueForRow($row, $graceDays, $chargeRate, $asOf);
                $paid = LoanSummary::activePenaltyPaid($row);

                return max(0, round($due - $paid - ($waived['penalty'][$row->id] ?? 0), 2));
            })
            : 0.0;
        $otherCharges = (float) $charges->sum(fn ($c) => max(0, (float) $c->due_amount - (float) $c->paid_amount - ($waived['charge'][$c->id] ?? 0)));

        return [
            'interest' => round($interest, 2),
            'od_charge' => round($odCharge, 2),
            'other_charges' => round($otherCharges, 2),
            'capital' => round($capital, 2),
        ];
    }

    /**
     * Undoes what applyAllocation()/applyManualAllocation() did for a payment — the installment and
     * charge paid amounts, a settlement's interest waiver, and a Closed loan's status — then refreshes
     * the loan's outstanding cache. Shared by a cancelled receipt (LoanPaymentController::approveVoidRequest)
     * and a realized cheque that later bounces, so both put the schedule back identically. The caller
     * marks the payment Voided and reverses its ledger entries (LoanLedgerPoster::reverseAllFor).
     */
    public static function reverseAllocation(FacilityPayment $payment): void
    {
        $payment->loadMissing(['allocations.schedule', 'chargeAllocations.charge']);

        foreach ($payment->allocations as $alloc) {
            $row = $alloc->schedule;
            // Undo the accrued-to-date interest waiver this settlement applied
            // (see applySettlementWaiver()) before recomputing status below —
            // voiding the settlement means the loan carries on, so the full
            // future interest is owed again.
            if ($payment->payment_type === 'Settlement' && (float) $row->waived_interest > 0) {
                $restore = (float) $row->waived_interest;
                $row->interest_due = round((float) $row->interest_due + $restore, 2);
                $row->total_due = round((float) $row->total_due + $restore, 2);
                $row->waived_interest = 0;
            }
            // paid_amount tracks principal+interest only (an installment's total_due
            // never includes penalty — see LoanAmortizer), so only reverse those two;
            // penalty_applied is netted off separately via LoanSummary's live estimate.
            $newPaid = max(0, round((float) $row->paid_amount - $alloc->principal_applied - $alloc->interest_applied, 2));
            $row->update([
                'paid_amount' => $newPaid,
                'status' => $newPaid <= 0 ? 'Pending' : ($newPaid >= (float) $row->total_due ? 'Paid' : 'Partial'),
                'paid_at' => $newPaid >= (float) $row->total_due ? $row->paid_at : null,
            ]);
        }
        foreach ($payment->chargeAllocations as $chargeAlloc) {
            $charge = $chargeAlloc->charge;
            $charge->update(['paid_amount' => max(0, round((float) $charge->paid_amount - $chargeAlloc->amount_applied, 2))]);
        }

        $loan = Loan::findOrFail($payment->credit_facility_id);
        if ($loan->status === 'Closed') {
            $loan->update(['status' => 'Active', 'closed_at' => null]);
        }
        $loan->update(['outstanding_balance_cache' => self::outstandingBalance($loan->fresh())]);
    }
}
