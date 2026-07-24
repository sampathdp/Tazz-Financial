<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Loan;
use App\Models\LoanType;
use App\Support\LoanAmortizer;
use App\Support\LoanSummary;
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
        'loanType:id,name,code,parent_id,interest_method,interest_rate_percent,rate_period',
        'loanOfficer:id,name',
        'recoveryOfficer:id,name',
        'applier:id,name',
        'approver:id,name',
        'confirmedBy:id,name',
        'guarantors.guarantor:id,full_name,code,nic_no,phone',
        'collateral',
        'schedules.allocations',
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

    public function store(Request $request)
    {
        [$data, $guarantors, $collateral] = $this->validated($request);
        $data['applied_by'] = $request->user()->id;
        $data['created_by'] = $request->user()->id;
        $data['status'] = 'Pending';

        $loan = DB::transaction(function () use ($data, $guarantors, $collateral) {
            $max = Loan::withTrashed()->lockForUpdate()->max('id') ?? 0;
            $data['code'] = 'LN-' . str_pad((string) ($max + 1), 6, '0', STR_PAD_LEFT);

            $loan = Loan::create($data);
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
        });

        return $loan->load(self::DETAIL_RELATIONS);
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
