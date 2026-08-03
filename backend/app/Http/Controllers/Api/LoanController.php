<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Loan;
use App\Models\LoanSchedule;
use App\Models\LoanType;
use App\Models\SalesExecutiveCommission;
use App\Services\NumberGenerator;
use App\Support\LoanAmortizer;
use App\Support\LoanSummary;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class LoanController extends Controller
{
    private const FREQUENCIES = ['daily', 'weekly', 'monthly'];

    private const SUMMARY_RELATIONS = [
        'customer:id,full_name,code,nic_no,phone',
        'branch:id,name,code',
        'loanType:id,name,code',
        'loanOfficer:id,name',
    ];

    private const DETAIL_RELATIONS = [
        'customer:id,full_name,code,nic_no,passport_no,driving_license_no,phone,email,address,occupation_type,employer_name,job_title,business_name,monthly_income,status,is_guarantor,branch_id',
        'customer.branch:id,name,code',
        'branch:id,name,code',
        'loanType:id,name,code,parent_id,interest_method,interest_rate_percent,rate_period,default_charge_rate_percent,grace_period_days,processing_fee_percent',
        'loanOfficer:id,name',
        'recoveryOfficer:id,name',
        'applier:id,name',
        'approver:id,name',
        'confirmedBy:id,name',
        'guarantors.guarantor:id,full_name,code,nic_no,phone',
        'collateral',
        'schedules.allocations.payment:id,status,approval_status',
        'payments.receiver:id,name',
        'payments.voider:id,name',
        'charges.creator:id,name',
        'postDatedCheques.creator:id,name',
        'documents.uploader:id,name',
        'diaryNotes.creator:id,name',
        'waivers.approver:id,name',
    ];

    public function index()
    {
        return Loan::with(self::SUMMARY_RELATIONS)
            ->withSum('schedules as total_due_sum', 'total_due')
            ->withSum('schedules as paid_sum', 'paid_amount')
            ->orderByDesc('id')->get();
    }

    /**
     * Active loans with at least one installment past its due date and not
     * fully paid. There's no persisted "overdue" flag (LoanSchedule.status
     * only ever becomes Pending/Partial/Paid — see LoanPaymentAllocator), so
     * this is computed live, the same way app\Support\LoanSummary does for a
     * single loan's arrears — penaltyDueForRow() is reused directly so the OD
     * figure here can never disagree with what Loan Detail shows.
     */
    public function overdue()
    {
        $today = now()->startOfDay();

        $loans = Loan::where('status', 'Active')
            ->whereHas('schedules', fn ($q) => $q->where('due_date', '<', $today)->where('status', '!=', 'Paid'))
            ->with([
                'customer:id,full_name,code,nic_no,phone',
                'branch:id,name,code',
                'loanType:id,name,code,default_charge_rate_percent,grace_period_days',
                'loanOfficer:id,name',
                'recoveryOfficer:id,name',
                'schedules' => fn ($q) => $q->where('due_date', '<', $today)->where('status', '!=', 'Paid')->orderBy('due_date'),
            ])
            ->withSum('schedules as total_due_sum', 'total_due')
            ->withSum('schedules as paid_sum', 'paid_amount')
            ->get();

        return $loans->map(function ($loan) use ($today) {
            $graceDays = (int) ($loan->loanType?->grace_period_days ?? 5);
            $chargeRate = (float) ($loan->loanType?->default_charge_rate_percent ?? 0);

            $overdueSchedules = $loan->schedules;
            $oldestDueDate = $overdueSchedules->min('due_date');

            $data = $loan->toArray();
            $data['oldestOverdueDate'] = $oldestDueDate;
            $data['daysOverdue'] = $oldestDueDate ? (int) $today->diffInDays($oldestDueDate) : 0;
            $data['overdueInstallments'] = $overdueSchedules->count();
            $data['overdueAmount'] = round((float) $overdueSchedules->sum(
                fn ($s) => max(0, (float) $s->principal_due + (float) $s->interest_due - (float) $s->paid_amount)
            ), 2);
            $data['penaltyDue'] = round((float) $overdueSchedules->sum(
                fn ($s) => LoanSummary::penaltyDueForRow($s, $graceDays, $chargeRate, $today)
            ), 2);

            return $data;
        })->sortByDesc('daysOverdue')->values();
    }

    public function show(Loan $loan)
    {
        $loan->load(self::DETAIL_RELATIONS);
        $data = $loan->toArray();
        $data['summary'] = LoanSummary::build($loan);

        return $data;
    }

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
        ]);

        if (($data['is_terminated'] ?? false) && !$loan->is_terminated) {
            $data['terminated_at'] = $data['terminated_at'] ?? now();
        }
        if (($data['is_legal'] ?? false) && !$loan->is_legal) {
            $data['legal_at'] = $data['legal_at'] ?? now();
        }
        if (($data['seizing_order_issued'] ?? false) && !$loan->seizing_order_issued) {
            $data['seizing_order_at'] = $data['seizing_order_at'] ?? now();
        }
        if (($data['is_repossessed'] ?? false) && !$loan->is_repossessed) {
            $data['repossessed_at'] = $data['repossessed_at'] ?? now();
        }

        $loan->update($data);

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

        $loan->update($data);

        return $this->show($loan);
    }

    public function store(Request $request)
    {
        [$data, $guarantors, $collateral] = $this->validated($request);
        $data['applied_by'] = $request->user()->id;
        $data['created_by'] = $request->user()->id;
        $data['application_date'] = $data['application_date'] ?? now()->toDateString();
        $data['status'] = 'Pending';

        $loan = DB::transaction(function () use ($data, $guarantors, $collateral, $request) {
            $branch = Branch::find($data['branch_id']);
            $loanType = LoanType::find($data['loan_type_id']);
            $data['code'] = NumberGenerator::next('loan', [
                'branch_id' => $branch->id,
                'branch_code' => $branch->code,
                'loan_type_id' => $loanType->id,
                'loan_type_code' => $loanType->code,
                'user_id' => $request->user()->id,
            ], $loanType->reference_format);

            $loan = Loan::create($data);
            NumberGenerator::attach($data['code'], $loan);
            foreach ($guarantors as $g) {
                $loan->guarantors()->create($g);
            }
            foreach ($collateral as $c) {
                $loan->collateral()->create($c);
            }

            return $loan;
        });

        return response()->json($loan->load(self::DETAIL_RELATIONS), 201);
    }

    public function update(Request $request, Loan $loan)
    {
        if ($loan->status !== 'Pending') {
            return response()->json(['message' => 'Only pending applications can be edited.'], 422);
        }
        [$data, $guarantors, $collateral] = $this->validated($request, $loan);

        DB::transaction(function () use ($loan, $data, $guarantors, $collateral) {
            $loan->update($data);
            $loan->guarantors()->delete();
            foreach ($guarantors as $g) {
                $loan->guarantors()->create($g);
            }
            $loan->collateral()->delete();
            foreach ($collateral as $c) {
                $loan->collateral()->create($c);
            }
        });

        return $loan->load(self::DETAIL_RELATIONS);
    }

    public function destroy(Loan $loan)
    {
        if ($loan->status !== 'Pending') {
            return response()->json(['message' => 'Only pending applications can be withdrawn.'], 422);
        }
        $loan->delete();

        return response()->noContent();
    }

    public function approve(Request $request, Loan $loan)
    {
        if ($loan->status !== 'Pending') {
            return response()->json(['message' => 'Only pending applications can be approved.'], 422);
        }
        $loan->update([
            'status' => 'Approved',
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
            'rejected_reason' => null,
        ]);

        return $loan->load(self::DETAIL_RELATIONS);
    }

    public function reject(Request $request, Loan $loan)
    {
        if ($loan->status !== 'Pending') {
            return response()->json(['message' => 'Only pending applications can be rejected.'], 422);
        }
        $data = $request->validate(['rejected_reason' => 'required|string|max:255']);
        $loan->update([
            'status' => 'Rejected',
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
            'rejected_reason' => $data['rejected_reason'],
        ]);

        return $loan->load(self::DETAIL_RELATIONS);
    }

    public function disburse(Request $request, Loan $loan)
    {
        if ($loan->status !== 'Approved') {
            return response()->json(['message' => 'Only approved loans can be disbursed.'], 422);
        }
        $loanType = LoanType::find($loan->loan_type_id);
        $documentCharges = $loan->charges()->where('charge_type', 'Document')->get();
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
        ]);

        DB::transaction(function () use ($loan, $data) {
            $loan->update([
                'disbursed_amount' => $data['disbursed_amount'] ?? ($loan->principal_amount - $loan->down_payment),
                'disbursed_at' => $data['disbursed_at'] ?? now(),
                'first_installment_date' => $data['first_installment_date'],
                'status' => 'Active',
            ]);
            $loan->schedules()->insert(LoanAmortizer::generate($loan, $data['first_installment_date']));
            $this->generateSalesExecutiveCommission($loan);
        });

        return $loan->load(self::DETAIL_RELATIONS);
    }

    /** Creates the first commission cycle for the loan's sales executive, if its
     *  loan type has a commission configured — only for loans disbursed from now
     *  on, since this only runs at disbursal time. A percentage-type commission
     *  goes on to pay out repeatedly, once per cycle of required_installments
     *  paid installments — see SalesExecutiveCommissionController::maybeStartNextCycle(). */
    private function generateSalesExecutiveCommission(Loan $loan): void
    {
        $loanType = LoanType::find($loan->loan_type_id);
        if (!$loan->loan_officer_id || !$loanType || (float) $loanType->commission_value <= 0) {
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
            'loan_id' => $loan->id,
            'sales_executive_id' => $loan->loan_officer_id,
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
    public function skipInstallment(Loan $loan, LoanSchedule $schedule)
    {
        if ($schedule->loan_id !== $loan->id) {
            abort(404);
        }
        if ($loan->status !== 'Active') {
            return response()->json(['message' => 'Only installments on an active loan can be skipped.'], 422);
        }
        if ($schedule->status === 'Paid' || (float) $schedule->paid_amount > 0) {
            return response()->json(['message' => 'A paid or partially paid installment cannot be skipped.'], 422);
        }

        DB::transaction(function () use ($loan, $schedule) {
            $last = $loan->schedules()->orderByDesc('due_date')->firstOrFail();
            $schedule->update([
                'due_date' => LoanAmortizer::nextDueDate($last->due_date, $loan->installment_frequency)->format('Y-m-d'),
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
        $rows = LoanSchedule::query()
            ->whereHas('loan', fn ($q) => $q->where('status', 'Active')->where('loan_type_id', $loanType->id))
            ->where('status', '!=', 'Paid')
            ->where('paid_amount', 0)
            ->selectRaw('due_date, COUNT(DISTINCT loan_id) as loan_count, COUNT(*) as installment_count, SUM(total_due) as total_amount')
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
            ->where('loan_type_id', $loanType->id)
            ->whereHas('schedules', fn ($q) => $q->whereIn('due_date', $dates)->where('status', '!=', 'Paid')->where('paid_amount', 0))
            ->get();

        $loansAffected = 0;
        $installmentsSkipped = 0;
        $errors = [];

        foreach ($loans as $loan) {
            $rows = $loan->schedules()->whereIn('due_date', $dates)
                ->where('status', '!=', 'Paid')->where('paid_amount', 0)
                ->orderBy('due_date')->get();
            if ($rows->isEmpty()) {
                continue;
            }
            try {
                DB::transaction(function () use ($loan, $rows) {
                    foreach ($rows as $schedule) {
                        // Re-queried fresh each pass (not the eager-loaded $rows/schedules
                        // collection) so a loan with two skippable dates in this batch
                        // correctly stacks the second skip behind the first.
                        $last = $loan->schedules()->orderByDesc('due_date')->firstOrFail();
                        $schedule->update([
                            'due_date' => LoanAmortizer::nextDueDate($last->due_date, $loan->installment_frequency)->format('Y-m-d'),
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

    /** @return array{0: array, 1: array, 2: array} [loan fields, guarantors, collateral] */
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
            'guarantors' => 'array',
            'guarantors.*.guarantor_id' => 'required|exists:customers,id',
            'guarantors.*.guaranteed_amount' => 'required|numeric|min:0',
            'collateral' => 'array',
            'collateral.*.type' => 'required|string|max:64',
            'collateral.*.description' => 'required|string|max:255',
            'collateral.*.estimated_value' => 'nullable|numeric|min:0',
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

        $guarantors = $validated['guarantors'] ?? [];
        foreach ($guarantors as $g) {
            if ((int) $g['guarantor_id'] === (int) $validated['customer_id']) {
                throw ValidationException::withMessages(['guarantors' => 'A customer cannot guarantee their own loan.']);
            }
        }

        $data = [
            'branch_id' => $validated['branch_id'],
            'customer_id' => $validated['customer_id'],
            'loan_type_id' => $validated['loan_type_id'],
            'principal_amount' => $validated['principal_amount'],
            'down_payment' => $downPayment,
            // The product sets a default rate, but it's negotiable per application.
            'interest_rate_percent' => $validated['interest_rate_percent'] ?? $loanType->interest_rate_percent,
            'interest_method' => $loanType->interest_method,
            'rate_period' => $loanType->rate_period,
            'term_months' => $validated['term_months'],
            'installment_frequency' => $validated['installment_frequency'],
            'purpose' => $validated['purpose'] ?? null,
            'application_date' => $validated['application_date'] ?? null,
            'reference_name' => $validated['reference_name'] ?? null,
            'reference_id_no' => $validated['reference_id_no'] ?? null,
            'reference_address' => $validated['reference_address'] ?? null,
            'loan_officer_id' => $validated['loan_officer_id'] ?? null,
        ];

        $collateral = array_map(fn ($c) => [
            'type' => $c['type'],
            'description' => $c['description'],
            'estimated_value' => $c['estimated_value'] ?? null,
        ], $validated['collateral'] ?? []);

        $guarantors = array_map(fn ($g) => [
            'guarantor_id' => $g['guarantor_id'],
            'guaranteed_amount' => $g['guaranteed_amount'],
        ], $guarantors);

        return [$data, $guarantors, $collateral];
    }
}
