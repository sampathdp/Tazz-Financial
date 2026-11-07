<?php

namespace App\Support;

use App\Models\Customer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Internal, CRIB-style credit report for one customer — computed live off
 * this company's own credit_facilities / facility_schedules / facility_payments.
 *
 * NOT a Credit Information Bureau report: it only ever sees facilities granted
 * by this company, and the score is a plain in-house indicator, not a CRIB
 * bureau score. The layout deliberately echoes the CRIB "Consumer Credit
 * Report" shape (a scoring band, the payment-profile-status figures, a
 * month-by-month payment profile grid, a credit-facility list) so staff who
 * already read CRIB reports find ours familiar.
 */
class CribReport
{
    /** Trailing months the payment-profile grid covers. */
    public const GRID_MONTHS = 12;

    private const OPEN_STATUSES = ['Active', 'Lapsed'];
    private const CLOSED_STATUSES = ['Closed', 'Restructured', 'WrittenOff', 'Rejected', 'Cancelled'];

    public function __construct(private readonly Customer $customer)
    {
    }

    public static function build(Customer $customer): array
    {
        return (new self($customer))->compute();
    }

    private function compute(): array
    {
        $today = CarbonImmutable::today();

        // Only facilities that actually moved money (or are live) — an
        // application that was rejected/cancelled before disbursement is an
        // inquiry, not a credit facility, and is counted as such below.
        $facilities = DB::table('credit_facilities')
            ->where('customer_id', $this->customer->id)
            ->whereNull('deleted_at')
            ->where(fn ($q) => $q->whereNotNull('disbursed_at')->orWhereIn('status', ['Active', 'Lapsed']))
            ->orderByDesc('id')
            ->get();

        $ids = $facilities->pluck('id')->all();

        $productNames = $this->productNames($ids);
        $schedulesByFacility = DB::table('facility_schedules')
            ->whereIn('credit_facility_id', $ids)
            ->orderBy('due_date')
            ->get(['credit_facility_id', 'due_date', 'total_due', 'paid_amount', 'paid_at', 'status'])
            ->groupBy('credit_facility_id');
        $lastPaymentAt = DB::table('facility_payments')
            ->whereIn('credit_facility_id', $ids)
            ->where('status', '!=', 'Voided')
            ->groupBy('credit_facility_id')
            ->selectRaw('credit_facility_id, MAX(paid_at) as last_paid_at')
            ->pluck('last_paid_at', 'credit_facility_id');

        // The month-ends the grid is evaluated at — the current month uses
        // today, every earlier month uses its last day.
        $gridMonths = [];
        for ($i = self::GRID_MONTHS - 1; $i >= 0; $i--) {
            $monthStart = $today->startOfMonth()->subMonths($i);
            $gridMonths[] = [
                'label' => $monthStart->format('M y'),
                'asOf' => $monthStart->isSameMonth($today) ? $today : $monthStart->endOfMonth(),
            ];
        }

        $facilityRows = [];
        $gridRows = [];
        $highestCurrentNdia = 0;
        $highest12Ndia = 0;
        $totalArrears = 0.0;
        $openCount = 0;
        $closedCount = 0;
        $writtenOffCount = 0;
        $anyNpl = false;
        $totalGrantedOpen = 0.0;
        $totalBalanceOpen = 0.0;
        $totalInstallmentOpen = 0.0;

        foreach ($facilities as $f) {
            $sched = $schedulesByFacility->get($f->id, collect());
            $isOpen = in_array($f->status, self::OPEN_STATUSES, true);
            if ($isOpen) {
                $openCount++;
                $totalGrantedOpen += (float) $f->principal_amount;
                $totalBalanceOpen += (float) $f->outstanding_balance_cache;
                $totalInstallmentOpen += $this->installmentAmount($sched);
            } elseif (in_array($f->status, self::CLOSED_STATUSES, true)) {
                $closedCount++;
            }
            if ($f->status === 'WrittenOff') {
                $writtenOffCount++;
            }
            if ((int) $f->npl_flag === 1) {
                $anyNpl = true;
            }

            $currentNdia = $this->ndiaAt($sched, $today);
            $arrears = $this->arrearsAt($sched, $today);
            if ($isOpen) {
                $totalArrears += $arrears;
                $highestCurrentNdia = max($highestCurrentNdia, $currentNdia ?? 0);
            }

            $cells = [];
            $facHigh12 = 0;
            foreach ($gridMonths as $gm) {
                $n = $this->ndiaAt($sched, $gm['asOf']);
                $cells[] = ['month' => $gm['label'], 'ndia' => $n];
                if ($n !== null) {
                    $facHigh12 = max($facHigh12, $n);
                    $highest12Ndia = max($highest12Ndia, $n);
                }
            }

            $label = $productNames[$f->id] ?? ($f->facility_type === 'SpeedDraft' ? 'Speed Draft' : $f->facility_type);

            $facilityRows[] = [
                'code' => $f->code,
                'type' => $f->facility_type,
                'product' => $label,
                'status' => $f->status,
                'ownership' => 'Borrower',
                'amountGranted' => round((float) $f->principal_amount, 2),
                'currentBalance' => round((float) $f->outstanding_balance_cache, 2),
                'amountInArrears' => round($arrears, 2),
                'installmentAmount' => round($this->installmentAmount($sched), 2),
                'currentNdia' => $currentNdia,
                'highestNdia12m' => $facHigh12,
                'firstDisbursementDate' => $f->disbursed_at ? CarbonImmutable::parse($f->disbursed_at)->toDateString() : null,
                'lastPaymentDate' => $lastPaymentAt->get($f->id) ? CarbonImmutable::parse($lastPaymentAt->get($f->id))->toDateString() : null,
                'maturityDate' => $f->maturity_date ? CarbonImmutable::parse($f->maturity_date)->toDateString() : null,
                'writtenOff' => $f->status === 'WrittenOff',
            ];
            // Grid is a payment history — skip facilities that never had an
            // installment fall due (nothing to show a profile for).
            if ($sched->isNotEmpty()) {
                $gridRows[] = [
                    'code' => $f->code,
                    'product' => $label,
                    'status' => $f->status,
                    'cells' => $cells,
                ];
            }
        }

        $score = $this->score($highestCurrentNdia, $totalArrears, $writtenOffCount, $anyNpl);

        return [
            'customer' => [
                'id' => $this->customer->id,
                'code' => $this->customer->code,
                'fullName' => $this->customer->full_name,
                'nicNo' => $this->customer->nic_no,
                'phone' => $this->customer->phone,
            ],
            'generatedAt' => CarbonImmutable::now()->toIso8601String(),
            'asOf' => $today->toDateString(),
            'disclaimer' => 'Internal indicative report generated from this company\'s own records only. '
                .'It is not a Credit Information Bureau (CRIB) report and the score below is not a CRIB bureau score.',
            'scoring' => [
                'score' => $score['score'],
                'riskGrade' => $score['grade'],
                'riskLabel' => $score['label'],
                'scale' => ['min' => 250, 'max' => 900],
            ],
            'paymentProfileStatus' => [
                'openCreditFacilities' => $openCount,
                'closedCreditFacilities' => $closedCount,
                'writtenOffCreditFacilities' => $writtenOffCount,
                'totalAmountGrantedOpen' => round($totalGrantedOpen, 2),
                'totalCurrentBalanceOpen' => round($totalBalanceOpen, 2),
                'totalInstallmentAmountOpen' => round($totalInstallmentOpen, 2),
                'totalAmountInArrears' => round($totalArrears, 2),
                'highestCurrentNdia' => $highestCurrentNdia,
                'highestNdia12Months' => $highest12Ndia,
            ],
            'paymentProfileGrid' => [
                'months' => array_map(fn ($m) => $m['label'], $gridMonths),
                'rows' => $gridRows,
            ],
            'creditFacilities' => $facilityRows,
            'guarantorFacilities' => $this->guarantorFacilities(),
            'inquiries' => [
                'last6Months' => $this->inquiryCount($today->subMonths(6)),
                'last12Months' => $this->inquiryCount($today->subMonths(12)),
            ],
        ];
    }

    /** loan_type / speed_draft_type display name keyed by credit_facility_id. */
    private function productNames(array $ids): array
    {
        if (!$ids) {
            return [];
        }
        $loans = DB::table('loan_facility_details')
            ->join('loan_types', 'loan_types.id', '=', 'loan_facility_details.loan_type_id')
            ->whereIn('loan_facility_details.credit_facility_id', $ids)
            ->pluck('loan_types.name', 'loan_facility_details.credit_facility_id')
            ->all();
        $drafts = DB::table('speed_draft_facility_details')
            ->join('speed_draft_types', 'speed_draft_types.id', '=', 'speed_draft_facility_details.speed_draft_type_id')
            ->whereIn('speed_draft_facility_details.credit_facility_id', $ids)
            ->pluck('speed_draft_types.name', 'speed_draft_facility_details.credit_facility_id')
            ->all();

        return $loans + $drafts;
    }

    /**
     * Number of Days In Arrears for one facility as at $asOf: days between the
     * oldest still-unpaid installment that had fallen due by then and $asOf.
     * Returns null when the facility had not started yet (no installment due) —
     * rendered as "–" (no data), matching a CRIB payment profile.
     */
    private function ndiaAt(iterable $schedules, CarbonImmutable $asOf): ?int
    {
        $started = false;
        $oldestUnpaid = null;

        foreach ($schedules as $s) {
            $due = CarbonImmutable::parse($s->due_date);
            if ($due->gt($asOf)) {
                continue;
            }
            $started = true;

            if ($s->status === 'Paid' && $s->paid_at === null) {
                continue; // legacy fully-paid row with no timestamp — treat as settled
            }
            $paidByAsOf = $s->paid_at !== null
                && CarbonImmutable::parse($s->paid_at)->lte($asOf)
                && (float) $s->paid_amount >= (float) $s->total_due - 0.01;
            if ($paidByAsOf) {
                continue;
            }
            if ($oldestUnpaid === null || $due->lt($oldestUnpaid)) {
                $oldestUnpaid = $due;
            }
        }

        if (!$started) {
            return null;
        }

        return $oldestUnpaid ? (int) $oldestUnpaid->diffInDays($asOf) : 0;
    }

    /** Overdue balance for one facility as at $asOf (installments past due, unpaid portion). */
    private function arrearsAt(iterable $schedules, CarbonImmutable $asOf): float
    {
        $sum = 0.0;
        foreach ($schedules as $s) {
            if (CarbonImmutable::parse($s->due_date)->gte($asOf)) {
                continue;
            }
            $sum += max(0.0, (float) $s->total_due - (float) $s->paid_amount);
        }

        return round($sum, 2);
    }

    /** Representative installment for a facility — its first scheduled total_due. */
    private function installmentAmount(iterable $schedules): float
    {
        foreach ($schedules as $s) {
            return (float) $s->total_due;
        }

        return 0.0;
    }

    /**
     * In-house score, 250–900. Starts at 900 and deducts for current lateness,
     * standing arrears, past write-offs and an NPL flag. Deliberately simple:
     * it is an internal risk indicator, not a modelled probability of default.
     */
    private function score(int $highestCurrentNdia, float $totalArrears, int $writtenOffCount, bool $anyNpl): array
    {
        $score = 900.0;
        $score -= min(450, $highestCurrentNdia * 5);
        if ($totalArrears > 0) {
            $score -= min(150, 40 + $totalArrears / 20000);
        }
        $score -= min(300, $writtenOffCount * 150);
        if ($anyNpl) {
            $score -= 60;
        }
        $score = (int) round(max(250, min(900, $score)));

        [$grade, $label] = match (true) {
            $score >= 760 => ['A', 'Very Low Risk'],
            $score >= 660 => ['B', 'Low Risk'],
            $score >= 560 => ['C', 'Average Risk'],
            $score >= 460 => ['D', 'High Risk'],
            default => ['E', 'Very High Risk'],
        };

        return ['score' => $score, 'grade' => $grade, 'label' => $label];
    }

    /** Facilities on which this customer stands as guarantor. */
    private function guarantorFacilities(): array
    {
        return DB::table('facility_guarantors')
            ->join('credit_facilities', 'credit_facilities.id', '=', 'facility_guarantors.credit_facility_id')
            ->leftJoin('customers', 'customers.id', '=', 'credit_facilities.customer_id')
            ->where('facility_guarantors.guarantor_customer_id', $this->customer->id)
            ->whereNull('credit_facilities.deleted_at')
            ->orderByDesc('credit_facilities.id')
            ->get([
                'credit_facilities.code',
                'credit_facilities.status',
                'credit_facilities.principal_amount',
                'credit_facilities.outstanding_balance_cache',
                'customers.full_name as borrower_name',
                'facility_guarantors.guaranteed_amount',
            ])
            ->map(fn ($r) => [
                'code' => $r->code,
                'status' => $r->status,
                'borrowerName' => $r->borrower_name,
                'amountGranted' => round((float) $r->principal_amount, 2),
                'currentBalance' => round((float) $r->outstanding_balance_cache, 2),
                'guaranteedAmount' => $r->guaranteed_amount !== null ? round((float) $r->guaranteed_amount, 2) : null,
            ])
            ->all();
    }

    private function inquiryCount(CarbonImmutable $since): int
    {
        return DB::table('credit_facilities')
            ->where('customer_id', $this->customer->id)
            ->whereNull('deleted_at')
            ->where('created_at', '>=', $since)
            ->count();
    }

    /**
     * Table sections for the PDF export — same data as build(), reshaped to the
     * generic report view's section/column/row contract.
     *
     * @return array{0: string, 1: array, 2: ?string}
     */
    public static function pdfSections(array $data): array
    {
        $s = $data['paymentProfileStatus'];
        $ndia = fn (?int $n) => $n === null ? '–' : (string) $n;

        $sections = [];

        $sections[] = [
            'title' => 'Scoring',
            'columns' => [['key' => 'metric', 'label' => 'Metric'], ['key' => 'value', 'label' => 'Value', 'align' => 'right']],
            'rows' => [
                ['metric' => 'Internal Score (250–900)', 'value' => $data['scoring']['score']],
                ['metric' => 'Risk Grade', 'value' => $data['scoring']['riskGrade'].' — '.$data['scoring']['riskLabel']],
            ],
        ];

        $sections[] = [
            'title' => 'Payment Profile Status',
            'columns' => [['key' => 'metric', 'label' => 'Metric'], ['key' => 'value', 'label' => 'Value', 'align' => 'right']],
            'rows' => [
                ['metric' => 'Open Credit Facilities', 'value' => $s['openCreditFacilities']],
                ['metric' => 'Closed Credit Facilities', 'value' => $s['closedCreditFacilities']],
                ['metric' => 'Written-off Credit Facilities', 'value' => $s['writtenOffCreditFacilities']],
                ['metric' => 'Total Amount Granted (open)', 'value' => number_format($s['totalAmountGrantedOpen'], 2)],
                ['metric' => 'Total Current Balance (open)', 'value' => number_format($s['totalCurrentBalanceOpen'], 2)],
                ['metric' => 'Total Installment Amount (open)', 'value' => number_format($s['totalInstallmentAmountOpen'], 2)],
                ['metric' => 'Total Amount in Arrears', 'value' => number_format($s['totalAmountInArrears'], 2)],
                ['metric' => 'Highest Current NDIA', 'value' => $s['highestCurrentNdia']],
                ['metric' => 'Highest NDIA in 12 Months', 'value' => $s['highestNdia12Months']],
                ['metric' => 'Inquiries — last 6 / 12 months', 'value' => $data['inquiries']['last6Months'].' / '.$data['inquiries']['last12Months']],
            ],
        ];

        $months = $data['paymentProfileGrid']['months'];
        $gridColumns = [['key' => 'code', 'label' => 'Facility']];
        foreach ($months as $i => $label) {
            $gridColumns[] = ['key' => 'm'.$i, 'label' => $label, 'align' => 'center'];
        }
        $gridRows = array_map(function ($row) use ($ndia) {
            $out = ['code' => $row['code']];
            foreach ($row['cells'] as $i => $cell) {
                $out['m'.$i] = $ndia($cell['ndia']);
            }

            return $out;
        }, $data['paymentProfileGrid']['rows']);
        $sections[] = [
            'title' => 'Payment Profile — Number of Days in Arrears by month',
            'columns' => $gridColumns,
            'rows' => $gridRows,
            'emptyText' => 'No credit facilities on record',
        ];

        $sections[] = [
            'title' => 'Credit Facilities',
            'columns' => [
                ['key' => 'code', 'label' => 'Code'],
                ['key' => 'product', 'label' => 'Product'],
                ['key' => 'status', 'label' => 'Status'],
                ['key' => 'amountGranted', 'label' => 'Granted', 'align' => 'right', 'format' => 'money'],
                ['key' => 'currentBalance', 'label' => 'Balance', 'align' => 'right', 'format' => 'money'],
                ['key' => 'amountInArrears', 'label' => 'Arrears', 'align' => 'right', 'format' => 'money'],
                ['key' => 'installmentAmount', 'label' => 'Installment', 'align' => 'right', 'format' => 'money'],
                ['key' => 'currentNdiaText', 'label' => 'NDIA', 'align' => 'right'],
                ['key' => 'firstDisbursementDate', 'label' => 'Disbursed'],
                ['key' => 'lastPaymentDate', 'label' => 'Last Paid'],
                ['key' => 'maturityDate', 'label' => 'Maturity'],
            ],
            'rows' => array_map(fn ($f) => $f + ['currentNdiaText' => $ndia($f['currentNdia'])], $data['creditFacilities']),
            'emptyText' => 'No credit facilities on record',
        ];

        if (!empty($data['guarantorFacilities'])) {
            $sections[] = [
                'title' => 'Facilities Guaranteed by this Customer',
                'columns' => [
                    ['key' => 'code', 'label' => 'Code'],
                    ['key' => 'borrowerName', 'label' => 'Borrower'],
                    ['key' => 'status', 'label' => 'Status'],
                    ['key' => 'amountGranted', 'label' => 'Granted', 'align' => 'right', 'format' => 'money'],
                    ['key' => 'currentBalance', 'label' => 'Balance', 'align' => 'right', 'format' => 'money'],
                ],
                'rows' => $data['guarantorFacilities'],
            ];
        }

        $meta = 'As of '.$data['asOf'].' • '.$data['disclaimer'];

        return ['Customer Credit Report (Internal) — '.$data['customer']['fullName'], $sections, $meta];
    }
}
