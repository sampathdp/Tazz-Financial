<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\FdDeposit;
use App\Models\FixedDeposit;
use App\Models\Loan;
use App\Models\SavingsAccount;
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

    /**
     * With no `page` param, returns the full list exactly as before — every
     * customer picker across the app (Deposits, SpeedDrafts, Pawnings,
     * FundingReports, LoanApplications, ...) calls customersApi.list() with
     * no params expecting a flat array for local search, and must keep
     * working unchanged. `page` opts into server-side search + pagination
     * for the Customers browse screen.
     */
    public function index(Request $request)
    {
        $query = Customer::with(['branch:id,name,code', 'employmentDetail', 'businessDetail']);

        if (!$request->filled('page')) {
            return $query->orderByDesc('id')->get();
        }

        // Customer type is derived, not stored: a customer is a Loan borrower if they
        // hold a Loan facility, an FD customer if they hold a Fixed Deposit / FD deposit,
        // and a Guarantor if flagged as one or standing guarantor on any facility.
        // One customer can be several at once.
        $isLoan = 'EXISTS (SELECT 1 FROM credit_facilities cf WHERE cf.customer_id = customers.id AND cf.facility_type = \'Loan\' AND cf.deleted_at IS NULL)';
        $isFd = '(EXISTS (SELECT 1 FROM fixed_deposits fd WHERE fd.customer_id = customers.id) OR EXISTS (SELECT 1 FROM fd_deposits fdd WHERE fdd.customer_id = customers.id))';
        $isGuarantor = '(customers.is_guarantor = 1 OR EXISTS (SELECT 1 FROM facility_guarantors fg WHERE fg.guarantor_customer_id = customers.id))';

        $query->select('customers.*')
            ->selectRaw("{$isLoan} AS is_loan_customer")
            ->selectRaw("{$isFd} AS is_fd_customer")
            ->selectRaw("{$isGuarantor} AS is_guarantor_customer");

        if ($request->filled('customer_type')) {
            $map = ['Loan' => $isLoan, 'FD' => $isFd, 'Guarantor' => $isGuarantor];
            abort_unless(isset($map[$request->customer_type]), 422, 'Invalid customer type.');
            $query->whereRaw($map[$request->customer_type]);
        }
        if ($request->filled('from')) {
            $query->whereDate('customers.created_at', '>=', $request->from);
        }
        if ($request->filled('to')) {
            $query->whereDate('customers.created_at', '<=', $request->to);
        }
        if ($request->filled('branch_id')) {
            $query->where('customers.branch_id', (int) $request->branch_id);
        }

        if ($request->filled('search')) {
            $term = $request->search;
            $query->where(function ($q) use ($term) {
                $q->where('full_name', 'like', "%{$term}%")
                    ->orWhere('surname', 'like', "%{$term}%")
                    ->orWhere('initials', 'like', "%{$term}%")
                    ->orWhere('code', 'like', "%{$term}%")
                    ->orWhere('nic_no', 'like', "%{$term}%")
                    ->orWhere('passport_no', 'like', "%{$term}%")
                    ->orWhere('driving_license_no', 'like', "%{$term}%")
                    ->orWhere('phone', 'like', "%{$term}%")
                    ->orWhere('email', 'like', "%{$term}%")
                    ->orWhereHas('branch', fn ($qq) => $qq->where('name', 'like', "%{$term}%"));
            });
        }

        return $query->orderByDesc('id')->paginate((int) $request->input('perPage', 25));
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

    /** Full funding history for a customer's profile view: every deposit, Fixed Deposit and savings account they hold. */
    public function funding(Customer $customer)
    {
        return [
            'deposits' => FdDeposit::where('customer_id', $customer->id)->orderByDesc('id')->get(),
            'fixed_deposits' => FixedDeposit::with('fundingProduct:id,name,code')->where('customer_id', $customer->id)->orderByDesc('id')->get(),
            'savings_accounts' => SavingsAccount::with('savingsProduct:id,name,code')->where('customer_id', $customer->id)->orderByDesc('id')->get(),
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

    /**
     * Customer uses SoftDeletes, so the credit_facilities.customer_id FK
     * (plain RESTRICT, no cascade) never actually fires here — a soft
     * delete is just an UPDATE, not a real DELETE. Without this check a
     * customer with a live loan/speed-draft/pawning ticket or FD could
     * vanish from every list/report while their facility keeps running,
     * silently (every read already guards with ?-> so nothing crashes —
     * it just quietly loses the customer's name everywhere).
     */
    public function destroy(Customer $customer)
    {
        if (DB::table('credit_facilities')->where('customer_id', $customer->id)->exists()) {
            return response()->json(['message' => 'This customer has loan/speed-draft/pawning facilities on record and cannot be deleted.'], 422);
        }
        if (FixedDeposit::where('customer_id', $customer->id)->exists() || FdDeposit::where('customer_id', $customer->id)->exists()) {
            return response()->json(['message' => 'This customer has fixed deposits on record and cannot be deleted.'], 422);
        }
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
        $data = $request->validate([
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
            // Blacklisting is admin-only and lives on its own page (BlacklistController).
            'status' => ['nullable', Rule::in(['Active', 'Inactive'])],
            'registered_as' => ['nullable', Rule::in(Customer::REGISTERED_AS)],
            'notes' => 'nullable|string',
        ]);

        // is_guarantor stays as a derived mirror of registered_as for existing queries.
        if (array_key_exists('registered_as', $data)) {
            $data['registered_as'] ??= 'Customer';
            $data['is_guarantor'] = $data['registered_as'] === 'Guarantor';
        }

        // A blacklisted customer's status can only be changed from the Blacklist page.
        if ($customer?->status === 'Blacklisted') {
            unset($data['status']);
        }

        return $data;
    }
}
