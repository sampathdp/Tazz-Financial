<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApprovalRequest;
use App\Models\ApprovalType;
use App\Models\Branch;
use App\Models\FacilitySchedule;
use App\Models\SpeedDraft;
use App\Models\SpeedDraftType;
use App\Services\NumberGenerator;
use App\Support\SpeedDraftLedgerPoster;
use App\Support\SpeedDraftScheduler;
use App\Support\SpeedDraftSummary;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SpeedDraftController extends Controller
{
    private const COLLATERAL_TYPES = ['Vehicle', 'Jewelry', 'Property', 'Other'];

    private const SUMMARY_RELATIONS = [
        'branch:id,name,code', 'customer:id,full_name,code,nic_no,phone',
        'speedDraftDetail.speedDraftType:id,name,code,category,grace_period_days',
    ];
    private const DETAIL_RELATIONS = [
        'branch:id,name,code', 'customer:id,full_name,code,nic_no,phone',
        'speedDraftDetail.speedDraftType', 'insurance', 'applier:id,name', 'approver:id,name', 'creator:id,name',
        'collateral.jewelryDetail', 'collateral.vehicleDetail', 'collateral.documents',
        'schedules.allocations', 'payments.receiver:id,name', 'payments.voider:id,name',
        'pendingApplicationRequest.requester:id,name',
    ];

    /**
     * With no `page` param, returns the full (optionally status-filtered)
     * list exactly as before — PaymentReceipt.tsx's "pay against an Active
     * Speed Draft" picker calls list({status:'Active'}) with no page param
     * and needs the full set for local search, unaffected. `page` opts into
     * search + pagination for the Speed Drafts browse screen.
     */
    public function index(Request $request)
    {
        $query = SpeedDraft::with(self::SUMMARY_RELATIONS)
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status));

        if (!$request->filled('page')) {
            return $query->latest()->get();
        }

        if ($request->filled('search')) {
            $term = $request->search;
            $query->where(function ($q) use ($term) {
                $q->where('code', 'like', "%{$term}%")
                    ->orWhereHas('customer', fn ($qq) => $qq->where('full_name', 'like', "%{$term}%")->orWhere('code', 'like', "%{$term}%"))
                    ->orWhereHas('speedDraftDetail.speedDraftType', fn ($qq) => $qq->where('name', 'like', "%{$term}%"));
            });
        }

        return $query->latest()->paginate((int) $request->input('perPage', 25));
    }

    /** Per-status counts for the Speed Drafts filter chips — independent of the current search text, same as before pagination. */
    public function statusCounts()
    {
        $counts = SpeedDraft::selectRaw('status, count(*) as count')->groupBy('status')->pluck('count', 'status');

        return ['All' => (int) $counts->sum(), ...array_map('intval', $counts->all())];
    }

    /** Active drafts with at least one not-yet-paid installment past its grace period — computed live, never a stored flag (see SpeedDraftSummary::isOverdue). */
    public function overdue()
    {
        $today = Carbon::today();

        return SpeedDraft::where('status', 'Active')
            ->with(array_merge(self::SUMMARY_RELATIONS, ['schedules.allocations']))
            ->get()
            ->map(function (SpeedDraft $draft) use ($today) {
                $graceDays = (int) ($draft->speedDraftDetail?->speedDraftType?->grace_period_days ?? 5);
                $overdueRows = $draft->schedules->filter(fn ($s) => SpeedDraftSummary::isOverdue($s, $graceDays, $today));
                if ($overdueRows->isEmpty()) {
                    return null;
                }
                $oldest = $overdueRows->min('due_date');
                $overdueInterest = round((float) $overdueRows->sum(
                    fn ($s) => max(0, (float) $s->interest_due - (float) $s->allocations->sum('interest_applied'))
                ), 2);
                $draft->setAttribute('oldestOverdueDate', $oldest);
                $draft->setAttribute('daysOverdue', (int) $today->diffInDays($oldest));
                $draft->setAttribute('overdueInstallments', $overdueRows->count());
                $draft->setAttribute('overdueInterest', $overdueInterest);

                return $draft;
            })
            ->filter()
            ->values();
    }

    public function show(SpeedDraft $speedDraft)
    {
        $speedDraft->load(self::DETAIL_RELATIONS);
        $speedDraft->setAttribute('summary', SpeedDraftSummary::build($speedDraft));

        return $speedDraft;
    }

    public function store(Request $request)
    {
        [$facilityData, $detailData, $collateral, $insurance] = $this->validated($request);
        $branch = Branch::findOrFail($facilityData['branch_id']);

        $speedDraft = DB::transaction(function () use ($facilityData, $detailData, $collateral, $insurance, $branch, $request) {
            // No {CODE} placeholder in this module's default format — number_sequences'
            // loan_type_id/funding_product_id key columns are reserved for Loan/FD
            // (generated_numbers.loan_type_id is a hard FK to loan_types), and Speed
            // Draft Types are a separate table, so per-type numbering isn't wired here.
            $code = NumberGenerator::next('speed_draft', [
                'branch_id' => $branch->id,
                'branch_code' => $branch->code,
                'user_id' => $request->user()->id,
            ]);
            $draft = SpeedDraft::create(array_merge($facilityData, [
                'code' => $code,
                'outstanding_balance_cache' => $facilityData['principal_amount'],
                'status' => 'Pending',
                'applied_by' => $request->user()->id,
                'created_by' => $request->user()->id,
            ]));
            NumberGenerator::attach($code, $draft);
            $draft->speedDraftDetail()->create($detailData);
            if ($insurance) {
                $draft->insurance()->create($insurance);
            }

            foreach ($collateral ?? [] as $item) {
                $this->createCollateral($draft, $item);
            }

            $type = ApprovalType::where('code', 'speed_draft_application')->firstOrFail();
            ApprovalRequest::create([
                'approval_type_id' => $type->id,
                'subject_id' => $draft->id,
                'requested_by' => $request->user()->id,
                'requested_at' => now(),
                'required_approvals' => $type->required_approvals,
            ]);

            return $draft;
        });

        return response()->json($speedDraft->load(self::DETAIL_RELATIONS), 201);
    }

    public function update(Request $request, SpeedDraft $speedDraft)
    {
        if ($speedDraft->status !== 'Pending') {
            return response()->json(['message' => 'Only a Pending Speed Draft can be edited.'], 422);
        }
        [$facilityData, $detailData, $collateral, $insurance] = $this->validated($request, $speedDraft);

        DB::transaction(function () use ($speedDraft, $facilityData, $detailData, $collateral, $insurance) {
            $speedDraft->update(array_merge($facilityData, ['outstanding_balance_cache' => $facilityData['principal_amount']]));
            $speedDraft->speedDraftDetail()->update($detailData);
            $this->syncInsuranceDetail($speedDraft, $insurance);
            if ($collateral !== null) {
                // Cascades to any attached facility_collateral_documents too — editing
                // collateral through this whole-array replace (rather than the dedicated
                // collateral CRUD SpeedDraftDetail's Collateral panel uses) silently loses
                // any photos already attached to the rows being replaced.
                $speedDraft->collateral()->delete();
                foreach ($collateral as $item) {
                    $this->createCollateral($speedDraft, $item);
                }
            }
        });

        return $speedDraft->load(self::DETAIL_RELATIONS);
    }

    public function destroy(SpeedDraft $speedDraft)
    {
        if ($speedDraft->status !== 'Pending') {
            return response()->json(['message' => 'Only a Pending Speed Draft can be deleted.'], 422);
        }
        if ($appRequest = $speedDraft->pendingApplicationRequest) {
            $appRequest->delete();
        }
        $speedDraft->delete();

        return response()->noContent();
    }

    /** Applies once the associated approval_requests row (created at store() time,
     *  code 'speed_draft_application') has collected enough distinct approvals —
     *  mirrors LoanController::approve()'s 'loan_application' flow. Authorization
     *  is entirely via ApprovalType::isAuthorized('speed_draft_application') now —
     *  no route-level permission gate (see routes/api.php). */
    public function approve(Request $request, SpeedDraft $speedDraft)
    {
        if ($speedDraft->status !== 'Pending') {
            return response()->json(['message' => 'Only a Pending Speed Draft can be approved.'], 422);
        }
        $appRequest = $speedDraft->pendingApplicationRequest;
        if (!$appRequest) {
            return response()->json(['message' => 'No pending approval request found for this draft.'], 422);
        }
        if (!ApprovalType::isAuthorized($request->user(), 'speed_draft_application')) {
            return response()->json(['message' => 'You are not authorized to approve Speed Drafts.'], 403);
        }
        if ($appRequest->requested_by === $request->user()->id) {
            return response()->json(['message' => 'You cannot approve your own application.'], 422);
        }
        if ($appRequest->hasApproved($request->user()->id)) {
            return response()->json(['message' => 'You have already approved this request.'], 422);
        }

        DB::transaction(function () use ($appRequest, $speedDraft, $request) {
            if (!$appRequest->first_approved_by) {
                $appRequest->update(['first_approved_by' => $request->user()->id, 'first_approved_at' => now()]);
            } else {
                $appRequest->update(['second_approved_by' => $request->user()->id, 'second_approved_at' => now()]);
            }

            if ($appRequest->fresh()->approvalsCount() < $appRequest->required_approvals) {
                return;
            }

            $speedDraft->update(['status' => 'Approved', 'approved_by' => $request->user()->id, 'approved_at' => now()]);
            $appRequest->update(['status' => 'Approved']);
        });

        return $speedDraft->fresh()->load(self::DETAIL_RELATIONS);
    }

    public function reject(Request $request, SpeedDraft $speedDraft)
    {
        if ($speedDraft->status !== 'Pending') {
            return response()->json(['message' => 'Only a Pending Speed Draft can be rejected.'], 422);
        }
        $appRequest = $speedDraft->pendingApplicationRequest;
        if (!$appRequest) {
            return response()->json(['message' => 'No pending approval request found for this draft.'], 422);
        }
        if (!ApprovalType::isAuthorized($request->user(), 'speed_draft_application')) {
            return response()->json(['message' => 'You are not authorized to reject Speed Drafts.'], 403);
        }
        if ($appRequest->requested_by === $request->user()->id) {
            return response()->json(['message' => 'You cannot reject your own application — withdraw it instead.'], 422);
        }
        $data = $request->validate(['rejected_reason' => 'nullable|string|max:255']);

        DB::transaction(function () use ($appRequest, $speedDraft, $request, $data) {
            $appRequest->update([
                'status' => 'Rejected',
                'rejected_by' => $request->user()->id,
                'rejected_at' => now(),
                'reject_reason' => $data['rejected_reason'] ?? null,
            ]);
            $speedDraft->update(['status' => 'Rejected', 'rejected_reason' => $data['rejected_reason'] ?? null]);
        });

        return $speedDraft->fresh()->load(self::DETAIL_RELATIONS);
    }

    /** Hands the capital to the customer and generates the interest-only schedule. */
    public function disburse(Request $request, SpeedDraft $speedDraft)
    {
        if ($speedDraft->status !== 'Approved') {
            return response()->json(['message' => 'Only an Approved Speed Draft can be disbursed.'], 422);
        }
        $data = $request->validate([
            'disbursement_method' => ['required', Rule::in(['Cash', 'Bank'])],
            'disbursed_amount' => 'nullable|numeric|min:0.01',
            'start_date' => 'required|date',
        ]);

        $speedDraft = DB::transaction(function () use ($speedDraft, $data) {
            $startDate = Carbon::parse($data['start_date']);
            $speedDraft->update([
                'disbursed_amount' => $data['disbursed_amount'] ?? $speedDraft->principal_amount,
                'disbursed_at' => now(),
                'start_date' => $startDate->format('Y-m-d'),
                'maturity_date' => $startDate->copy()->addMonthsNoOverflow($speedDraft->term_months)->format('Y-m-d'),
                'status' => 'Active',
            ]);
            $speedDraft->speedDraftDetail()->update(['disbursement_method' => $data['disbursement_method']]);
            $fresh = $speedDraft->fresh();

            FacilitySchedule::insert(SpeedDraftScheduler::generate($fresh));
            SpeedDraftLedgerPoster::postDisbursement($fresh);

            return $fresh;
        });

        return $speedDraft->load(self::DETAIL_RELATIONS);
    }

    private function createCollateral(SpeedDraft $draft, array $c): void
    {
        $jewelry = $c['jewelry'] ?? null;
        unset($c['jewelry']);
        $row = $draft->collateral()->create($c);
        if ($row->collateral_type === 'Jewelry' && $jewelry) {
            $row->jewelryDetail()->create($jewelry);
        }
    }

    /** @return array{0: array, 1: array, 2: ?array, 3: ?array} [facility fields, speedDraftDetail fields, collateral or null if omitted, insurance] */
    private function validated(Request $request, ?SpeedDraft $speedDraft = null): array
    {
        $typeId = $request->input('speed_draft_type_id', $speedDraft?->speedDraftDetail?->speed_draft_type_id);
        $type = $typeId ? SpeedDraftType::find($typeId) : null;

        $principalRules = ['required', 'numeric', 'min:' . ($type?->min_amount ?: 0.01)];
        if ($type?->max_amount) {
            $principalRules[] = 'max:' . $type->max_amount;
        }
        $termRules = ['required', 'integer', 'min:' . ($type?->min_period_months ?: 1)];
        if ($type?->max_period_months) {
            $termRules[] = 'max:' . $type->max_period_months;
        }

        $validated = $request->validate([
            'branch_id' => 'required|exists:branches,id',
            'customer_id' => 'required|exists:customers,id',
            'speed_draft_type_id' => 'required|exists:speed_draft_types,id',
            'principal_amount' => $principalRules,
            'interest_rate_percent' => 'nullable|numeric|min:0',
            'rate_period' => ['nullable', Rule::in(['monthly', 'annual', 'daily'])],
            'term_months' => $termRules,
            'payment_frequency' => 'nullable|string|max:16',
            'vehicle_no' => 'nullable|string|max:20',
            'insurance' => 'nullable|array',
            'insurance.insurance_company' => 'nullable|string|max:150',
            'insurance.policy_no' => 'nullable|string|max:64',
            'insurance.amount' => 'nullable|numeric|min:0',
            'insurance.contact_person' => 'nullable|string|max:150',
            'insurance.coverage' => 'nullable|string|max:255',
            'insurance.remarks' => 'nullable|string',
            'collateral' => 'nullable|array',
            'collateral.*.collateral_type' => ['nullable', Rule::in(self::COLLATERAL_TYPES)],
            'collateral.*.collateral_category' => 'nullable|string|max:64',
            'collateral.*.description' => 'required_with:collateral|string|max:255',
            'collateral.*.weight_grams' => 'nullable|numeric|min:0',
            'collateral.*.purity' => 'nullable|string|max:16',
            'collateral.*.appraised_value' => 'nullable|numeric|min:0',
        ]);

        // Lease drafts track the leased vehicle's registration number directly on
        // the draft (separate from the optional facility_collateral list — see the
        // speed_draft_facility_details.vehicle_no migration docblock).
        if ($type?->category === 'Lease' && empty($validated['vehicle_no'])) {
            throw ValidationException::withMessages(['vehicle_no' => 'Vehicle No is required for a Lease draft.']);
        }
        // Lease/Mortgage drafts require an insurance policy on the leased/mortgaged asset.
        if (in_array($type?->category, ['Lease', 'Mortgage'], true)) {
            foreach (['insurance_company', 'policy_no', 'amount', 'contact_person', 'coverage'] as $field) {
                if (!isset($validated['insurance'][$field]) || $validated['insurance'][$field] === '') {
                    throw ValidationException::withMessages(["insurance.$field" => 'Insurance details are required for a Lease or Mortgage draft.']);
                }
            }
        }

        $facilityData = [
            'branch_id' => $validated['branch_id'],
            'customer_id' => $validated['customer_id'],
            'principal_amount' => $validated['principal_amount'],
            'interest_rate_percent' => $validated['interest_rate_percent'] ?? $type?->interest_rate_percent ?? 0,
            'rate_period' => $validated['rate_period'] ?? $type?->rate_period ?? 'monthly',
            'term_months' => $validated['term_months'],
        ];

        $detailData = [
            'speed_draft_type_id' => $validated['speed_draft_type_id'],
            'vehicle_no' => $type?->category === 'Lease' ? $validated['vehicle_no'] : null,
            'payment_frequency' => $validated['payment_frequency'] ?? 'monthly',
        ];

        // Only meaningful for Lease/Mortgage — see the facility_insurance_details
        // migration docblock. Null (not an empty array) for every other category,
        // which syncInsuranceDetail() below reads as "no insurance row for this draft".
        $insurance = in_array($type?->category, ['Lease', 'Mortgage'], true) ? [
            'insurance_company' => $validated['insurance']['insurance_company'],
            'policy_no' => $validated['insurance']['policy_no'],
            'amount' => $validated['insurance']['amount'],
            'contact_person' => $validated['insurance']['contact_person'],
            'coverage' => $validated['insurance']['coverage'],
            'remarks' => $validated['insurance']['remarks'] ?? null,
        ] : null;

        $collateral = $request->has('collateral')
            ? array_map(fn ($c) => [
                'collateral_type' => $c['collateral_type'] ?? 'Jewelry',
                'collateral_category' => $c['collateral_category'] ?? null,
                'description' => $c['description'],
                'estimated_value' => $c['appraised_value'] ?? null,
                'jewelry' => [
                    'weight_grams' => $c['weight_grams'] ?? null,
                    'purity' => $c['purity'] ?? null,
                    'appraised_value' => $c['appraised_value'] ?? null,
                ],
            ], $validated['collateral'] ?? [])
            : null;

        return [$facilityData, $detailData, $collateral, $insurance];
    }

    /** Creates/updates/clears the draft's insurance row to match $insurance — null
     *  means the current speed draft type's category doesn't require one (see
     *  validated()), so any existing row (e.g. left over from before an edit
     *  changed speed_draft_type_id away from Lease/Mortgage) is removed. */
    private function syncInsuranceDetail(SpeedDraft $speedDraft, ?array $insurance): void
    {
        if ($insurance) {
            $speedDraft->insurance()->updateOrCreate([], $insurance);
        } else {
            $speedDraft->insurance()->delete();
        }
    }
}
