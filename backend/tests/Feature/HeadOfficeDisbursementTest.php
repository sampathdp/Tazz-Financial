<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Loans are paid out only from a Head Office bank account; another branch's loan settles through the inter-branch accounts. */
class HeadOfficeDisbursementTest extends TestCase
{
    use DatabaseTransactions;

    private int $branchId;
    private int $loanId;
    private BankAccount $hoBank;
    private BankAccount $branchBank;

    protected function setUp(): void
    {
        parent::setUp();
        config(['finance.disburse_from_head_office_bank' => true, 'finance.strict_till_purposes' => true]);
        $admin = User::whereHas('role', fn ($q) => $q->where('slug', 'admin'))->first() ?? $this->markTestSkipped('no admin');
        $this->actingAs($admin, 'sanctum');
        $ho = Branch::where('is_head_office', true)->first() ?? $this->markTestSkipped('no head office');

        $this->branchId = DB::table('branches')->insertGetId(['name' => 'HOD ' . Str::random(3), 'code' => 'HD' . Str::random(3), 'branch_type' => 'Branch', 'status' => 'Active', 'created_at' => now(), 'updated_at' => now()]);
        $customerId = DB::table('customers')->insertGetId(['public_uuid' => (string) Str::uuid(), 'branch_id' => $this->branchId, 'code' => 'C' . Str::random(7), 'full_name' => 'HOD Customer', 'status' => 'Active', 'created_at' => now(), 'updated_at' => now()]);
        $typeId = DB::table('loan_types')->insertGetId(['name' => 'HOD Type', 'code' => 'HT' . Str::random(4), 'interest_method' => 'flat', 'rate_period' => 'monthly', 'grace_period_days' => 5, 'default_charge_rate_percent' => '0.000', 'processing_fee_percent' => 0, 'commission_type' => 'fixed', 'commission_value' => '0.00', 'status' => 'Active', 'created_at' => now(), 'updated_at' => now()]);
        $this->loanId = DB::table('credit_facilities')->insertGetId(['public_uuid' => (string) Str::uuid(), 'code' => 'L' . Str::random(9), 'facility_type' => 'Loan', 'branch_id' => $this->branchId, 'customer_id' => $customerId, 'principal_amount' => '50000.00', 'interest_rate_percent' => '2.000', 'term_months' => 4, 'rate_period' => 'monthly', 'status' => 'Approved', 'approved_by' => $admin->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('loan_facility_details')->insert(['credit_facility_id' => $this->loanId, 'loan_type_id' => $typeId, 'interest_method' => 'flat', 'installment_frequency' => 'monthly', 'down_payment' => '0.00']);

        $bankId = DB::table('banks')->value('id') ?? $this->markTestSkipped('no banks');
        $make = fn (int $branch, string $name) => BankAccount::withoutGlobalScopes()->create(['branch_id' => $branch, 'name' => $name, 'bank_id' => $bankId, 'account_number' => 'T' . Str::random(10), 'account_type' => 'Current', 'currency' => 'LKR', 'opening_balance' => 0, 'current_balance' => 100000, 'status' => 'Active']);
        $this->hoBank = $make($ho->id, 'HO Operation Test');
        $this->branchBank = $make($this->branchId, 'Branch Bank Test');
        ChartOfAccount::forBankAccount($this->hoBank);
        ChartOfAccount::forBankAccount($this->branchBank);
    }

    private function disburse(array $o)
    {
        return $this->postJson("/api/loans/{$this->loanId}/disburse", array_merge(['first_installment_date' => now()->addMonth()->toDateString(), 'disbursed_at' => now()->toDateString()], $o));
    }

    public function test_cash_and_other_branch_bank_accounts_are_refused(): void
    {
        $this->disburse(['disbursement_method' => 'Cash'])->assertStatus(422);
        $this->disburse(['disbursement_method' => 'Bank', 'disbursement_bank_account_id' => $this->branchBank->id])->assertStatus(422);
        $this->assertSame('Approved', DB::table('credit_facilities')->where('id', $this->loanId)->value('status'));
    }

    public function test_head_office_bank_pays_another_branchs_loan_through_inter_branch_accounts(): void
    {
        $this->disburse(['disbursement_method' => 'Bank', 'disbursement_bank_account_id' => $this->hoBank->id])->assertStatus(200);
        $this->assertSame('Active', DB::table('credit_facilities')->where('id', $this->loanId)->value('status'));
        $this->assertSame('50000.00', (string) $this->hoBank->fresh()->current_balance, 'the Head Office bank paid it out');

        $entries = JournalEntry::where('source_type', 'Loan')->where('source_id', $this->loanId)->get();
        $this->assertCount(2, $entries, 'the loan branch entry plus the Head Office companion');
        $this->assertTrue($entries->contains(fn ($e) => $e->counterparty_branch_id !== null), 'the companion names the other branch');
    }
}
