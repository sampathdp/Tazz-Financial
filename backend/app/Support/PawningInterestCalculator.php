<?php

namespace App\Support;

use App\Models\Pawning;
use Carbon\Carbon;

/**
 * Pawning interest is flat and cycle-based, never amortized — there is no
 * facility_schedules row for a Pawning ticket (see the Pawning model's
 * docblock). What's owed for the CURRENT cycle is always one of two flat
 * percentages of the outstanding capital: pawning_types.normal_rate_percent,
 * or the discounted special_rate_percent if settlement falls within
 * special_rate_days of the cycle's start — never a per-day accrual.
 *
 * The current cycle's start date isn't a stored column: it's always
 * repay_due_date - cycle_days, an invariant that holds because both store()
 * and renew() set repay_due_date = cycleStart + cycle_days.
 */
class PawningInterestCalculator
{
    /** @return array{rate: float, isSpecial: bool, daysElapsed: int, interest: float} for settling $capital as of $asOf. */
    public static function quote(Pawning $pawning, float $capital, ?Carbon $asOf = null): array
    {
        $detail = $pawning->pawningDetail()->with('pawningType')->firstOrFail();
        $type = $detail->pawningType;
        $asOf ??= now();
        $cycleStart = Carbon::parse($detail->repay_due_date)->subDays($type->cycle_days);
        $daysElapsed = max(0, $cycleStart->diffInDays($asOf, false));

        $isSpecial = $type->special_rate_percent !== null
            && $type->special_rate_days !== null
            && $daysElapsed <= $type->special_rate_days;
        $rate = $isSpecial ? (float) $type->special_rate_percent : (float) $type->normal_rate_percent;
        $interest = round($capital * $rate / 100, 2);

        return ['rate' => $rate, 'isSpecial' => $isSpecial, 'daysElapsed' => (int) $daysElapsed, 'interest' => $interest];
    }

    /** Convenience: the interest quote for settling the ticket's full current outstanding balance. */
    public static function quoteFull(Pawning $pawning, ?Carbon $asOf = null): array
    {
        return self::quote($pawning, (float) $pawning->outstanding_balance_cache, $asOf);
    }

    /**
     * Capital + interest for settling only a subset of a ticket's pledged
     * articles — the shared math behind both partial redemption and auction
     * settlement. One ticket's outstanding balance secures several articles
     * jointly, so a subset's capital share is that subset's proportion of
     * the ticket's total max_loan_value (the simplest defensible split when
     * there's no per-article sub-loan to point to directly).
     *
     * @param  array<int>  $facilityCollateralIds  Pledged article ids to settle — must all belong to $pawning and still be Pledged.
     * @return array{rate: float, isSpecial: bool, daysElapsed: int, interest: float, capital: float, total: float}
     */
    public static function quoteForArticles(Pawning $pawning, array $facilityCollateralIds, ?Carbon $asOf = null): array
    {
        $pledged = $pawning->collateral()->where('collateral_type', 'PawnedJewelry')
            ->whereHas('pawnDetail', fn ($q) => $q->where('item_status', 'Pledged'))
            ->with('pawnDetail')->get();
        $release = $pledged->whereIn('id', $facilityCollateralIds);

        $totalMaxLoanValue = (float) $pledged->sum(fn ($c) => (float) $c->pawnDetail->max_loan_value);
        $releaseMaxLoanValue = (float) $release->sum(fn ($c) => (float) $c->pawnDetail->max_loan_value);
        $outstanding = (float) $pawning->outstanding_balance_cache;
        $capital = $totalMaxLoanValue > 0
            ? round($outstanding * ($releaseMaxLoanValue / $totalMaxLoanValue), 2)
            : 0.0;

        $quote = self::quote($pawning, $capital, $asOf);
        $quote['capital'] = $capital;
        $quote['total'] = round($capital + $quote['interest'], 2);

        return $quote;
    }
}
