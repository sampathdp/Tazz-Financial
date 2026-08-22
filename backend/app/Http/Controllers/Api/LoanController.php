<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApprovalRequest;
use App\Models\ApprovalType;
use App\Models\Branch;
use App\Models\FacilityCollateralVehicleDetail;
use App\Models\FacilitySchedule;
use App\Models\Loan;
use App\Models\LoanFacilityDetail;
use App\Models\LoanType;
use App\Models\SalesExecutiveCommission;
use App\Services\NumberGenerator;
use App\Support\AerCalculator;
use App\Support\LoanAmortizer;
use App\Support\LoanLedgerPoster;
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
    private const LIVE_STATUSES = ['Approved', 'Active', 'Closed', 'WrittenOff', 'Restructured'];

    private const SUMMARY_RELATIONS = [
        'customer:id,full_name,code,nic_no,phone',
        'coApplicants.coApplicant:id,full_name,code,nic_no,phone',
        'branch:id,name,code',
        'loanDetail.loanType:id,name,code',
        'loanDetail.loanOfficer:id,name',
        'pendingCancelRequest:id,approval_type_id,subject_id,status,required_approvals',
    ];

    private const DETAIL_RELATIONS = [
        'customer:id,full_name,code,nic_no,passport_no,driving_license_no,phone,email,address,occupation_type,monthly_income,status,is_guarantor,branch_id',
        'customer.branch:id,name,code',
        'customer.employmentDetail',
        'customer.businessDetail',
        'branch:id,name,code',
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
        'pendingApplicationRequest.requester:id,name',
        'coApplicants.coApplicant:id,full_name,code,nic_no,passport_no,driving_license_no,phone,email,address,occupation_type,monthly_income',
        'guarantors.guarantor:id,full_name,code,nic_no,phone',
        'collateral.vehicleDetail',
        'collateral.jewelryDetail',
        'collateral.documents.uploader:id,name',
        'schedules.allocations.payment:id,status,approval_status',
        'payments.receiver:id,name',
        'payments.voider:id,name',
        'payments.bank:id,name',
        'payments.pendingVoidRequest.requester:id,name',
        'payments.pendingAllocationRequest.requester:id,name',
        'charges.creator:id,name', 'charges.category:id,name',
        'postDatedCheques.creator:id,name',
        'postDatedCheques.bank:id,name',
        'documents.uploader:id,name',
        'diaryNotes.creator:id,name',
        'waivers.approver:id,name',
        'statusEvents.recordedBy:id,name',
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

        return LoanSummary::overdueList($today, $from);
    }

    public function show(Loan $loan)
    {
        $loan->load(self::DETAIL_RELATIONS);
        $data = $loan->toArray();
        $data['summary'] = LoanSummary::build($loan);

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
            $removed = $loan->collateral()->whereNotIn('id', $keepIds ?: [0])->get();
            foreach ($removed as $r) {
                foreach ($r->documents as $doc) {
                    Storage::disk('public')->delete($doc->file_path);
                }
            }
            $loan->collateral()->whereNotIn('id', $keepIds ?: [0])->delete();
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
        if ($loan->status === 'Active' && $loan->payments()->exists()) {
            return response()->json(['message' => 'This loan already has payments recorded — cancel is only for loans with no collection history yet. Use Contract Status Change instead.'], 422);
        }
        if ($loan->cancelRequests()->where('status', 'PendingApproval')->exists()) {
            return response()->json(['message' => 'A cancel request is already pending approval for this loan.'], 422);
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
        if ($loan->status === 'Active' && $loan->payments()->exists()) {
            $cancelRequest->update(['status' => 'Rejected', 'rejected_by' => $request->user()->id, 'rejected_at' => now(), 'reject_reason' => 'Payments were recorded on this loan after the request was submitted.']);

            return response()->json(['message' => 'This loan now has payments recorded — the request has been auto-rejected. Use Contract Status Change instead.'], 422);
        }

        DB::transaction(function () use ($cancelRequest, $loan, $request) {
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

    public function disburse(Request $request, Loan $loan)
    {
        if ($loan->status !== 'Approved') {
            return response()->json(['message' => 'Only approved loans can be disbursed.'], 422);
        }
        $detail = $loan->loanDetail()->with('loanType')->firstOrFail();
        $loanType = $detail->loanType;
        $documentCharges = $loan->charges()->whereHas('category', fn($q) => $q->where('name', 'Document'))->get();
        if ($loanType && (float) $loanType->processing_fee_percent > 0 && $documentCharges->isEmpty()) {
            return response()->json(['message' => 'Add the document charge before disbursing this loan.'], 422);
        }
        $unpaidDocumentCharge = $documentCharges->sum(fn ($c) => max(0, (float) $c->due_amount - (float) $c->paid_amount));
        if ($unpaidDocumentCharge > 0) {
            return response()->json(['message' => 'Settle the document charge before disbursing this loan.'], 422);
        }
        $data = $request->validate([
            'disbursed_amount' => 'nullable|numeric|min:0',
            'disbursed_at' => 'nullable|date',
            'first_installment_date' => 'required|date',
            'disbursement_method' => ['required', Rule::in(['Cash', 'Bank', 'Cheque'])],
        ]);

        DB::transaction(function () use ($loan, $detail, $data) {
            $financed = $data['disbursed_amount'] ?? ((float) $loan->principal_amount - (float) $detail->down_payment);
            $loan->update([
                'disbursed_amount' => $financed,
                'disbursed_at' => $data['disbursed_at'] ?? now(),
                'outstanding_balance_cache' => $financed,
                'status' => 'Active',
            ]);
            $detail->update([
                'first_installment_date' => $data['first_installment_date'],
                'disbursement_method' => $data['disbursement_method'],
            ]);
            $rows = LoanAmortizer::generate($loan->fresh(), $detail->fresh(), $data['first_installment_date']);
            $loan->schedules()->insert($rows);
            $this->applyEffectiveRate($detail->fresh(), $rows);
            $this->generateSalesExecutiveCommission($loan->fresh(), $detail);
            LoanLedgerPoster::postDisbursement($loan->fresh());
        });

        return $loan->load(self::DETAIL_RELATIONS);
    }

    /**
     * Bad-debt write-off — WrittenOff was a listed status (LIVE_STATUSES,
     * loan report counts) that nothing ever actually set; this is that
     * missing action. Direct-write-off (Dr 5300 expense, Cr 1100), no
     * amortized schedule is touched (kept as history, same as a Closed
     * loan) — only the outstanding balance and status change.
     */
    public function writeOff(Request $request, Loan $loan)
    {
        if ($loan->status !== 'Active') {
            return response()->json(['message' => 'Only an active loan can be written off.'], 422);
        }
        $data = $request->validate(['reason' => 'required|string|max:255']);
        $amount = (float) $loan->outstanding_balance_cache;

        DB::transaction(function () use ($loan, $data, $amount, $request) {
            LoanLedgerPoster::postWriteOff($loan, $amount, $request->user()->id);
            $loan->update([
                'status' => 'WrittenOff',
                'outstanding_balance_cache' => 0,
                'closed_at' => now(),
                'written_off_reason' => $data['reason'],
                'written_off_by' => $request->user()->id,
            ]);
        });

        return $loan->load(self::DETAIL_RELATIONS);
    }

    /** Flat Rate loans only: back-solves the equivalent reducing-balance rate implied
     *  by the generated schedule's EMI and stores it as a monthly/annual effective
     *  rate (AerCalculator) — informational only, never affects the schedule itself.
     *  Deliberately swallows any failure (invalid inputs, non-convergence, an AER
     *  large enough to overflow the decimal(10,8) columns) so it can never turn a
     *  successful disbursement into a failed one. */
    private function applyEffectiveRate(LoanFacilityDetail $detail, array $rows): void
    {
        if ($detail->interest_method !== 'flat' || empty($rows)) {
            return;
        }
        try {
            $principal = (float) $detail->loan->principal_amount - (float) $detail->down_payment;
            $rental = (float) $rows[0]['total_due'];
            $weekdaysOnly = $detail->installment_frequency === 'daily' && $detail->loanType?->collection_days === 'week_days';
            $periodsPerMonth = LoanAmortizer::periodsPerMonth($detail->installment_frequency, $weekdaysOnly);
            $result = AerCalculator::calculate($principal, $rental, count($rows), $periodsPerMonth);
            if ($result) {
                $detail->update([
                    'effective_monthly_rate' => round($result['monthly'], 8),
                    'effective_annual_rate' => round($result['annual'], 8),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('AER calculation skipped for loan '.$detail->credit_facility_id.': '.$e->getMessage());
        }
    }

    /** Creates the first commission cycle for the loan's sales executive, if its
     *  loan type has a commission configured — only for loans disbursed from now
     *  on, since this only runs at disbursal time. A percentage-type commission
     *  goes on to pay out repeatedly, once per cycle of required_installments
     *  paid installments — see SalesExecutiveCommissionController::maybeStartNextCycle(). */
    private function generateSalesExecutiveCommission(Loan $loan, LoanFacilityDetail $detail): void
    {
        $loanType = $detail->loanType ?? LoanType::find($detail->loan_type_id);
        if (!$detail->loan_officer_id || !$loanType || (float) $loanType->commission_value <= 0) {
            return;
        }

        // Percentage commissions are earned against interest actually collected over
        // this cycle, not the capital disbursed — nothing has been collected yet at
        // disbursal time, so this starts at 0 and is recalculated live (see
        // SalesExecutiveCommissionController::commissionAmount()) until it's paid.
        $commissionAmount = $loanType->commission_type === 'percentage'
            ? 0.0
            : (float) $loanType->commission_value;

        SalesExecutiveCommission::create([
            'credit_facility_id' => $loan->id,
            'sales_executive_id' => $detail->loan_officer_id,
            'loan_type_id' => $loanType->id,
            'cycle_no' => 1,
            'from_installment' => 0,
            'commission_type' => $loanType->commission_type,
            'commission_value' => $loanType->commission_value,
            'commission_amount' => $commissionAmount,
            'required_installments' => $loanType->min_paid_installments,
        ]);
    }

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
            'loan_type_id' => 'required|exists:loan_types,id',
            'principal_amount' => 'required|numeric|min:0',
            'down_payment' => 'nullable|numeric|min:0',
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
            'collateral.*.collateral_type' => ['nullable', Rule::in(self::COLLATERAL_TYPES)],
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
        }, $validated['collateral'] ?? []);

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
