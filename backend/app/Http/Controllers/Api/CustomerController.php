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
    private const GENDERS = ['Male', 'Female', 'Other'];

    private const EMPLOYMENT_FIELDS = ['employer_name', 'job_title', 'employer_address', 'employer_phone', 'employment_start_date'];
    private const BUSINESS_FIELDS = ['business_name', 'business_reg_no', 'business_type', 'business_address', 'business_phone'];

    public function index()
    {
        return Customer::with(['branch:id,name,code', 'employmentDetail', 'businessDetail'])->orderByDesc('id')->get();
    }

    public function show(Customer $customer)
    {
        return $customer->load(['branch:id,name,code', 'documents', 'employmentDetail', 'businessDetail']);
    }

    /**
     * Full loan history for a customer's profile view: every loan they've
     * ever borrowed (any status — pending, active, closed, written off, ...)
     * plus every loan they stand as a guarantor on.
     */
    public function loans(Customer $customer)
    {
        $withSums = fn ($q) => $q->with(['branch:id,name,code', 'loanDetail.loanType:id,name,code'])
            ->withSum('schedules as total_due_sum', 'total_due')
            ->withSum('schedules as paid_sum', 'paid_amount')
            ->orderByDesc('id');

        $borrower = $withSums(Loan::where('customer_id', $customer->id))->get();

        $guarantor = $withSums(
            Loan::whereHas('guarantors', fn ($q) => $q->where('guarantor_customer_id', $customer->id))
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

            $this->syncDetail($customer, 'employmentDetail', $data, self::EMPLOYMENT_FIELDS);
            $this->syncDetail($customer, 'businessDetail', $data, self::BUSINESS_FIELDS);

            return $customer;
        });

        return response()->json($customer->load(['branch:id,name,code', 'employmentDetail', 'businessDetail']), 201);
    }

    public function update(Request $request, Customer $customer)
    {
        $data = $this->validated($request, $customer);

        DB::transaction(function () use ($data, $customer) {
            $customer->update($data);
            $this->syncDetail($customer, 'employmentDetail', $data, self::EMPLOYMENT_FIELDS);
            $this->syncDetail($customer, 'businessDetail', $data, self::BUSINESS_FIELDS);
        });

        return $customer->load(['branch:id,name,code', 'employmentDetail', 'businessDetail']);
    }

    public function destroy(Customer $customer)
    {
        $customer->delete();

        return response()->noContent();
    }

    /**
     * Creates, updates, or clears a customer's employment/business detail
     * row from whichever of that cluster's fields the request actually sent
     * — mirrors the old flat-column behavior (a field absent from the
     * request leaves existing data alone; present-but-null clears it) now
     * that the cluster lives in its own 1:1 table instead of directly on
     * customers.
     */
    private function syncDetail(Customer $customer, string $relation, array $data, array $fields): void
    {
        $sent = array_intersect_key($data, array_flip($fields));
        if (!$sent) {
            return;
        }
        if (array_filter($sent, fn ($v) => $v !== null)) {
            $customer->{$relation}()->updateOrCreate([], $sent);
        } else {
            $customer->{$relation}()->delete();
        }
    }

    private function validated(Request $request, ?Customer $customer = null): array
    {
        return $request->validate([
            'branch_id' => 'required|exists:branches,id',
            'title' => ['required', Rule::in(self::TITLES)],
            'surname' => 'required|string|max:100',
            'name_denoted_by_initials' => 'nullable|string|max:191',
            'initials' => 'required|string|max:20',
            // Checked against nic_no_active (a generated column that resolves
            // to NULL on a trashed row — see the create_customers_table
            // migration), not the raw nic_no column: a soft-deleted
            // customer's NIC must not block re-registering someone new
            // under the same number.
            'nic_no' => ['nullable', 'string', 'max:15', Rule::unique('customers', 'nic_no_active')->ignore($customer?->id)],
            'passport_no' => 'nullable|string|max:20',
            'driving_license_no' => 'nullable|string|max:20',
            'dob' => 'nullable|date',
            'gender' => ['nullable', Rule::in(self::GENDERS)],
            'phone' => 'nullable|string|max:20',
            'alt_phone' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string|max:255',
            'occupation_type' => ['nullable', Rule::in(self::OCCUPATION_TYPES)],
            'employer_name' => 'nullable|string|max:191',
            'job_title' => 'nullable|string|max:128',
            'employer_address' => 'nullable|string|max:255',
            'employer_phone' => 'nullable|string|max:20',
            'employment_start_date' => 'nullable|date',
            'business_name' => 'nullable|string|max:191',
            'business_reg_no' => 'nullable|string|max:32',
            'business_type' => 'nullable|string|max:128',
            'business_address' => 'nullable|string|max:255',
            'business_phone' => 'nullable|string|max:20',
            'monthly_income' => 'nullable|numeric|min:0',
            'status' => ['nullable', Rule::in(['Active', 'Blacklisted', 'Inactive'])],
            'is_guarantor' => 'boolean',
            'notes' => 'nullable|string',
        ]);
    }
}
