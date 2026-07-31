<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\LoanType;
use App\Models\Role;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run()
    {
        $branch = $this->seedHeadOffice();
        $adminRole = $this->seedAdminRole();
        $this->seedExampleRoles();
        $admin = $this->seedAdminUser($adminRole, $branch);
        $this->seedSampleStaffUsers($branch);

        $this->seedSampleBranches($branch);
        $this->seedSampleLoanTypes();
        $this->seedSampleCustomers($branch, $admin);
    }

    private function seedHeadOffice(): Branch
    {
        return Branch::updateOrCreate(['code' => 'HO'], [
            'name' => 'Head Office',
            'address' => '',
            'phone' => '',
            'email' => '',
            'is_head_office' => true,
            'status' => 'Active',
        ]);
    }

    private function seedAdminRole(): Role
    {
        return Role::updateOrCreate(['slug' => 'admin'], [
            'name' => 'Administrator',
            'description' => 'Full, implicit access to every module. Cannot be edited or deleted.',
            'is_system' => true,
            'can_view_all_branches' => true,
        ]);
    }

    /** A couple of starter roles so the Role Management screen isn't empty on first run. */
    private function seedExampleRoles(): void
    {
        $manager = Role::updateOrCreate(['slug' => 'branch_manager'], [
            'name' => 'Branch Manager',
            'description' => 'Runs day-to-day operations for a single branch.',
            'is_system' => false,
            'can_view_all_branches' => false,
        ]);
        Permissions::saveForRole($manager, [
            'Dashboard' => ['View' => true],
            'Branch Management' => ['View' => true],
            'User Management' => ['View' => true, 'Create' => true, 'Edit' => true],
            'Customer Management' => ['View' => true, 'Create' => true, 'Edit' => true, 'Delete' => true],
            // Loan product catalog is HQ-configured; branches only need to see it when issuing loans.
            'Loan Types' => ['View' => true],
            'Loan Applications' => ['View' => true, 'Create' => true, 'Edit' => true, 'Delete' => true, 'Approve' => true],
            'Active Loans' => ['View' => true, 'Approve' => true],
            // Approves/rejects the Cashier's full-settlement requests so a loan can close.
            // Edit lets them skip an installment (pushed to the back of the schedule).
            'Loan Payments' => ['View' => true, 'Create' => true, 'Edit' => true, 'Delete' => true, 'Approve' => true],
            'Sales Executive Commissions' => ['View' => true],
            'Overdue Loans' => ['View' => true],
            'Bank Accounts' => ['View' => true, 'Create' => true, 'Edit' => true],
            'Bank Transactions' => ['View' => true, 'Create' => true, 'Delete' => true],
            'Bank Reconciliation' => ['View' => true, 'Create' => true, 'Edit' => true],
            // Funding products are HQ-configured, same rationale as Loan Types above.
            'Funding Products' => ['View' => true],
            'Deposits' => ['View' => true, 'Create' => true, 'Delete' => true],
            'Fixed Deposits' => ['View' => true, 'Create' => true, 'Edit' => true],
            'Funding Reports' => ['View' => true],
        ]);

        $staff = Role::updateOrCreate(['slug' => 'staff'], [
            'name' => 'Staff',
            'description' => 'General branch staff with read access to their own branch.',
            'is_system' => false,
            'can_view_all_branches' => false,
        ]);
        Permissions::saveForRole($staff, [
            'Dashboard' => ['View' => true],
            'Customer Management' => ['View' => true, 'Create' => true, 'Edit' => true],
            'Loan Types' => ['View' => true],
            'Loan Applications' => ['View' => true, 'Create' => true, 'Edit' => true],
            'Active Loans' => ['View' => true],
            'Loan Payments' => ['View' => true, 'Create' => true, 'Edit' => true],
            'Overdue Loans' => ['View' => true],
            'Deposits' => ['View' => true, 'Create' => true],
            'Fixed Deposits' => ['View' => true, 'Create' => true],
        ]);

        // Cashier — the only role allowed to apply for a full loan settlement (see
        // LoanPaymentController::store()'s explicit role-slug check); day-to-day
        // payment recording otherwise matches Staff.
        $cashier = Role::updateOrCreate(['slug' => 'cashier'], [
            'name' => 'Cashier',
            'description' => 'Records loan payments at the counter, including full settlements (which a Branch Manager must approve).',
            'is_system' => false,
            'can_view_all_branches' => false,
        ]);
        Permissions::saveForRole($cashier, [
            'Dashboard' => ['View' => true],
            'Customer Management' => ['View' => true],
            'Loan Applications' => ['View' => true],
            'Active Loans' => ['View' => true],
            'Loan Payments' => ['View' => true, 'Create' => true, 'Edit' => true],
            // Records counter deposits and pays out monthly FD interest/withdrawals (Edit).
            'Deposits' => ['View' => true, 'Create' => true],
            'Fixed Deposits' => ['View' => true, 'Create' => true, 'Edit' => true],
        ]);

        // Marketing Executive — the field/sales role. Members of this role are the
        // ones offered in the "Loan Officer" picklist on Loan Applications and the
        // Loan Detail "Assign Officers" panel (see UserController::marketingExecutives()).
        $marketing = Role::updateOrCreate(['slug' => 'marketing_executive'], [
            'name' => 'Marketing Executive',
            'description' => 'Sources customers and loan applications in the field.',
            'is_system' => false,
            'can_view_all_branches' => false,
        ]);
        Permissions::saveForRole($marketing, [
            'Dashboard' => ['View' => true],
            'Customer Management' => ['View' => true, 'Create' => true, 'Edit' => true],
            'Loan Types' => ['View' => true],
            'Loan Applications' => ['View' => true, 'Create' => true],
            'Active Loans' => ['View' => true],
        ]);
    }

    /** Sample Cashier and Marketing Executive users so the Loan Officer /
     *  sales-executive picklists aren't empty on first run. Idempotent by
     *  email — re-running the seeder never touches an existing account. */
    private function seedSampleStaffUsers(Branch $branch): void
    {
        $cashier = Role::where('slug', 'cashier')->first();
        $marketing = Role::where('slug', 'marketing_executive')->first();

        $people = [
            ['email' => 'cashier1@absl.local', 'name' => 'H.M. Kaushalya', 'role' => $cashier],
            ['email' => 'cashier2@absl.local', 'name' => 'P.G. Nadeesha', 'role' => $cashier],
            ['email' => 'marketing1@absl.local', 'name' => 'S.A. Ruwan', 'role' => $marketing],
            ['email' => 'marketing2@absl.local', 'name' => 'D.M. Iresha', 'role' => $marketing],
            ['email' => 'marketing3@absl.local', 'name' => 'T.K. Chamara', 'role' => $marketing],
        ];

        foreach ($people as $p) {
            if (!$p['role'] || User::where('email', $p['email'])->exists()) {
                continue;
            }
            User::create([
                'email' => $p['email'],
                'name' => $p['name'],
                'password' => Hash::make('password'),
                'role_id' => $p['role']->id,
                'branch_id' => $branch->id,
                'status' => 'Active',
            ]);
        }
    }

    private function seedAdminUser(Role $adminRole, Branch $branch): User
    {
        // Only set on first creation — re-running the seeder must never clobber a real admin's password.
        $existing = User::where('email', 'admin@absl.local')->first();
        if ($existing) {
            return $existing;
        }

        return User::create([
            'email' => 'admin@absl.local',
            'name' => 'System Administrator',
            'password' => Hash::make('password'),
            'role_id' => $adminRole->id,
            'branch_id' => $branch->id,
            'status' => 'Active',
        ]);
    }

    /** A second branch + a center under it, so Branch Management isn't a single empty row. */
    private function seedSampleBranches(Branch $headOffice): void
    {
        $kandy = Branch::updateOrCreate(['code' => 'KND'], [
            'name' => 'Kandy', 'type' => 'Branch', 'status' => 'Active',
        ]);
        Branch::updateOrCreate(['code' => 'PRD-C'], [
            'name' => 'Peradeniya Center', 'type' => 'Center', 'parent_id' => $kandy->id, 'status' => 'Active',
        ]);
    }

    /** The loan product tree from the SME product-creation notes: top-level
     *  products plus SME → Daily / Micro → Weekly / Monthly. */
    private function seedSampleLoanTypes(): void
    {
        LoanType::updateOrCreate(['code' => 'LEASE'], [
            'name' => 'Leasing', 'is_product' => true, 'frequency' => 'monthly',
            'interest_method' => 'flat', 'interest_rate_percent' => 2.5, 'rate_period' => 'monthly',
            'min_amount' => 100000, 'max_amount' => 10000000, 'min_term_months' => 12, 'max_term_months' => 60,
        ]);
        LoanType::updateOrCreate(['code' => 'CONSUMER'], [
            'name' => 'Consumer Loan', 'is_product' => true, 'frequency' => 'monthly',
            'interest_method' => 'reducing_balance', 'interest_rate_percent' => 3, 'rate_period' => 'monthly',
            'min_amount' => 10000, 'max_amount' => 1000000, 'min_term_months' => 3, 'max_term_months' => 36,
        ]);
        LoanType::updateOrCreate(['code' => 'MORTGAGE'], [
            'name' => 'Mortgage', 'is_product' => true, 'frequency' => 'monthly',
            'interest_method' => 'reducing_balance', 'interest_rate_percent' => 1.8, 'rate_period' => 'monthly',
            'min_amount' => 1000000, 'max_amount' => 50000000, 'min_term_months' => 24, 'max_term_months' => 240,
        ]);
        LoanType::updateOrCreate(['code' => 'REALESTATE'], [
            'name' => 'Real Estate', 'is_product' => true, 'frequency' => 'monthly',
            'interest_method' => 'reducing_balance', 'interest_rate_percent' => 2, 'rate_period' => 'monthly',
            'min_amount' => 2000000, 'max_amount' => 100000000, 'min_term_months' => 24, 'max_term_months' => 300,
        ]);

        $sme = LoanType::updateOrCreate(['code' => 'SME'], ['name' => 'SME', 'is_product' => false]);
        LoanType::updateOrCreate(['code' => 'SME-DAILY'], [
            'name' => 'Daily', 'parent_id' => $sme->id, 'is_product' => true, 'frequency' => 'daily',
            'interest_method' => 'flat', 'interest_rate_percent' => 3, 'rate_period' => 'monthly',
            'min_amount' => 10000, 'max_amount' => 500000, 'min_term_months' => 1, 'max_term_months' => 12,
        ]);
        $micro = LoanType::updateOrCreate(['code' => 'SME-MICRO'], ['name' => 'Micro', 'parent_id' => $sme->id, 'is_product' => false]);
        LoanType::updateOrCreate(['code' => 'SME-MICRO-WK'], [
            'name' => 'Weekly', 'parent_id' => $micro->id, 'is_product' => true, 'frequency' => 'weekly',
            'interest_method' => 'reducing_balance', 'interest_rate_percent' => 4, 'rate_period' => 'monthly',
            'min_amount' => 5000, 'max_amount' => 100000, 'min_term_months' => 1, 'max_term_months' => 6,
        ]);
        LoanType::updateOrCreate(['code' => 'SME-MICRO-MO'], [
            'name' => 'Monthly', 'parent_id' => $micro->id, 'is_product' => true, 'frequency' => 'monthly',
            'interest_method' => 'reducing_balance', 'interest_rate_percent' => 3.5, 'rate_period' => 'monthly',
            'min_amount' => 5000, 'max_amount' => 150000, 'min_term_months' => 1, 'max_term_months' => 12,
        ]);
    }

    /** A handful of customers covering the occupation-type variants, so the
     *  Loan Applications customer/guarantor pickers aren't empty on first run. */
    private function seedSampleCustomers(Branch $headOffice, User $admin): void
    {
        $people = [
            ['nic' => '892152977V', 'name' => 'D.P.P.K.S. Silva', 'phone' => '0771234567', 'occ' => 'Employed', 'employer' => 'Ceylon Textiles Ltd', 'job' => 'Supervisor', 'income' => 85000],
            ['nic' => '901234567V', 'name' => 'W.A. Perera', 'phone' => '0712345678', 'occ' => 'BusinessOwner', 'biz' => 'Perera Hardware Store', 'income' => 150000],
            ['nic' => '855566778V', 'name' => 'R.M. Fernando', 'phone' => '0783456789', 'occ' => 'SelfEmployed', 'biz' => 'Fernando Tailoring', 'income' => 60000],
            ['nic' => '927788990V', 'name' => 'K.G. Jayasuriya', 'phone' => '0754567890', 'occ' => 'Employed', 'employer' => 'Horana Plantations', 'job' => 'Field Officer', 'income' => 55000],
            ['nic' => '885544332V', 'name' => 'N.T. Bandara', 'phone' => '0765678901', 'occ' => 'Retired', 'income' => 30000],
            ['nic' => '910011223V', 'name' => 'S.A.D.C. Silva', 'phone' => '0776789012', 'occ' => 'Employed', 'employer' => 'Ceylon Textiles Ltd', 'job' => 'Accountant', 'income' => 95000, 'guarantor' => true],
        ];

        foreach ($people as $i => $p) {
            Customer::updateOrCreate(['nic_no' => $p['nic']], [
                'branch_id' => $headOffice->id,
                'code' => 'CUS-' . str_pad((string) ($i + 1), 6, '0', STR_PAD_LEFT),
                'full_name' => $p['name'],
                'phone' => $p['phone'],
                'occupation_type' => $p['occ'],
                'employer_name' => $p['employer'] ?? null,
                'job_title' => $p['job'] ?? null,
                'business_name' => $p['biz'] ?? null,
                'monthly_income' => $p['income'],
                'is_guarantor' => $p['guarantor'] ?? false,
                'status' => 'Active',
                'created_by' => $admin->id,
            ]);
        }
    }
}
