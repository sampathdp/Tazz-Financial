<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\FdDeposit;
use App\Models\FixedDeposit;
use App\Models\Loan;
use App\Services\NumberGenerator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CustomerController extends Controller
{
    private const OCCUPATION_TYPES = ['Employed', 'SelfEmployed', 'BusinessOwner', 'Unemployed', 'Retired', 'Student', 'Other'];
    private const TITLES = ['Mr.', 'Mrs.', 'Miss', 'Ms.', 'Dr.', 'Rev.'];

    public function index()
    {
        return Customer::with('branch:id,name,code')->orderByDesc('id')->get();
    }

    public function show(Customer $customer)
    {
        return $customer->load(['branch:id,name,code', 'documents']);
    }

    /**
     * Full loan history for a customer's profile view: every loan they've
     * ever borrowed (any status — pending, active, closed, written off, ...)
     * plus every loan they stand as a guarantor on.
     */
    public function loans(Customer $customer)
    {
        $withSums = fn ($q) => $q->with(['branch:id,name,code', 'loanType:id,name,code'])
            ->withSum('schedules as total_due_sum', 'total_due')
            ->withSum('schedules as paid_sum', 'paid_amount')
            ->orderByDesc('id');

        $borrower = $withSums(Loan::where('customer_id', $customer->id))->get();

        $guarantor = $withSums(
            Loan::whereHas('guarantors', fn ($q) => $q->where('guarantor_id', $customer->id))
                ->with('customer:id,full_name,code')
        )->get();

        return ['borrower' => $borrower, 'guarantor' => $guarantor];
    }

    /** Full funding history for a customer's profile view: every deposit and Fixed Deposit they hold. */
    public function funding(Customer $customer)
    {
        return [
            'deposits' => FdDeposit::where('customer_id', $customer->id)->orderByDesc('id')->get(),
            'fixed_deposits' => FixedDeposit::with('fundingProduct:id,name,code')->where('customer_id', $customer->id)->orderByDesc('id')->get(),
        ];
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['created_by'] = $request->user()->id;

        // Serialize code generation on the last row so two concurrent creates
        // can't compute the same "next" number.
        $customer = DB::transaction(function () use ($data, $request) {
            $data['code'] = NumberGenerator::next('customer', ['user_id' => $request->user()->id]);
            $customer = Customer::create($data);
            NumberGenerator::attach($data['code'], $customer);

            return $customer;
        });

        return response()->json($customer->load('branch:id,name,code'), 201);
    }

    public function update(Request $request, Customer $customer)
    {
        $customer->update($this->validated($request, $customer));

        return $customer->load('branch:id,name,code');
    }

    public function destroy(Customer $customer)
    {
        $customer->delete();

        return response()->noContent();
    }

    private function validated(Request $request, ?Customer $customer = null): array
    {
        return $request->validate([
            'branch_id' => 'required|exists:branches,id',
            'title' => ['required', Rule::in(self::TITLES)],
            'surname' => 'required|string|max:100',
            'name_in_full' => 'nullable|string|max:191',
            'initials' => 'required|string|max:32',
            'nic_no' => ['nullable', 'string', 'max:32', Rule::unique('customers', 'nic_no')->ignore($customer?->id)],
            'passport_no' => 'nullable|string|max:32',
            'driving_license_no' => 'nullable|string|max:32',
            'dob' => 'nullable|date',
            'gender' => 'nullable|string|max:16',
            'phone' => 'nullable|string|max:32',
            'alt_phone' => 'nullable|string|max:32',
            'email' => 'nullable|email|max:190',
            'address' => 'nullable|string',
            'occupation_type' => ['nullable', Rule::in(self::OCCUPATION_TYPES)],
            'employer_name' => 'nullable|string|max:191',
            'job_title' => 'nullable|string|max:128',
            'employer_address' => 'nullable|string|max:255',
            'employer_phone' => 'nullable|string|max:32',
            'employment_start_date' => 'nullable|date',
            'business_name' => 'nullable|string|max:191',
            'business_reg_no' => 'nullable|string|max:64',
            'business_type' => 'nullable|string|max:128',
            'business_address' => 'nullable|string|max:255',
            'business_phone' => 'nullable|string|max:32',
            'monthly_income' => 'nullable|numeric|min:0',
            'status' => ['nullable', Rule::in(['Active', 'Blacklisted', 'Inactive'])],
            'is_guarantor' => 'boolean',
            'notes' => 'nullable|string',
        ]);
    }
}
