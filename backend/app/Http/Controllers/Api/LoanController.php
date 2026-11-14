<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApprovalRequest;
use App\Models\ApprovalType;
use App\Models\Branch;
use App\Models\FacilityCollateralFdDetail;
use App\Models\FacilityCollateralVehicleDetail;
use App\Models\FacilityReschedule;
use App\Models\FacilitySchedule;
use App\Models\Loan;
use App\Models\LoanType;
use App\Models\ChartOfAccount;
use App\Services\NumberGenerator;
use App\Support\FdLienService;
use App\Support\FinanceRequestService;
use App\Support\LoanAmortizer;
use App\Support\DocStore;
use App\Support\LoanDisbursementCompleter;
use App\Support\LoanLedgerPoster;
use App\Support\LoanPaymentAllocator;
use App\Support\LoanSummary;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class LoanController extends Controller
{
    private const FREQUENCIES = ['daily', 'weekly', 'monthly'];
    private const COLLATERAL_TYPES = ['Vehicle', 'Jewelry', 'Property', 'Other'];

    /** Matches frontend ActiveLoans.tsx's LIVE_STATUSES — the statuses shown on the Active Loans browse screen. */
    private const LIVE_STATUSES = ['Approved', 'Active', 'Lapsed', 'Closed', 'WrittenOff', 'Restructured'];

    private const SUMMARY_RELATIONS = [
        'customer:id,full_name,code,nic_no,phone',
        'coApplicants.coApplicant:id,full_name,code,nic_no,phone',
        'branch:id,name,code',
        'loanDetail.loanType:id,name,code',
        'loanDetail.loanOfficer:id,name',
        'pendingCancelRequest:id,approval_type_id,subject_id,status,required_approvals',
        'pendingWriteOffRequest:id,approval_type_id,subject_id,status,required_approvals',
        'pendingDisbursementRequest:id,code,source_id,status,amount,requested_by',
    ];

    private const DETAIL_RELATIONS = [
        'customer:id,full_name,code,nic_no,passport_no,driving_license_no,phone,email,address,occupation_type,monthly_income,status,is_guarantor,branch_id,dob',
        'customer.branch:id,name,code',
        'customer.employmentDetail',
        'customer.businessDetail',
        'branch:id,name,code',
        'bankDetail.bank:id,name',
        'loanDetail.loanType:id,name,code,parent_id,category,interest_method,interest_rate_percent,rate_period,default_charge_rate_percent,grace_period_days,processing_fee_percent',
        'loanDetail.loanOfficer:id,name',
        'loanDetail.recoveryOfficer:id,name',
        'loanDetail.confirmedBy:id,name',
        'insurance',
        'applier:id,name',
        'approver:id,name',
        'canceller:id,name',
        'writtenOffBy:id,name',
        'cancelRequests.requester:id,name', 'cancelRequests.firstApprover:id,name', 'cancelRequests.secondApprover:id,name', 'cancelRequests.rejecter:id,name',
        'pendingWriteOffRequest.requester:id,name', 'pendingWriteOffRequest.firstApprover:id,name',
        'pendingApplicationRequest.requester:id,name',
        'pendingDisbursementRequest:id,code,source_id,status,amount,requested_by',
        'pendingDisbursementRequest.requester:id,name',
        'coApplicants.coApplicant:id,full_name,code,nic_no,passport_no,driving_license_no,phone,email,address,occupation_type,monthly_income',
        'guarantors.guarantor:id,full_name,code,nic_no,passport_no,driving_license_no,phone,email,address,occupation_type,monthly_income',
        'guarantors.guarantor.employmentDetail',
        'guarantors.guarantor.businessDetail',
        'collateral.vehicleDetail',
        'collateral.jewelryDetail',
        'collateral.fdDetail.fixedDeposit:id,fd_number,principal_amount,interest_rate_percent,maturity_date,status,lien_amount',
        'collateral.documents.uploader:id,name',
        'schedules.allocations.payment:id,status,approval_status',
        'payments.receiver:id,name',
        'payments.voider:id,name',
        'payments.bank:id,name',
        'payments.pendingVoidRequest.requester:id,name',
        'payments.pendingAllocationRequest.requester:id,name', 'payments.pendingAllocationRequest.firstApprover:id,name',
        'payments.pendingSettlementRequest.requester:id,name', 'payments.pendingSettlementRequest.firstApprover:id,name',
        'charges.creator:id,name', 'charges.category:id,name',
        'postDatedCheques.creator:id,name',
        'postDatedCheques.bank:id,name',
        'documents.uploader:id,name',
        'diaryNotes.creator:id,name',
        'waivers.approver:id,name',
        'statusEvents.recordedBy:id,name',
        'restructuredFrom:id,code,status',
        'restructuredTo:id,code,status,restructured_from_id',
    ];

    /**
     * With no `page` param, returns the full list exactly as before — every
     * loan/customer picker across the app (LoanApplications, LoanAdjustments,
     * PaymentReceipt, ...) calls loansApi.list() with no params expecting a
     * flat array for local search, and must keep working unchanged. `page`
     * opts into server-side status/search filtering + pagination for the
     * Active Loans browse screen, which can't stay an unbounded full-table
     * fetch as the portfolio grows.
     */
    public function index(Request $request)
    {
        $query = Loan::with(self::SUMMARY_RELATIONS)
            ->withSum('schedules as total_due_sum', 'total_due')
            ->withSum('schedules as paid_sum', 'paid_amount');

        if (!$request->filled('page')) {
            return $query->orderByDesc('id')->get();
        }

        // The only current caller of paginated mode is the Active Loans screen,
        // scoped to LIVE_STATUSES exactly like the old client-side filter was
        // (approved/active/closed/written-off/restructured — not pending
        // applications or rejected/cancelled ones).
        if ($request->filled('status') && $request->status !== 'All') {
            $query->where('status', $request->status);
        } else {
            $query->whereIn('status', self::LIVE_STATUSES);
        }
        if ($request->filled('search')) {
            $term = $request->search;
            $query->where(function ($q) use ($term) {
                $q->where('code', 'like', "%{$term}%")
                    ->orWhereHas('customer', fn ($qq) => $qq->where('full_name', 'like', "%{$term}%")->orWhere('code', 'like', "%{$term}%"))
                    ->orWhereHas('loanDetail.loanType', fn ($qq) => $qq->where('name', 'like', "%{$term}%"));
            });
        }

        return $query->orderByDesc('id')->paginate((int) $request->input('perPage', 25));
    }

    /**
     * Queue for the Loan Disbursement screen: approved loans not yet paid out, plus loans whose
     * last disbursement stopped halfway (Active, disbursed_at set, no schedule).
     */
    public function awaitingDisbursement(Request $request)
    {
        $query = Loan::with(self::SUMMARY_RELATIONS)
            ->where(function ($q) {
                $q->where('status', 'Approved')
                    ->orWhere(fn ($qq) => $qq->where('status', 'Active')->whereNotNull('disbursed_at')->whereDoesntHave('schedules'));
            });
        if ($request->filled('search')) {
            $term = $request->search;
            $query->where(function ($q) use ($term) {
                $q->where('code', 'like', "%{$term}%")
                    ->orWhereHas('customer', fn ($qq) => $qq->where('full_name', 'like', "%{$term}%")->orWhere('code', 'like', "%{$term}%"));
            });
        }

        return $query->orderByDesc('id')->paginate((int) $request->input('perPage', 25));
    }

    /**
     * Per-status counts for the Active Loans screen's filter chips — kept as
     * its own cheap GROUP BY query rather than derived from a full loan list
     * on the client (which is exactly the unbounded fetch index() above now
     * avoids once paginated). Not affected by the current search text, same
     * as the counts these chips showed before pagination.
     */
    public function statusCounts()
    {
        $counts = Loan::whereIn('status', self::LIVE_STATUSES)
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        return ['All' => (int) $counts->sum(), ...array_map('intval', $counts->all())];
    }

    /**
     * Active loans with at least one installment past its due date and not
     * fully paid. There's no persisted "overdue" flag (FacilitySchedule.status
     * only ever becomes Pending/Partial/Paid — see LoanPaymentAllocator), so
     * this is computed live, the same way app\Support\LoanSummary does for a
     * single loan's arrears — penaltyDueForRow() is reused directly so the OD
     * figure here can never disagree with what Loan Detail shows.
     *
     * `to` is the as-of date (days-overdue/penalty are computed as of it,
     * defaulting to today); `from` optionally narrows the window to
     * installments due on/after that date.
     */
    public function overdue(Request $request)
    {
        $today = $request->filled('to') ? Carbon::parse($request->to)->startOfDay() : now()->startOfDay();
        $from = $request->filled('from') ? Carbon::parse($request->from)->startOfDay() : null;

        return LoanSummary::withReceivable(LoanSummary::overdueList($today, $from), $today);
    }

    public function show(Loan $loan)
    {
        $loan->load(self::DETAIL_RELATIONS);
        $data = $loan->toArray();
        $data['summary'] = LoanSummary::build($loan);
        $data['disbursement_incomplete'] = $loan->disbursementIncomplete();

        return $data;
    }

    /**
     * Records a recovery-workflow event (Terminated/Legal/SeizingOrderIssued/
     * Repossessed) — one row per occurrence, so a status that's issued,
     * withdrawn, and reissued keeps its full history instead of only the
     * latest flag (see the facility_status_events migration). freeze_date is
     * a plain loanDetail field, not an event, and is updated directly.
     */
    public function updateStatusFlags(Request $request, Loan $loan)
    {
        $data = $request->validate([
            'is_terminated' => 'boolean',
            'terminated_at' => 'nullable|date',
            'is_legal' => 'boolean',
            'legal_at' => 'nullable|date',
            'seizing_order_issued' => 'boolean',
            'seizing_order_at' => 'nullable|date',
            'is_repossessed' => 'boolean',
            'repossessed_at' => 'nullable|date',
            'freeze_date' => 'nullable|date',
            'notes' => 'nullable|string|max:255',
        ]);

        $eventMap = [
            'is_terminated' => ['Terminated', 'terminated_at'],
            'is_legal' => ['Legal', 'legal_at'],
            'seizing_order_issued' => ['SeizingOrderIssued', 'seizing_order_at'],
            'is_repossessed' => ['Repossessed', 'repossessed_at'],
        ];

        DB::transaction(function () use ($loan, $data, $eventMap, $request) {
            foreach ($eventMap as $flag => [$eventType, $dateField]) {
                if ($data[$flag] ?? false) {
                    $loan->statusEvents()->create([
                        'event_type' => $eventType,
                        'event_date' => $data[$dateField] ?? now()->toDateString(),
                        'notes' => $data['notes'] ?? null,
                        'recorded_by' => $request->user()->id,
                    ]);
                }
            }
            if (array_key_exists('freeze_date', $data)) {
                $loan->loanDetail()->update(['freeze_date' => $data['freeze_date']]);
            }
        });

        return $this->show($loan);
    }

    /** Reassign the Marketing Executive / Follow Up Officer. Separate from update()
     *  (which is locked to Pending applications) so officers can be reassigned for
     *  the life of the loan, including while it's Active. */
    public function updateOfficers(Request $request, Loan $loan)
    {
        $data = $request->validate([
            'loan_officer_id' => 'nullable|exists:users,id',
            'recovery_officer_id' => 'nullable|exists:users,id',
        ]);

        $loan->loanDetail()->update($data);

        return $this->show($loan);
    }

    public function store(Request $request)
    {
        [$facilityData, $detailData, $guarantors, $collateral, $insurance, $coApplicants] = $this->validated($request);
        $facilityData['applied_by'] = $request->user()->id;
        $facilityData['created_by'] = $request->user()->id;
        $facilityData['status'] = 'Pending';
        $detailData['application_date'] = $detailData['application_date'] ?? now()->toDateString();

        $loan = DB::transaction(function () use ($facilityData, $detailData, $guarantors, $collateral, $insurance, $coApplicants, $request) {
            $branch = Branch::find($facilityData['branch_id']);
            $loanType = LoanType::find($detailData['loan_type_id']);
            $facilityData['code'] = NumberGenerator::next('loan', [
                'branch_id' => $branch->id,
                'branch_code' => $branch->code,
                'loan_type_id' => $loanType->id,
                'loan_type_code' => $loanType->code,
                'user_id' => $request->user()->id,
            ], $loanType->reference_format);

            $loan = Loan::create($facilityData);
            NumberGenerator::attach($facilityData['code'], $loan);
            $loan->loanDetail()->create($detailData);
            if ($insurance) {
                $loan->insurance()->create($insurance);
            }
            foreach ($guarantors as $g) {
                $loan->guarantors()->create($g);
            }
            foreach ($coApplicants as $ca) {
                $loan->coApplicants()->create($ca);
            }
            foreach ($collateral as $c) {
                $this->createCollateral($loan, $c);
            }

            $type = ApprovalType::where('code', 'loan_application')->firstOrFail();
            ApprovalRequest::create([
                'approval_type_id' => $type->id,
                'subject_id' => $loan->id,
                'requested_by' => $request->user()->id,
                'requested_at' => now(),
                'required_approvals' => $type->required_approvals,
            ]);

            return $loan;
        });

        return response()->json($loan->load(self::DETAIL_RELATIONS), 201);
    }

    public function update(Request $request, Loan $loan)
    {
        if ($loan->status !== 'Pending') {
            return response()->json(['message' => 'Only pending applications can be edited.'], 422);
        }
        [$facilityData, $detailData, $guarantors, $collateral, $insurance, $coApplicants] = $this->validated($request, $loan);

        // A loan secured by an FD keeps the customer, rate and rate period that the FD policy set,
        // and may not be raised past what the pledged FD supports - the edit form must not bypass the lien rules.
        $lien = FacilityCollateralFdDetail::where('credit_facility_id', $loan->id)->where('lien_status', 'Active')->first();
        if ($lien) {
            if ((float) $facilityData['principal_amount'] > (float) $lien->max_loan_value + 0.005) {
                throw ValidationException::withMessages(['principal_amount' => 'Amount exceeds the maximum of ' . number_format((float) $lien->max_loan_value, 2) . ' allowed against the pledged FD.']);
            }
            $facilityData['customer_id'] = $loan->customer_id;
            $facilityData['interest_rate_percent'] = $loan->interest_rate_percent;
            $facilityData['rate_period'] = $loan->rate_period;
        }

        DB::transaction(function () use ($loan, $facilityData, $detailData, $guarantors, $collateral, $insurance, $coApplicants) {
            $loan->update($facilityData);
            $loan->loanDetail()->update($detailData);
            $this->syncInsuranceDetail($loan, $insurance);
            $loan->guarantors()->delete();
            foreach ($guarantors as $g) {
                $loan->guarantors()->create($g);
            }
            $loan->coApplicants()->delete();
            foreach ($coApplicants as $ca) {
                $loan->coApplicants()->create($ca);
            }
            // Update-in-place / create-new keyed by id, rather than delete-all-recreate,
            // so previously uploaded collateral documents (which cascade-delete with
            // their parent row) survive edits that don't touch that collateral item.
            $keepIds = array_values(array_filter(array_column($collateral, 'id')));
            $removed = $loan->collateral()->where('collateral_type', '!=', 'FixedDeposit')->whereNotIn('id', $keepIds ?: [0])->get();
            foreach ($removed as $r) {
                foreach ($r->documents as $doc) {
                    // After commit only: deleting the file inside the transaction loses it if the save then rolls back (or retries).
                    $path = $doc->file_path;
                    DB::afterCommit(fn () => DocStore::delete($path));
                }
            }
            // FixedDeposit collateral is a lien: only the FD-loan flow and loan status changes add/remove it.
            $loan->collateral()->where('collateral_type', '!=', 'FixedDeposit')->whereNotIn('id', $keepIds ?: [0])->delete();
            foreach ($collateral as $c) {
                $id = $c['id'] ?? null;
                unset($c['id']);
                $vehicle = $c['vehicle'] ?? null;
                unset($c['vehicle']);
                if ($id) {
                    $loan->collateral()->whereKey($id)->update($c);
                    if ($c['collateral_type'] === 'Vehicle' && $vehicle) {
                        FacilityCollateralVehicleDetail::updateOrCreate(['facility_collateral_id' => $id], $vehicle);
                    }
                } else {
                    $row = $loan->collateral()->create($c);
                    if ($row->collateral_type === 'Vehicle' && $vehicle) {
                        $row->vehicleDetail()->create($vehicle);
                    }
                }
            }
        });

        return $loan->load(self::DETAIL_RELATIONS);
    }

    public function destroy(Request $request, Loan $loan)
    {
        if ($loan->status !== 'Pending') {
            return response()->json(['message' => 'Only pending applications can be withdrawn.'], 422);
        }
        $data = $request->validate(['rejected_reason' => 'required|string|max:255']);

        DB::transaction(function () use ($loan, $data, $request) {
            $loan->update(['status' => 'Rejected', 'rejected_reason' => $data['rejected_reason']]);
            if ($appRequest = $loan->pendingApplicationRequest) {
                $appRequest->update([
                    'status' => 'Rejected',
                    'rejected_by' => $request->user()->id,
                    'rejected_at' => now(),
                    'reject_reason' => $data['rejected_reason'],
                ]);
            }
        });

        return response()->noContent();
    }

    /** Applies once the associated approval_requests row (created at store() time,
     *  code 'loan_application') has collected enough distinct approvals — see the
     *  loan_cancel trio above for the reference pattern this mirrors. Authorization
     *  is entirely via ApprovalType::isAuthorized('loan_application') now — no
     *  route-level permission gate (see routes/api.php). */
    public function approve(Request $request, Loan $loan)
    {
        if ($loan->status !== 'Pending') {
            return response()->json(['message' => 'Only pending applications can be approved.'], 422);
        }
        $appRequest = $loan->pendingApplicationRequest;
        if (!$appRequest) {
            return response()->json(['message' => 'No pending approval request found for this application.'], 422);
        }
        if (!ApprovalType::isAuthorized($request->user(), 'loan_application')) {
            return response()->json(['message' => 'You are not authorized to approve loan applications.'], 403);
        }
        if ($appRequest->requested_by === $request->user()->id) {
            return response()->json(['message' => 'You cannot approve your own application.'], 422);
        }
        if ($appRequest->hasApproved($request->user()->id)) {
            return response()->json(['message' => 'You have already approved this request.'], 422);
        }

        DB::transaction(function () use ($appRequest, $loan, $request) {
            $appRequest->lockPending();
            if (!$appRequest->first_approved_by) {
                $appRequest->update(['first_approved_by' => $request->user()->id, 'first_approved_at' => now()]);
            } else {
                $appRequest->update(['second_approved_by' => $request->user()->id, 'second_approved_at' => now()]);
            }

            if ($appRequest->fresh()->approvalsCount() < $appRequest->required_approvals) {
                return;
            }

            $loan->update([
                'status' => 'Approved',
                'approved_by' => $request->user()->id,
                'approved_at' => now(),
                'rejected_reason' => null,
            ]);
            $appRequest->update(['status' => 'Approved']);
        });

        return $loan->fresh()->load(self::DETAIL_RELATIONS);
    }

    public function reject(Request $request, Loan $loan)
    {
        if ($loan->status !== 'Pending') {
            return response()->json(['message' => 'Only pending applications can be rejected.'], 422);
        }
        $appRequest = $loan->pendingApplicationRequest;
        if (!$appRequest) {
            return response()->json(['message' => 'No pending approval request found for this application.'], 422);
        }
        if (!ApprovalType::isAuthorized($request->user(), 'loan_application')) {
            return response()->json(['message' => 'You are not authorized to reject loan applications.'], 403);
        }
        if ($appRequest->requested_by === $request->user()->id) {
            return response()->json(['message' => 'You cannot reject your own application — withdraw it instead.'], 422);
        }
        $data = $request->validate(['rejected_reason' => 'required|string|max:255']);

        DB::transaction(function () use ($appRequest, $loan, $request, $data) {
            $appRequest->lockPending();
            $appRequest->update([
                'status' => 'Rejected',
                'rejected_by' => $request->user()->id,
                'rejected_at' => now(),
                'reject_reason' => $data['rejected_reason'],
            ]);
            $loan->update([
                'status' => 'Rejected',
                'approved_by' => $request->user()->id,
                'approved_at' => now(),
                'rejected_reason' => $data['rejected_reason'],
            ]);
        });

        return $loan->fresh()->load(self::DETAIL_RELATIONS);
    }

    /** Submits a request to cancel a loan — either Approved-but-not-yet-
     *  disbursed (distinct from reject(), which only applies to Pending
     *  applications), or Active with zero payments collected so far (a bad/
     *  mistaken disbursement). Doesn't cancel anything itself: it only takes
     *  effect once approveCancelRequest() has collected enough distinct
     *  approvals (approval_types.required_approvals for code 'loan_cancel',
     *  snapshotted here) — see that method for what actually gets reversed. */
    public function cancelRequest(Request $request, Loan $loan)
    {
        if (!in_array($loan->status, ['Approved', 'Active'], true)) {
            return response()->json(['message' => 'Only approved (not yet disbursed) or freshly disbursed (no payments yet) loans can be cancelled.'], 422);
        }
        if ($loan->status === 'Active' && $loan->hasCollectionHistory()) {
            return response()->json(['message' => 'This loan already has payments recorded — cancel is only for loans with no collection history yet. Use Contract Status Change instead.'], 422);
        }
        if ($loan->cancelRequests()->where('status', 'PendingApproval')->exists()) {
            return response()->json(['message' => 'A cancel request is already pending approval for this loan.'], 422);
        }
        // An undisbursed loan holding a paid Document charge would be cancelled with the customer's cash kept - void that receipt first.
        if ($loan->status === 'Approved' && $loan->payments()->reorder()->where(fn ($q) => $q->whereNull('status')->orWhere('status', '!=', 'Voided'))->exists()) {
            return response()->json(['message' => 'This loan has a payment recorded (the document charge). Void that receipt first, then cancel the loan.'], 422);
        }
        $data = $request->validate(['cancelled_reason' => 'required|string|max:255']);
        $type = ApprovalType::where('code', 'loan_cancel')->firstOrFail();

        $cancelRequest = ApprovalRequest::create([
            'approval_type_id' => $type->id,
            'subject_id' => $loan->id,
            'requested_by' => $request->user()->id,
            'requested_at' => now(),
            'reason' => $data['cancelled_reason'],
            'required_approvals' => $type->required_approvals,
        ]);

        return response()->json($cancelRequest->load('requester:id,name'), 201);
    }

    public function approveCancelRequest(Request $request, ApprovalRequest $cancelRequest)
    {
        if ($cancelRequest->approvalType->code !== 'loan_cancel') {
            abort(404);
        }
        if (!ApprovalType::isAuthorized($request->user(), 'loan_cancel')) {
            return response()->json(['message' => 'You are not an approver for loan cancellations.'], 403);
        }
        if ($cancelRequest->status !== 'PendingApproval') {
            return response()->json(['message' => 'This request is not pending approval.'], 422);
        }
        if ($cancelRequest->requested_by === $request->user()->id) {
            return response()->json(['message' => 'You cannot approve your own cancel request.'], 422);
        }
        if ($cancelRequest->hasApproved($request->user()->id)) {
            return response()->json(['message' => 'You have already approved this request.'], 422);
        }
        $loan = Loan::findOrFail($cancelRequest->subject_id);
        // Re-check nothing changed since the request was submitted — a payment
        // could have been recorded on this loan in the meantime.
        if ($loan->status === 'Active' && $loan->hasCollectionHistory()) {
            $cancelRequest->update(['status' => 'Rejected', 'rejected_by' => $request->user()->id, 'rejected_at' => now(), 'reject_reason' => 'Payments were recorded on this loan after the request was submitted.']);

            return response()->json(['message' => 'This loan now has payments recorded — the request has been auto-rejected. Use Contract Status Change instead.'], 422);
        }

        DB::transaction(function () use ($cancelRequest, $loan, $request) {
            $cancelRequest->lockPending();
            // Lock the loan too (payment recording does) and re-check: a payment may have landed since the pre-check.
            \App\Models\Loan::whereKey($loan->id)->lockForUpdate()->first();
            $loan->refresh();
            if ($loan->status === 'Active' && $loan->hasCollectionHistory()) {
                throw \Illuminate\Validation\ValidationException::withMessages(['loan' => 'This loan now has payments recorded - use Contract Status Change instead.']);
            }
            if (!$cancelRequest->first_approved_by) {
                $cancelRequest->update(['first_approved_by' => $request->user()->id, 'first_approved_at' => now()]);
            } else {
                $cancelRequest->update(['second_approved_by' => $request->user()->id, 'second_approved_at' => now()]);
            }

            if ($cancelRequest->fresh()->approvalsCount() < $cancelRequest->required_approvals) {
                return;
            }

            if ($loan->status === 'Active') {
                $loan->schedules()->delete();
                $loan->salesExecutiveCommissions()->delete();
                LoanLedgerPoster::reverseDisbursement($loan, $request->user()->id);
                $loan->update(['disbursed_amount' => null, 'disbursed_at' => null, 'outstanding_balance_cache' => 0]);
            }
            $loan->update([
                'status' => 'Cancelled',
                'cancelled_by' => $request->user()->id,
                'cancelled_at' => now(),
                'cancelled_reason' => $cancelRequest->reason,
            ]);
            $cancelRequest->update(['status' => 'Approved']);
        });

        return $cancelRequest->fresh()->load('requester:id,name', 'firstApprover:id,name', 'secondApprover:id,name');
    }

    public function rejectCancelRequest(Request $request, ApprovalRequest $cancelRequest)
    {
        if ($cancelRequest->approvalType->code !== 'loan_cancel') {
            abort(404);
        }
        if (!ApprovalType::isAuthorized($request->user(), 'loan_cancel')) {
            return response()->json(['message' => 'You are not an approver for loan cancellations.'], 403);
        }
        if ($cancelRequest->status !== 'PendingApproval') {
            return response()->json(['message' => 'This request is not pending approval.'], 422);
        }
        $data = $request->validate(['reject_reason' => 'nullable|string|max:255']);
        $cancelRequest->update([
            'status' => 'Rejected',
            'rejected_by' => $request->user()->id,
            'rejected_at' => now(),
            'reject_reason' => $data['reject_reason'] ?? null,
        ]);

        return $cancelRequest->fresh()->load('requester:id,name', 'rejecter:id,name');
    }

    /** Shared validation rules for the disburse / request-disbursement forms. */
    private const DISBURSE_RULES = [
        'disbursed_amount' => 'nullable|numeric|gt:0',
        'disbursed_at' => 'nullable|date',
        'first_installment_date' => 'required|date',
        'disbursement_method' => ['required', 'in:Cash,Bank,Cheque'],
        // The account the money leaves from — required for Bank/Cheque.
        'disbursement_bank_account_id' => ['required_if:disbursement_method,Bank,Cheque', 'nullable', 'exists:bank_accounts,id'],
        // Cash only: which till pays it out. Omitted = the branch's disbursement till.
        'cash_account_id' => ['nullable', 'exists:cash_accounts,id'],
    ];

    /**
     * Loans are paid out only from a Head Office bank account: no cash, and no other branch's account. Whatever
     * branch owns the loan, the ledger settles it with Head Office through the inter-branch accounts.
     */
    private function headOfficeBankError(array $data): ?string
    {
        if (!config('finance.disburse_from_head_office_bank', true)) {
            return null;
        }
        if (($data['disbursement_method'] ?? null) === 'Cash') {
            return 'Loans are paid out from the Head Office bank account. Choose Bank or Cheque and pick that account.';
        }
        $bank = \App\Models\BankAccount::withoutGlobalScopes()->find($data['disbursement_bank_account_id'] ?? 0);
        $isHo = $bank && \App\Models\Branch::whereKey($bank->branch_id)->where('is_head_office', true)->exists();

        return $isHo ? null : 'Pick a Head Office bank account. Loans are paid out only from Head Office.';
    }

    /** Sanity checks on the disburse form values - the amount can't exceed what was approved and the first installment can't fall before the money goes out. */
    private function disbursementInputError(Loan $loan, array $data): ?string
    {
        $max = round((float) $loan->principal_amount - (float) ($loan->loanDetail?->down_payment ?? 0), 2);
        if (isset($data['disbursed_amount']) && round((float) $data['disbursed_amount'], 2) > $max) {
            return 'The disbursed amount (' . number_format((float) $data['disbursed_amount'], 2) . ') cannot exceed the approved financed amount (' . number_format($max, 2) . ').';
        }
        $disbursedOn = Carbon::parse($data['disbursed_at'] ?? now())->startOfDay();
        if (Carbon::parse($data['first_installment_date'])->startOfDay()->lt($disbursedOn)) {
            return 'The first installment date cannot be before the disbursed date.';
        }

        return null;
    }

    /** Why this loan can't be disbursed yet — status, unsettled document charge, or a disbursement request already in flight. Null when it's clear. */
    private function disbursementBlockReason(Loan $loan, ?float $financed = null): ?string
    {
        if ($loan->status !== 'Approved' && !$loan->disbursementIncomplete()) {
            return 'Only approved loans can be disbursed.';
        }
        $detail = $loan->loanDetail()->with('loanType')->first();
        $loanType = $detail?->loanType;
        $documentCharges = $loan->charges()->whereHas('category', fn ($q) => $q->where('name', 'Document'))->get();
        if ($loanType && (float) $loanType->processing_fee_percent > 0 && $documentCharges->isEmpty()) {
            return 'Add the document charge before disbursing this loan.';
        }
        if ($documentCharges->sum(fn ($c) => max(0, (float) $c->due_amount - (float) $c->paid_amount)) > 0) {
            return 'Settle the document charge before disbursing this loan.';
        }
        // The charge must cover the processing fee on what is actually being paid out - a hand-keyed
        // 1.00 (or a charge raised before the disbursed amount was changed) must not unlock the loan.
        if ($loanType && (float) $loanType->processing_fee_percent > 0) {
            $financed ??= (float) $loan->principal_amount - (float) $loan->loanDetail->down_payment;
            // An amount keyed in on the application overrides the computed fee.
            $expected = $detail->document_charge_amount !== null
                ? round((float) $detail->document_charge_amount, 2)
                : round($financed * (float) $loanType->processing_fee_percent / 100, 2);
            $paid = (float) $documentCharges->sum('due_amount');
            if ($paid + 0.005 < $expected) {
                return 'The document charge (' . number_format($paid, 2) . ') is less than the ' . rtrim(rtrim(number_format((float) $loanType->processing_fee_percent, 3), '0'), '.') . '% processing fee on the disbursed amount (' . number_format($expected, 2) . '). Correct the charge before disbursing.';
            }
        }
        if ($loan->pendingDisbursementRequest()->exists()) {
            return 'A disbursement request for this loan is already awaiting finance approval or payment.';
        }

        return null;
    }

    /** Sets (or changes) the customer bank account the loan is paid to - allowed until the money has gone out. */
    public function setPayoutAccount(Request $request, Loan $loan)
    {
        $user = $request->user();
        abort_unless(
            \App\Support\Permissions::userCan($user, 'Loan Disbursement', 'Create')
            || \App\Support\Permissions::userCan($user, 'Loan Disbursement', 'Approve')
            || \App\Support\Permissions::userCan($user, 'Loan Applications', 'Edit'),
            403,
            "You don't have permission to change the loan's payout account."
        );
        if ($loan->status !== 'Approved' && !$loan->disbursementIncomplete()) {
            return response()->json(['message' => 'The payout account can only be changed before the loan is disbursed.'], 422);
        }
        if ($loan->pendingDisbursementRequest()->exists()) {
            return response()->json(['message' => 'A disbursement request is already with Finance for this loan.'], 422);
        }
        $data = $request->validate(['customer_bank_detail_id' => 'required|exists:customer_bank_details,id']);
        \App\Models\CustomerBankDetail::assertBelongsTo((int) $data['customer_bank_detail_id'], (int) $loan->customer_id);
        $loan->update(['customer_bank_detail_id' => $data['customer_bank_detail_id']]);

        return $loan->load(self::DETAIL_RELATIONS);
    }

    /**
     * The direct disburse path (Finance / admin, perm Active Loans,Approve):
     * finishes the loan side and releases the money in one call. Loan officers
     * use requestDisbursement() instead — see the D2 decision in the Finance
     * module plan.
     */
    public function disburse(Request $request, Loan $loan)
    {
        $data = $request->validate(array_merge(self::DISBURSE_RULES, ['disbursed_amount' => ['nullable', 'numeric', 'gt:0', new \App\Rules\Money]]));
        if ($invalid = $this->disbursementInputError($loan, $data) ?? $this->headOfficeBankError($data)) {
            return response()->json(['message' => $invalid], 422);
        }
        if ($block = $this->disbursementBlockReason($loan, isset($data['disbursed_amount']) ? (float) $data['disbursed_amount'] : null)) {
            return response()->json(['message' => $block], 422);
        }

        $executedBy = $request->user()->id;
        DB::transaction(function () use ($loan, $data, $executedBy) {
            // Lock + re-check: two simultaneous disbursements must not both complete the loan.
            $loan->setRawAttributes(Loan::lockForUpdate()->findOrFail($loan->id)->getAttributes(), true);
            if ($loan->status !== 'Approved' && !$loan->disbursementIncomplete()) {
                throw \Illuminate\Validation\ValidationException::withMessages(['loan' => 'This loan has already been disbursed or is not approved.']);
            }
            // Finishing a disbursement that stopped halfway: put the loan back to Approved first, so it goes
            // through the normal path. The ledger posting is idempotent (one key per loan), so money never moves twice.
            if ($loan->disbursementIncomplete()) {
                \App\Models\SalesExecutiveCommission::where('credit_facility_id', $loan->id)->whereNull('paid_at')->delete();
                $loan->update(['status' => 'Approved', 'disbursed_amount' => null, 'disbursed_at' => null, 'outstanding_balance_cache' => 0]);
            }
            LoanDisbursementCompleter::complete($loan, $data);
            LoanLedgerPoster::postDisbursement($loan->fresh(), $executedBy, $data['disbursement_method'] === 'Cash' ? ($data['cash_account_id'] ?? null) : null);
        });

        return $loan->load(self::DETAIL_RELATIONS);
    }

    /**
     * The request path (a loan officer, perm Active Loans,Create): raises a
     * LoanDisbursement finance request instead of disbursing. Finance
     * approves + executes it (the money moves there), then the
     * FinanceRequestSettled listener runs LoanDisbursementCompleter to finish
     * the loan. The disbursement params are snapshotted onto the request meta.
     */
    public function requestDisbursement(Request $request, Loan $loan)
    {
        $data = $request->validate(array_merge(self::DISBURSE_RULES, ['disbursed_amount' => ['nullable', 'numeric', 'gt:0', new \App\Rules\Money]]));
        if ($invalid = $this->disbursementInputError($loan, $data) ?? $this->headOfficeBankError($data)) {
            return response()->json(['message' => $invalid], 422);
        }
        if ($block = $this->disbursementBlockReason($loan, isset($data['disbursed_amount']) ? (float) $data['disbursed_amount'] : null)) {
            return response()->json(['message' => $block], 422);
        }

        $detail = $loan->loanDetail;
        $financed = $data['disbursed_amount'] ?? ((float) $loan->principal_amount - (float) $detail->down_payment);
        $bankAccountId = $data['disbursement_method'] === 'Cash' ? null : ($data['disbursement_bank_account_id'] ?? null);

        $financeRequest = FinanceRequestService::open([
            'request_type' => 'LoanDisbursement',
            'amount' => $financed,
            'branch_id' => $loan->branch_id,
            'source_module' => 'Loan',
            'source_id' => $loan->id,
            'payee_type' => 'Customer',
            'payee_id' => $loan->customer_id,
            'payee_name' => $loan->customer?->full_name,
            'preferred_method' => $data['disbursement_method'] === 'Cheque' ? 'Cheque' : $data['disbursement_method'],
            'purpose' => "Loan disbursement - {$loan->code}",
            'requested_by' => $request->user()->id,
            'meta' => [
                'gl_source_type' => 'Loan',
                'gl_source_id' => $loan->id,
                'offset_account_id' => ChartOfAccount::system('1100')->id,
                'first_installment_date' => $data['first_installment_date'],
                'disbursed_amount' => $financed,
                'disbursed_at' => $data['disbursed_at'] ?? now()->toDateString(),
                'disbursement_method' => $data['disbursement_method'],
                'disbursement_bank_account_id' => $bankAccountId,
                'source_bank_account_id' => $bankAccountId,
            ],
        ]);

        return response()->json([
            'message' => 'Disbursement request sent to Finance.',
            'financeRequest' => $financeRequest->load('branch:id,name'),
        ], 201);
    }

    /** Common guard for both writeOffRequest() and approveWriteOffRequest():
     *  the loan must still be write-off-able. Returns an error message string,
     *  or null when it's clear to proceed. */
    private function writeOffBlockReason(Loan $loan): ?string
    {
        if (!in_array($loan->status, ['Active', 'Lapsed'], true)) {
            return 'Only an active or lapsed loan can be written off.';
        }
        // NPL loans must be fully provisioned before write-off — see
        // LoanProvisioning and LoanLedgerPoster::postWriteOff(), which
        // relieves the posted provision first rather than expensing the
        // whole amount fresh. A loan that never reached the 100% slab (e.g.
        // written off early for another reason) isn't blocked here.
        if ((int) $loan->provision_required_pct === 100 && (float) $loan->provision_posted_amount < (float) $loan->provision_required_amount - 0.01) {
            return 'Post the full impairment provision before writing off this loan.';
        }

        return null;
    }

    /**
     * Submits a request to write a loan off as bad debt — doesn't write it off
     * itself. Maker-checker, exactly like cancelRequest(): a row in the shared
     * approval_requests table (code 'loan_write_off') that only takes effect
     * once approveWriteOffRequest() has collected enough distinct approvals
     * (approval_types.required_approvals, snapshotted here). See
     * approveWriteOffRequest() for the actual ledger posting / status change.
     */
    public function writeOffRequest(Request $request, Loan $loan)
    {
        if ($block = $this->writeOffBlockReason($loan)) {
            return response()->json(['message' => $block], 422);
        }
        if ($loan->writeOffRequests()->where('status', 'PendingApproval')->exists()) {
            return response()->json(['message' => 'A write-off request is already pending approval for this loan.'], 422);
        }
        $data = $request->validate(['reason' => 'required|string|max:255']);
        $type = ApprovalType::where('code', 'loan_write_off')->firstOrFail();

        $writeOffRequest = ApprovalRequest::create([
            'approval_type_id' => $type->id,
            'subject_id' => $loan->id,
            'requested_by' => $request->user()->id,
            'requested_at' => now(),
            'reason' => $data['reason'],
            'required_approvals' => $type->required_approvals,
        ]);

        return response()->json($writeOffRequest->load('requester:id,name'), 201);
    }

    /** Applies the write-off once enough distinct approvals are in — mirrors
     *  approveCancelRequest(). Direct-write-off (Dr 5300 expense / relieve
     *  provision, Cr 1100), the amortized schedule is kept as history (same as
     *  a Closed loan); only the outstanding balance and status change. */
    public function approveWriteOffRequest(Request $request, ApprovalRequest $writeOffRequest)
    {
        if ($writeOffRequest->approvalType->code !== 'loan_write_off') {
            abort(404);
        }
        if (!ApprovalType::isAuthorized($request->user(), 'loan_write_off')) {
            return response()->json(['message' => 'You are not an approver for loan write-offs.'], 403);
        }
        if ($writeOffRequest->status !== 'PendingApproval') {
            return response()->json(['message' => 'This request is not pending approval.'], 422);
        }
        if ($writeOffRequest->requested_by === $request->user()->id) {
            return response()->json(['message' => 'You cannot approve your own write-off request.'], 422);
        }
        if ($writeOffRequest->hasApproved($request->user()->id)) {
            return response()->json(['message' => 'You have already approved this request.'], 422);
        }
        $loan = Loan::findOrFail($writeOffRequest->subject_id);
        // Re-check nothing changed since the request was submitted — the loan
        // could have been closed, or slipped into the 100% provision slab
        // without the provision being posted, in the meantime.
        if ($block = $this->writeOffBlockReason($loan)) {
            $writeOffRequest->update(['status' => 'Rejected', 'rejected_by' => $request->user()->id, 'rejected_at' => now(), 'reject_reason' => $block]);

            return response()->json(['message' => $block . ' The request has been auto-rejected.'], 422);
        }

        DB::transaction(function () use ($writeOffRequest, $loan, $request) {
            $writeOffRequest->lockPending();
            \App\Models\Loan::whereKey($loan->id)->lockForUpdate()->first();
            $loan->refresh();
            if (!$writeOffRequest->first_approved_by) {
                $writeOffRequest->update(['first_approved_by' => $request->user()->id, 'first_approved_at' => now()]);
            } else {
                $writeOffRequest->update(['second_approved_by' => $request->user()->id, 'second_approved_at' => now()]);
            }

            if ($writeOffRequest->fresh()->approvalsCount() < $writeOffRequest->required_approvals) {
                return;
            }

            $amount = (float) $loan->outstanding_balance_cache;
            LoanLedgerPoster::postWriteOff($loan, $amount, $request->user()->id);
            $loan->update([
                'status' => 'WrittenOff',
                'outstanding_balance_cache' => 0,
                'closed_at' => now(),
                'written_off_reason' => $writeOffRequest->reason,
                'written_off_by' => $writeOffRequest->requested_by,
                'npl_flag' => false,
                'provision_required_pct' => 0,
                'provision_required_amount' => 0,
                'provision_posted_amount' => 0,
            ]);
            $writeOffRequest->update(['status' => 'Approved']);
        });

        return $writeOffRequest->fresh()->load('requester:id,name', 'firstApprover:id,name', 'secondApprover:id,name');
    }

    public function rejectWriteOffRequest(Request $request, ApprovalRequest $writeOffRequest)
    {
        if ($writeOffRequest->approvalType->code !== 'loan_write_off') {
            abort(404);
        }
        if (!ApprovalType::isAuthorized($request->user(), 'loan_write_off')) {
            return response()->json(['message' => 'You are not an approver for loan write-offs.'], 403);
        }
        if ($writeOffRequest->status !== 'PendingApproval') {
            return response()->json(['message' => 'This request is not pending approval.'], 422);
        }
        $data = $request->validate(['reject_reason' => 'nullable|string|max:255']);
        $writeOffRequest->update([
            'status' => 'Rejected',
            'rejected_by' => $request->user()->id,
            'rejected_at' => now(),
            'reject_reason' => $data['reject_reason'] ?? null,
        ]);

        return $writeOffRequest->fresh()->load('requester:id,name', 'rejecter:id,name');
    }

    /**
     * Reschedule (restructure) an Active loan onto a fresh contract — the
     * finance-department action for a borrower who can't service the loan as
     * it stands. The old loan is closed as 'Restructured' (its schedule /
     * receipts kept as history) and a NEW linked loan is created, its code
     * the original with an -R1 / -R2 … suffix.
     *
     * New principal = remaining capital
     *               + capitalised interest (the EARNED / overdue slice of the
     *                 unpaid interest, less any extra the user chooses to waive)
     *               + retained default charges (OD), if not waived
     *               + outstanding other charges (Document/Legal/…)
     *               + any manually added charge lines
     * A new interest rate + term are entered (the rate is usually raised, the
     * same override already allowed on a normal application) and the new
     * amount is re-amortised via the standard LoanAmortizer.
     *
     * Accounting policy: this ledger recognises interest income only as it is
     * collected (see LoanLedgerPoster). A reschedule keeps to that — the only
     * interest capitalised and recognised here is the slice already EARNED
     * (interest on installments already past due, which the customer was
     * already contractually due to pay); the unearned future-interest portion
     * of the old schedule is written off, exactly like any interest waiver in
     * this system (no GL entry — it was never booked). OD penalties and unpaid
     * charges are likewise already-earned amounts, so retaining them is a
     * catch-up recognition, not new income. No cash moves, so there is no
     * disbursement entry — the old loan's capital simply carries in 1100.
     * See LoanLedgerPoster::postRescheduleRollover().
     *
     * Admin-only (routes/api.php) — no approval workflow, same as the other
     * Loan Adjustments actions.
     */
    public function reschedule(Request $request, Loan $loan)
    {
        if (!in_array($loan->status, ['Active', 'Lapsed'], true)) {
            return response()->json(['message' => 'Only an active or lapsed loan can be rescheduled.'], 422);
        }
        // Everything below is quoted from this snapshot; the transaction refuses if a payment lands before it commits.
        $paymentMark = \App\Models\FacilityPayment::withoutGlobalScopes()->where('credit_facility_id', $loan->id)->max('id');
        if ($loan->payments()->whereIn('payment_type', ['Settlement', 'ManualAllocation'])->where('approval_status', 'PendingApproval')->exists()) {
            return response()->json(['message' => 'This loan has a settlement or manual allocation still awaiting approval — resolve it first.'], 422);
        }
        if ($loan->payments()->whereHas('voidRequests', fn ($q) => $q->where('status', 'PendingApproval'))->exists()) {
            return response()->json(['message' => 'This loan has a payment void request still awaiting approval — resolve it first.'], 422);
        }
        if ($loan->payments()->where('payment_method', 'Cheque')->where('clearance_status', 'Pending')->exists()
            || $loan->postDatedCheques()->whereIn('status', ['Pending', 'Handed'])->exists()) {
            return response()->json(['message' => 'This loan has an unrealised cheque — realize or bounce it before rescheduling.'], 422);
        }

        $data = $request->validate([
            'as_of_date' => 'nullable|date',
            // An EXTRA write-off of earned interest, on top of the automatic
            // write-off of the unearned future-interest portion. A % is of the
            // whole unpaid interest figure shown to the user.
            'interest_deduction_type' => ['required', Rule::in(['percent', 'amount'])],
            'interest_deduction_value' => 'required|numeric|min:0',
            'default_charges_action' => ['required', Rule::in(['retain', 'waive'])],
            'charges' => 'array',
            'charges.*.charge_category_id' => 'required|exists:charge_categories,id',
            'charges.*.amount' => 'required|numeric|min:0.01',
            'charges.*.notes' => 'nullable|string|max:255',
            'interest_rate_percent' => 'required|numeric|min:0|max:999',
            'interest_method' => ['required', Rule::in(['flat', 'reducing_balance'])],
            'term_months' => 'required|integer|min:1',
            'installment_frequency' => ['required', Rule::in(self::FREQUENCIES)],
            'first_installment_date' => 'required|date',
            'loan_officer_id' => 'nullable|exists:users,id',
            'recovery_officer_id' => 'nullable|exists:users,id',
            'reason' => 'nullable|string|max:255',
        ]);

        $asOf = $data['as_of_date'] ?? now()->toDateString();
        $oldDetail = $loan->loanDetail()->with('loanType')->firstOrFail();
        $loanType = $oldDetail->loanType;

        $asOfC = Carbon::parse($asOf);
        $bucket = LoanPaymentAllocator::outstandingByBucket($loan, $asOfC);
        $capital = round((float) $bucket['capital'], 2);
        $unpaidInterest = round((float) $bucket['interest'], 2);
        $odCharges = round((float) $bucket['od_charge'], 2);
        $otherCharges = round((float) $bucket['other_charges'], 2);
        // The actual charge balances that get rolled forward and marked settled on
        // the old loan — drives both the new principal and the GL line, so the two
        // can't disagree (the bucket figure above nets Charge-type waivers and is
        // kept only for the audit snapshot).
        $otherChargesRolled = round((float) $loan->charges->sum(fn ($c) => max(0, (float) $c->due_amount - (float) $c->paid_amount)), 2);

        // Earned interest = interest on installments already past due (net of what
        // was collected against them). Only this may be capitalised + recognised;
        // the rest of the unpaid interest is unearned and gets written off.
        $earnedInterest = round((float) $loan->schedules()
            ->where('status', '!=', 'Paid')
            ->where('due_date', '<', $asOfC->toDateString())
            ->with('allocations.payment:id,status,approval_status')
            ->get()
            ->sum(function ($s) {
                $paid = (float) $s->allocations
                    ->filter(fn ($a) => $a->payment && $a->payment->status !== 'Voided' && $a->payment->approval_status !== 'PendingApproval')
                    ->sum('interest_applied');

                return max(0, (float) $s->interest_due - $paid);
            }), 2);
        $earnedInterest = min($earnedInterest, $unpaidInterest);

        $deduction = $data['interest_deduction_type'] === 'percent'
            ? round($unpaidInterest * (float) $data['interest_deduction_value'] / 100, 2)
            : round((float) $data['interest_deduction_value'], 2);
        $deduction = min(max(0.0, $deduction), $unpaidInterest);
        // Capitalise the earned slice, less any extra the user waived — never more.
        $capitalisedInterest = round(max(0.0, min($unpaidInterest - $deduction, $earnedInterest)), 2);
        $interestWrittenOff = round($unpaidInterest - $capitalisedInterest, 2);
        $retainedOd = $data['default_charges_action'] === 'retain' ? $odCharges : 0.0;

        $addedLines = collect($data['charges'] ?? [])->map(fn ($c) => [
            'charge_category_id' => (int) $c['charge_category_id'],
            'amount' => round((float) $c['amount'], 2),
            'notes' => $c['notes'] ?? null,
        ])->values();
        $addedTotal = round((float) $addedLines->sum('amount'), 2);

        $newPrincipal = round($capital + $capitalisedInterest + $retainedOd + $otherChargesRolled + $addedTotal, 2);
        if ($newPrincipal <= 0) {
            return response()->json(['message' => 'The rescheduled amount works out to zero or less — nothing to reschedule.'], 422);
        }

        $newLoan = DB::transaction(function () use (
            $request, $loan, $oldDetail, $loanType, $data, $asOf,
            $capital, $unpaidInterest, $earnedInterest, $odCharges, $otherCharges, $otherChargesRolled,
            $deduction, $capitalisedInterest, $interestWrittenOff, $retainedOd, $addedLines, $addedTotal, $newPrincipal, $paymentMark
        ) {
            $uid = $request->user()->id;
            Loan::whereKey($loan->id)->lockForUpdate()->first();
            if (!in_array($loan->fresh()->status, ['Active', 'Lapsed'], true)
                || \App\Models\FacilityPayment::withoutGlobalScopes()->where('credit_facility_id', $loan->id)->max('id') !== $paymentMark) {
                throw \Illuminate\Validation\ValidationException::withMessages(['loan' => 'This loan changed while the reschedule was being prepared (a payment was recorded or its status changed). Reload and try again.']);
            }

            // -R1, then -R2 on a subsequent reschedule of the chain, ...
            $n = preg_match('/^(.*)-R(\d+)$/', $loan->code, $m) ? ((int) $m[2] + 1) : 1;
            $base = isset($m[1]) ? $m[1] : $loan->code;
            $newCode = "{$base}-R{$n}";

            $newLoan = Loan::create([
                'branch_id' => $loan->branch_id,
                'code' => $newCode,
                'customer_id' => $loan->customer_id,
                'customer_bank_detail_id' => $loan->customer_bank_detail_id,
                'principal_amount' => $newPrincipal,
                'interest_rate_percent' => $data['interest_rate_percent'],
                'rate_period' => $loanType->rate_period,
                'term_months' => $data['term_months'],
                'start_date' => $asOf,
                'disbursed_amount' => $newPrincipal,
                'disbursed_at' => $asOf,
                'outstanding_balance_cache' => 0,
                'status' => 'Active',
                'applied_by' => $uid,
                'created_by' => $uid,
                'approved_by' => $uid,
                'approved_at' => now(),
                'restructured_from_id' => $loan->id,
            ]);
            NumberGenerator::attach($newCode, $newLoan);

            $newLoan->loanDetail()->create([
                'loan_type_id' => $oldDetail->loan_type_id,
                'vehicle_no' => $oldDetail->vehicle_no,
                'down_payment' => 0,
                'disbursement_method' => $oldDetail->disbursement_method,
                'disbursement_bank_account_id' => $oldDetail->disbursement_bank_account_id,
                'interest_method' => $data['interest_method'],
                'installment_frequency' => $data['installment_frequency'],
                'purpose' => 'Reschedule of ' . $loan->code,
                'application_date' => $asOf,
                'reference_name' => $oldDetail->reference_name,
                'reference_id_no' => $oldDetail->reference_id_no,
                'reference_address' => $oldDetail->reference_address,
                'loan_officer_id' => $data['loan_officer_id'] ?? $oldDetail->loan_officer_id,
                'recovery_officer_id' => $data['recovery_officer_id'] ?? $oldDetail->recovery_officer_id,
                'first_installment_date' => $data['first_installment_date'],
            ]);

            // Carry the security/parties across unchanged.
            foreach ($loan->guarantors as $g) {
                $newLoan->guarantors()->create($g->only(['guarantor_customer_id', 'guaranteed_amount']));
            }
            foreach ($loan->coApplicants as $ca) {
                $newLoan->coApplicants()->create($ca->only(['co_applicant_customer_id', 'share_percent']));
            }
            foreach ($loan->collateral()->where('collateral_type', '!=', 'FixedDeposit')->with(['vehicleDetail', 'jewelryDetail'])->get() as $c) {
                $row = $newLoan->collateral()->create($c->only(['collateral_type', 'collateral_category', 'description', 'estimated_value']));
                if ($c->vehicleDetail) {
                    $row->vehicleDetail()->create($c->vehicleDetail->only([
                        'vehicle_type', 'make_model', 'registration_no', 'chassis_no', 'engine_no', 'year_of_manufacture',
                    ]));
                }
                if ($c->jewelryDetail) {
                    $row->jewelryDetail()->create($c->jewelryDetail->only(['weight_grams', 'purity', 'appraised_value']));
                }
            }
            // An FD lien follows the loan: the FD stays pledged to the replacement.
            FdLienService::transfer($loan, $newLoan);
            if ($loan->insurance) {
                $newLoan->insurance()->create($loan->insurance->only([
                    'insurance_company', 'policy_no', 'amount', 'contact_person', 'coverage', 'remarks',
                ]));
            }

            // Fresh schedule at the new rate/term via the standard amortizer.
            $newDetail = $newLoan->loanDetail()->with('loanType')->first();
            $rows = LoanAmortizer::generate($newLoan->fresh(), $newDetail, $data['first_installment_date']);
            $newLoan->schedules()->insert($rows);
            LoanDisbursementCompleter::applyEffectiveRate($newDetail->fresh(), $rows);

            // Manually added charge lines — rolled into the new principal (above)
            // AND recorded as real charges on the new loan so finance can still
            // see the itemised breakdown. paid_amount = due_amount because their
            // value is already financed into the schedule, not collected on top.
            foreach ($addedLines as $line) {
                $newLoan->charges()->create([
                    'branch_id' => $newLoan->branch_id,
                    'charge_category_id' => $line['charge_category_id'],
                    'due_amount' => $line['amount'],
                    'paid_amount' => $line['amount'],
                    'charged_at' => $asOf,
                    'notes' => trim('Financed into reschedule ' . $newCode . ($line['notes'] ? ' — ' . $line['notes'] : '')),
                    'created_by' => $uid,
                ]);
            }

            // The old loan's still-unpaid other charges ($otherChargesRolled,
            // computed above) have been rolled into the new principal — settle
            // them on the old loan so it nets to zero and they aren't counted twice.
            foreach ($loan->charges as $charge) {
                if (round((float) $charge->due_amount - (float) $charge->paid_amount, 2) <= 0) {
                    continue;
                }
                $charge->update([
                    'paid_amount' => $charge->due_amount,
                    'notes' => trim(($charge->notes ? $charge->notes . ' | ' : '') . 'Rolled into reschedule ' . $newCode),
                ]);
            }

            LoanLedgerPoster::postRescheduleRollover(
                $loan, $newLoan->fresh(), $capitalisedInterest, $retainedOd,
                round($otherChargesRolled + $addedTotal, 2), $asOf, $uid
            );

            // Release any impairment provision posted against the old loan — it
            // is no longer an Active NPL (mirrors writeOff()).
            if ((float) $loan->provision_posted_amount > 0.01) {
                LoanLedgerPoster::postProvisionAdjustment($loan, -(float) $loan->provision_posted_amount, $uid);
            }

            $loan->update([
                'status' => 'Restructured',
                'outstanding_balance_cache' => 0,
                'closed_at' => now(),
                'npl_flag' => false,
                'provision_required_pct' => 0,
                'provision_required_amount' => 0,
                'provision_posted_amount' => 0,
            ]);

            $newLoan->update([
                'outstanding_balance_cache' => LoanPaymentAllocator::outstandingBalance($newLoan->fresh()),
            ]);

            FacilityReschedule::create([
                'credit_facility_id' => $loan->id,
                'new_credit_facility_id' => $newLoan->id,
                'branch_id' => $loan->branch_id,
                'as_of_date' => $asOf,
                'capital_outstanding' => $capital,
                'unpaid_interest' => $unpaidInterest,
                'overdue_interest' => $earnedInterest,
                'default_charges' => $odCharges,
                'other_charges' => $otherCharges,
                'interest_deduction' => $deduction,
                'capitalised_interest' => $capitalisedInterest,
                'interest_written_off' => $interestWrittenOff,
                'default_charges_action' => $data['default_charges_action'],
                'retained_default_charges' => $retainedOd,
                'other_charges_rolled' => $otherChargesRolled,
                'added_charges_total' => $addedTotal,
                'added_charges' => $addedLines->all() ?: null,
                'new_principal' => $newPrincipal,
                'new_interest_rate_percent' => $data['interest_rate_percent'],
                'new_term_months' => $data['term_months'],
                'new_interest_method' => $data['interest_method'],
                'new_installment_frequency' => $data['installment_frequency'],
                'reason' => $data['reason'] ?? null,
                'created_by' => $uid,
            ]);

            // Sales-executive commission is NOT restarted — a reschedule is a
            // workout, not new business.

            return $newLoan;
        });

        return $this->show($newLoan->fresh());
    }

    /**
     * Manual close for a loan that's actually fully settled but didn't
     * auto-close (LoanPaymentAllocator::finalizeApplied fires only when
     * outstandingBalance() — a per-installment, per-bucket figure — nets to
     * exactly 0; an early settlement that overpays one installment's interest
     * while underpaying its principal by the same amount can leave that
     * schedule row's own bucket split unbalanced even though every due-type
     * total across the loan already nets to 0). Eligibility here is checked
     * the same way the Due Type Breakdown card sums to zero on screen — total
     * due minus total paid, across all rows — rather than repeating the
     * stricter per-row check that's why the loan is stuck Active.
     */
    public function close(Request $request, Loan $loan)
    {
        if (!in_array($loan->status, ['Active', 'Lapsed'], true)) {
            return response()->json(['message' => 'Only an active or lapsed loan can be closed.'], 422);
        }
        $loan->load(['schedules.allocations.payment:id,status,approval_status', 'charges', 'waivers']);
        $balance = collect(LoanSummary::build($loan)['dueBreakdown'])->sum('balance');
        if (round($balance, 2) > 0.01) {
            return response()->json(['message' => 'This loan still has an outstanding balance and cannot be closed.'], 422);
        }

        $loan->update(['status' => 'Closed', 'outstanding_balance_cache' => 0, 'closed_at' => now()]);

        return $loan->load(self::DETAIL_RELATIONS);
    }

    /** Flat Rate loans only: back-solves the equivalent reducing-balance rate implied
     *  by the generated schedule's EMI and stores it as a monthly/annual effective
     *  rate (AerCalculator) — informational only, never affects the schedule itself.
     *  Deliberately swallows any failure (invalid inputs, non-convergence, an AER
     *  large enough to overflow the decimal(10,8) columns) so it can never turn a
     *  successful disbursement into a failed one. */
    /** Pushes an unpaid installment to the back of the schedule (due one period
     *  after the current last installment) instead of collecting it on time —
     *  the loan simply runs one period longer. */
    public function skipInstallment(Loan $loan, FacilitySchedule $schedule)
    {
        if ($schedule->credit_facility_id !== $loan->id) {
            abort(404);
        }
        if ($loan->status !== 'Active') {
            return response()->json(['message' => 'Only installments on an active loan can be skipped.'], 422);
        }
        if ($schedule->status === 'Paid' || (float) $schedule->paid_amount > 0) {
            return response()->json(['message' => 'A paid or partially paid installment cannot be skipped.'], 422);
        }
        $frequency = $loan->loanDetail->installment_frequency;

        DB::transaction(function () use ($loan, $schedule, $frequency) {
            Loan::whereKey($loan->id)->lockForUpdate()->first();
            $liveRow = FacilitySchedule::lockForUpdate()->findOrFail($schedule->id);
            if ($liveRow->status === 'Paid' || (float) $liveRow->paid_amount > 0) {
                throw \Illuminate\Validation\ValidationException::withMessages(['schedule' => 'A paid or partially paid installment cannot be skipped.']);
            }
            $schedule->setRawAttributes($liveRow->getAttributes(), true);
            $last = FacilitySchedule::where('credit_facility_id', $loan->id)->orderByDesc('due_date')->firstOrFail();
            $schedule->update([
                'due_date' => LoanAmortizer::nextDueDate($last->due_date, $frequency)->format('Y-m-d'),
                'status' => 'Pending',
            ]);

            // Renumber by due date so installment_no still reflects payment order.
            foreach ($loan->schedules()->orderBy('due_date')->orderBy('id')->get() as $i => $row) {
                if ($row->installment_no !== $i + 1) {
                    $row->update(['installment_no' => $i + 1]);
                }
            }
        });

        return $this->show($loan->fresh());
    }

    /** Distinct due dates with at least one skippable (unpaid, untouched)
     *  installment across this loan type's active loans — powers the date
     *  picklist on the Loan Adjustments > Installment Skip (bulk) screen. */
    public function skipDates(LoanType $loanType)
    {
        $rows = FacilitySchedule::query()
            ->whereHas('facility', fn ($q) => $q->where('status', 'Active')
                ->whereHas('loanDetail', fn ($q2) => $q2->where('loan_type_id', $loanType->id)))
            ->where('status', '!=', 'Paid')
            ->where('paid_amount', 0)
            ->selectRaw('due_date, COUNT(DISTINCT credit_facility_id) as loan_count, COUNT(*) as installment_count, SUM(total_due) as total_amount')
            ->groupBy('due_date')
            ->orderBy('due_date')
            ->get();

        return $rows->map(fn ($r) => [
            'dueDate' => $r->due_date->toDateString(),
            'loanCount' => (int) $r->loan_count,
            'installmentCount' => (int) $r->installment_count,
            'totalAmount' => round((float) $r->total_amount, 2),
        ])->values();
    }

    /**
     * Bulk version of skipInstallment() — for every active loan of this loan
     * type, skips (pushes to the back of that loan's own schedule) whichever
     * of its unpaid installments falls on one of the given dates. Each loan is
     * its own transaction/renumbering pass (same rule as the single-installment
     * version: paid or partially paid installments are left alone), so one
     * loan's ordering never affects another's, and a failure on one loan
     * doesn't block the rest.
     */
    public function bulkSkipInstallments(Request $request, LoanType $loanType)
    {
        $data = $request->validate([
            'dates' => 'required|array|min:1',
            'dates.*' => 'date',
        ]);
        $dates = collect($data['dates'])->map(fn ($d) => Carbon::parse($d)->toDateString())->unique()->values()->all();

        $loans = Loan::where('status', 'Active')
            ->whereHas('loanDetail', fn ($q) => $q->where('loan_type_id', $loanType->id))
            ->whereHas('schedules', fn ($q) => $q->whereIn('due_date', $dates)->where('status', '!=', 'Paid')->where('paid_amount', 0))
            ->with('loanDetail:credit_facility_id,installment_frequency')
            ->get();

        $loansAffected = 0;
        $installmentsSkipped = 0;
        $errors = [];

        foreach ($loans as $loan) {
            $frequency = $loan->loanDetail->installment_frequency;
            $rows = $loan->schedules()->whereIn('due_date', $dates)
                ->where('status', '!=', 'Paid')->where('paid_amount', 0)
                ->orderBy('due_date')->get();
            if ($rows->isEmpty()) {
                continue;
            }
            try {
                DB::transaction(function () use ($loan, $rows, $frequency) {
                    foreach ($rows as $schedule) {
                        // Re-queried fresh each pass (not the eager-loaded $rows/schedules
                        // collection) so a loan with two skippable dates in this batch
                        // correctly stacks the second skip behind the first.
                        $last = FacilitySchedule::where('credit_facility_id', $loan->id)->orderByDesc('due_date')->firstOrFail();
                        $schedule->update([
                            'due_date' => LoanAmortizer::nextDueDate($last->due_date, $frequency)->format('Y-m-d'),
                            'status' => 'Pending',
                        ]);
                    }
                    foreach ($loan->schedules()->orderBy('due_date')->orderBy('id')->get() as $i => $row) {
                        if ($row->installment_no !== $i + 1) {
                            $row->update(['installment_no' => $i + 1]);
                        }
                    }
                });
                $loansAffected++;
                $installmentsSkipped += $rows->count();
            } catch (\Throwable $e) {
                $errors[] = ['loanId' => $loan->id, 'loanCode' => $loan->code, 'message' => $e->getMessage()];
            }
        }

        return response()->json([
            'loansAffected' => $loansAffected,
            'installmentsSkipped' => $installmentsSkipped,
            'errors' => $errors,
        ]);
    }

    private function createCollateral(Loan $loan, array $c): void
    {
        $vehicle = $c['vehicle'] ?? null;
        unset($c['vehicle']);
        $row = $loan->collateral()->create($c);
        if ($row->collateral_type === 'Vehicle' && $vehicle) {
            $row->vehicleDetail()->create($vehicle);
        }
    }

    /** @return array{0: array, 1: array, 2: array, 3: array, 4: ?array, 5: array} [facility fields, loanDetail fields, guarantors, collateral, insurance, coApplicants] */
    private function validated(Request $request, ?Loan $loan = null): array
    {
        $validated = $request->validate([
            'branch_id' => 'required|exists:branches,id',
            'customer_id' => 'required|exists:customers,id',
            'customer_bank_detail_id' => 'required|exists:customer_bank_details,id',
            'loan_type_id' => 'required|exists:loan_types,id',
            'principal_amount' => ['required', 'numeric', 'min:0', new \App\Rules\Money],
            'down_payment' => ['nullable', 'numeric', 'min:0', new \App\Rules\Money],
            'document_charge_amount' => ['nullable', 'numeric', 'min:0', new \App\Rules\Money],
            'interest_rate_percent' => 'nullable|numeric|min:0|max:999',
            'term_months' => 'required|integer|min:1',
            'installment_frequency' => ['required', Rule::in(self::FREQUENCIES)],
            'purpose' => 'nullable|string|max:255',
            'application_date' => 'nullable|date|before_or_equal:today',
            'reference_name' => 'nullable|string|max:150',
            'reference_id_no' => 'nullable|string|max:64',
            'reference_address' => 'nullable|string|max:255',
            'loan_officer_id' => 'nullable|exists:users,id',
            'vehicle_no' => 'nullable|string|max:20',
            'insurance' => 'nullable|array',
            'insurance.insurance_company' => 'nullable|string|max:150',
            'insurance.policy_no' => 'nullable|string|max:64',
            'insurance.amount' => 'nullable|numeric|min:0',
            'insurance.contact_person' => 'nullable|string|max:150',
            'insurance.coverage' => 'nullable|string|max:255',
            'insurance.remarks' => 'nullable|string',
            'guarantors' => 'array',
            'guarantors.*.guarantor_customer_id' => 'required|exists:customers,id',
            'guarantors.*.guaranteed_amount' => 'required|numeric|min:0',
            'co_applicants' => 'array',
            'co_applicants.*.co_applicant_customer_id' => 'required|exists:customers,id',
            'co_applicants.*.share_percent' => 'nullable|numeric|min:0|max:100',
            'collateral' => 'array',
            'collateral.*.id' => 'nullable|integer|exists:facility_collateral,id',
            'collateral.*.collateral_type' => ['nullable', Rule::in([...self::COLLATERAL_TYPES, 'FixedDeposit'])],
            'collateral.*.collateral_category' => 'nullable|string|max:64',
            'collateral.*.description' => 'required|string|max:255',
            'collateral.*.estimated_value' => 'nullable|numeric|min:0',
            'collateral.*.vehicle_type' => 'nullable|string|max:30',
            'collateral.*.make_model' => 'nullable|string|max:150',
            'collateral.*.registration_no' => 'nullable|string|max:20',
            'collateral.*.chassis_no' => 'nullable|string|max:50',
            'collateral.*.engine_no' => 'nullable|string|max:50',
            'collateral.*.year_of_manufacture' => 'nullable|integer|min:1900|max:' . (date('Y') + 1),
        ]);

        \App\Models\CustomerBankDetail::assertBelongsTo((int) $validated['customer_bank_detail_id'], (int) $validated['customer_id']);

        $loanType = LoanType::find($validated['loan_type_id']);
        if (!$loanType->is_product) {
            throw ValidationException::withMessages(['loan_type_id' => 'This loan type is a category, not a product — pick one of its sub-items.']);
        }
        if ($validated['principal_amount'] < $loanType->min_amount || $validated['principal_amount'] > $loanType->max_amount) {
            throw ValidationException::withMessages(['principal_amount' => "Amount must be between {$loanType->min_amount} and {$loanType->max_amount} for {$loanType->name}."]);
        }
        if ($validated['term_months'] < $loanType->min_term_months || $validated['term_months'] > $loanType->max_term_months) {
            throw ValidationException::withMessages(['term_months' => "Term must be between {$loanType->min_term_months} and {$loanType->max_term_months} months for {$loanType->name}."]);
        }
        $downPayment = $validated['down_payment'] ?? 0;
        if ($downPayment >= $validated['principal_amount']) {
            throw ValidationException::withMessages(['down_payment' => 'Down payment must be less than the loan amount.']);
        }

        // Leasing loans track the leased vehicle's registration number directly on
        // the loan (separate from the optional facility_collateral list — see the
        // loan_facility_details.vehicle_no migration docblock).
        if ($loanType->category === 'Leasing' && empty($validated['vehicle_no'])) {
            throw ValidationException::withMessages(['vehicle_no' => 'Vehicle No is required for a Leasing loan.']);
        }
        // Leasing/Mortgage loans require an insurance policy on the leased/mortgaged asset.
        if (in_array($loanType->category, ['Leasing', 'Mortgage'], true)) {
            foreach (['insurance_company', 'policy_no', 'amount', 'contact_person', 'coverage'] as $field) {
                if (!isset($validated['insurance'][$field]) || $validated['insurance'][$field] === '') {
                    throw ValidationException::withMessages(["insurance.$field" => 'Insurance details are required for a Leasing or Mortgage loan.']);
                }
            }
        }

        $guarantors = $validated['guarantors'] ?? [];
        foreach ($guarantors as $g) {
            if ((int) $g['guarantor_customer_id'] === (int) $validated['customer_id']) {
                throw ValidationException::withMessages(['guarantors' => 'A customer cannot guarantee their own loan.']);
            }
        }

        $coApplicantsInput = $validated['co_applicants'] ?? [];
        $coApplicantIds = array_column($coApplicantsInput, 'co_applicant_customer_id');
        if (in_array((int) $validated['customer_id'], array_map('intval', $coApplicantIds), true)) {
            throw ValidationException::withMessages(['co_applicants' => 'The primary applicant cannot also be listed as a co-applicant.']);
        }
        if (count($coApplicantIds) !== count(array_unique($coApplicantIds))) {
            throw ValidationException::withMessages(['co_applicants' => 'The same co-applicant is listed more than once.']);
        }

        $facilityData = [
            'branch_id' => $validated['branch_id'],
            'customer_id' => $validated['customer_id'],
            'customer_bank_detail_id' => $validated['customer_bank_detail_id'],
            'principal_amount' => $validated['principal_amount'],
            // The product sets a default rate, but it's negotiable per application.
            'interest_rate_percent' => $validated['interest_rate_percent'] ?? $loanType->interest_rate_percent,
            'rate_period' => $loanType->rate_period,
            'term_months' => $validated['term_months'],
        ];

        $detailData = [
            'loan_type_id' => $validated['loan_type_id'],
            'vehicle_no' => $loanType->category === 'Leasing' ? $validated['vehicle_no'] : null,
            'down_payment' => $downPayment,
            'document_charge_amount' => (float) $loanType->processing_fee_percent > 0 && isset($validated['document_charge_amount']) && $validated['document_charge_amount'] !== ''
                ? $validated['document_charge_amount'] : null,
            'interest_method' => $loanType->interest_method,
            'installment_frequency' => $validated['installment_frequency'],
            'purpose' => $validated['purpose'] ?? null,
            'application_date' => $validated['application_date'] ?? null,
            'reference_name' => $validated['reference_name'] ?? null,
            'reference_id_no' => $validated['reference_id_no'] ?? null,
            'reference_address' => $validated['reference_address'] ?? null,
            'loan_officer_id' => $validated['loan_officer_id'] ?? null,
        ];

        // Only meaningful for Leasing/Mortgage — see the facility_insurance_details
        // migration docblock. Null (not an empty array) for every other category,
        // which syncInsuranceDetail() below reads as "no insurance row for this loan".
        $insurance = in_array($loanType->category, ['Leasing', 'Mortgage'], true) ? [
            'insurance_company' => $validated['insurance']['insurance_company'],
            'policy_no' => $validated['insurance']['policy_no'],
            'amount' => $validated['insurance']['amount'],
            'contact_person' => $validated['insurance']['contact_person'],
            'coverage' => $validated['insurance']['coverage'],
            'remarks' => $validated['insurance']['remarks'] ?? null,
        ] : null;

        $collateral = array_map(function ($c) {
            $type = $c['collateral_type'] ?? 'Vehicle';

            return [
                'id' => $c['id'] ?? null,
                'collateral_type' => $type,
                'collateral_category' => $c['collateral_category'] ?? null,
                'description' => $c['description'],
                'estimated_value' => $c['estimated_value'] ?? null,
                'vehicle' => $type === 'Vehicle' ? [
                    'vehicle_type' => $c['vehicle_type'] ?? null,
                    'make_model' => $c['make_model'] ?? null,
                    'registration_no' => $c['registration_no'] ?? null,
                    'chassis_no' => $c['chassis_no'] ?? null,
                    'engine_no' => $c['engine_no'] ?? null,
                    'year_of_manufacture' => $c['year_of_manufacture'] ?? null,
                ] : null,
            ];
        }, array_filter($validated['collateral'] ?? [], fn ($c) => ($c['collateral_type'] ?? null) !== 'FixedDeposit'));
        $collateral = array_values($collateral);

        $guarantors = array_map(fn ($g) => [
            'guarantor_customer_id' => $g['guarantor_customer_id'],
            'guaranteed_amount' => $g['guaranteed_amount'],
        ], $guarantors);

        $coApplicants = array_map(fn ($ca) => [
            'co_applicant_customer_id' => $ca['co_applicant_customer_id'],
            'share_percent' => $ca['share_percent'] ?? null,
        ], $coApplicantsInput);

        return [$facilityData, $detailData, $guarantors, $collateral, $insurance, $coApplicants];
    }

    /** Creates/updates/clears the loan's insurance row to match $insurance — null
     *  means the current loan type's category doesn't require one (see validated()),
     *  so any existing row (e.g. left over from before an edit changed loan_type_id
     *  away from Leasing/Mortgage) is removed. */
    private function syncInsuranceDetail(Loan $loan, ?array $insurance): void
    {
        if ($insurance) {
            $loan->insurance()->updateOrCreate([], $insurance);
        } else {
            $loan->insurance()->delete();
        }
    }
}
