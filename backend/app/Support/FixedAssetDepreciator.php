<?php

namespace App\Support;

use App\Models\FixedAsset;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Straight-line monthly depreciation. A full month is charged from the month the asset enters service; the
 * charge never takes the asset below its residual value. run() catches every asset up to the chosen month, one
 * ledger entry per asset per month, and is safe to repeat: a month already depreciated is skipped, because
 * depreciated_through moves forward and (asset, period) is unique.
 */
class FixedAssetDepreciator
{
    public static function monthly(FixedAsset $asset): string
    {
        return number_format(((float) $asset->cost - (float) $asset->residual_value) / max(1, (int) $asset->useful_life_months), 2, '.', '');
    }

    /** Amount for the next month given the accumulated figure so far; '0.00' once fully depreciated. */
    public static function nextAmount(FixedAsset $asset, string $accumulated): string
    {
        $remaining = bcsub(bcsub((string) $asset->cost, (string) $asset->residual_value, 2), $accumulated, 2);
        if (bccomp($remaining, '0', 2) <= 0) {
            return '0.00';
        }
        $monthly = self::monthly($asset);
        $amount = bccomp($monthly, $remaining, 2) < 0 ? $monthly : $remaining;
        // Fold a sub-half-month rounding tail into this charge instead of leaving a stray cent-level month.
        if (bccomp(bcsub($remaining, $amount, 2), bcdiv($monthly, '2', 2), 2) < 0) {
            $amount = $remaining;
        }

        return $amount;
    }

    /** First period still to be charged, as a month-start date. */
    public static function firstPeriod(FixedAsset $asset): Carbon
    {
        return $asset->depreciated_through
            ? Carbon::parse($asset->depreciated_through)->startOfMonth()->addMonth()
            : Carbon::parse($asset->in_service_date)->startOfMonth();
    }

    /**
     * @param  string $through 'YYYY-MM' — the last month to charge
     * @return array{rows: array<int,array>, errors: array<int,string>, total: string}
     */
    public static function run(string $through, ?int $userId, bool $commit, ?int $branchId = null): array
    {
        $last = Carbon::createFromFormat('!Y-m', $through)->startOfMonth();
        $assets = FixedAsset::with('category')->where('status', 'Active')
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->orderBy('id')->get();

        $rows = [];
        $errors = [];
        $total = '0.00';
        foreach ($assets as $asset) {
            $acc = (string) $asset->accumulated_depreciation;
            $month = self::firstPeriod($asset);
            while ($month->lte($last)) {
                $amount = self::nextAmount($asset, $acc);
                if (bccomp($amount, '0', 2) <= 0) {
                    break;
                }
                $period = $month->format('Y-m');
                $periodEnd = $month->copy()->endOfMonth()->toDateString();
                if ($commit) {
                    try {
                        DB::transaction(function () use ($asset, $period, $periodEnd, $amount, $userId) {
                            // Lock + re-read: two runs (or a run and a disposal) must not post the same month twice.
                            $live = \App\Models\FixedAsset::lockForUpdate()->findOrFail($asset->id);
                            if ($live->status === 'Disposed' || ($live->depreciated_through && substr((string) $live->depreciated_through, 0, 10) >= $periodEnd)) {
                                throw new \RuntimeException('already depreciated through this month or disposed by another request');
                            }
                            $asset->setRawAttributes($live->getAttributes(), true);
                            FixedAssetLedgerPoster::postDepreciation($asset, $period, $periodEnd, $amount, $userId);
                            $newAcc = bcadd((string) $asset->accumulated_depreciation, $amount, 2);
                            $asset->accumulated_depreciation = $newAcc;
                            $asset->depreciated_through = $periodEnd;
                            if (bccomp($newAcc, bcsub((string) $asset->cost, (string) $asset->residual_value, 2), 2) >= 0) {
                                $asset->status = 'FullyDepreciated';
                            }
                            $asset->save();
                        });
                    } catch (\Throwable $e) {
                        $errors[] = "{$asset->code} {$period}: {$e->getMessage()}";
                        break;
                    }
                }
                $acc = bcadd($acc, $amount, 2);
                $total = bcadd($total, $amount, 2);
                $rows[] = [
                    'asset_id' => $asset->id, 'code' => $asset->code, 'name' => $asset->name,
                    'period' => $period, 'amount' => $amount,
                    'accumulated_after' => $acc, 'net_book_value_after' => bcsub((string) $asset->cost, $acc, 2),
                ];
                $month->addMonth();
            }
        }

        return ['rows' => $rows, 'errors' => $errors, 'total' => $total];
    }
}
