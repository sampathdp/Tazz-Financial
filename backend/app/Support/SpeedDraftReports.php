<?php

namespace App\Support;

use App\Models\SpeedDraft;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Live per-draft figures shared by SpeedDraftReportController and the dashboard,
 * so both always agree. Every number comes from SpeedDraftSummary (the same
 * source as the Speed Draft detail screen) - nothing is persisted.
 */
class SpeedDraftReports
{
    /** One flat row per Active Speed Draft: capital / interest / penalty / charges outstanding and arrears. */
    public static function activeRows(?int $typeId = null): Collection
    {
        $today = Carbon::today();

        return SpeedDraft::where('status', 'Active')
            ->when($typeId, fn ($q) => $q->whereHas('speedDraftDetail', fn ($q2) => $q2->where('speed_draft_type_id', $typeId)))
            ->with([
                'branch:id,name,code', 'customer:id,full_name,code,phone',
                'speedDraftDetail.speedDraftType:id,name,code,category,grace_period_days,default_charge_rate_percent',
                'speedDraftDetail.loanOfficer:id,name', 'speedDraftDetail.recoveryOfficer:id,name',
                'schedules.activeAllocations',
            ])
            ->get()
            ->map(function (SpeedDraft $d) use ($today) {
                $s = SpeedDraftSummary::build($d);
                $type = $d->speedDraftDetail?->speedDraftType;
                $grace = (int) ($type?->grace_period_days ?? 5);
                $overdueRows = $d->schedules->filter(fn ($r) => SpeedDraftSummary::isOverdue($r, $grace, $today));
                $waived = $overdueRows->isEmpty() ? ['interest' => []] : SpeedDraftWaivers::byRow($d, $today);
                $overdueInterest = round((float) $overdueRows->sum(
                    fn ($r) => max(0, (float) $r->interest_due - (float) $r->activeAllocations->sum('interest_applied') - (float) ($waived['interest'][$r->id] ?? 0))
                ), 2);

                return [
                    'id' => $d->id,
                    'code' => $d->code,
                    'customer' => $d->customer?->full_name ?? '—',
                    'customerCode' => $d->customer?->code,
                    'phone' => $d->customer?->phone,
                    'branchId' => $d->branch_id,
                    'branch' => $d->branch?->name ?? '—',
                    'typeId' => $d->speedDraftDetail?->speed_draft_type_id,
                    'type' => $type?->name ?? '—',
                    'officer' => $d->speedDraftDetail?->recoveryOfficer?->name ?? $d->speedDraftDetail?->loanOfficer?->name ?? '—',
                    'principal' => (float) $d->principal_amount,
                    'ratePercent' => (float) $d->interest_rate_percent,
                    'disbursedAt' => $d->disbursed_at?->toDateString(),
                    'maturityDate' => $d->maturity_date?->toDateString(),
                    'outstandingCapital' => (float) $s['outstandingCapital'],
                    'outstandingInterest' => (float) $s['outstandingInterest'],
                    'penaltyDue' => (float) $s['penaltyDue'],
                    'outstandingCharges' => (float) $s['outstandingCharges'],
                    'receivable' => (float) $s['amountToSettleToday'],
                    'daysOverdue' => (int) $s['daysOverdue'],
                    'overdueInstallments' => (int) $s['overdueInstallments'],
                    'overdueInterest' => $overdueInterest,
                ];
            })
            ->values();
    }

    /** PAR buckets keyed in display order. @return array<int, array{label: string, min: ?int, max: ?int}> */
    public const BUCKETS = [
        ['label' => 'Current (not overdue)', 'min' => null, 'max' => 0],
        ['label' => '1-29 days', 'min' => 1, 'max' => 29],
        ['label' => '30-59 days', 'min' => 30, 'max' => 59],
        ['label' => '60-89 days', 'min' => 60, 'max' => 89],
        ['label' => '90+ days (NPL)', 'min' => 90, 'max' => null],
    ];
}
