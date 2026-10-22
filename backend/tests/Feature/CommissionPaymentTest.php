<?php

namespace Tests\Feature;

use App\Models\CashAccount;
use App\Models\ChartOfAccount;
use App\Models\SalesExecutiveCommission;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Audit finding C-11: a commission payment must be capped to the earned amount, must not overdraw
 * the paying till, and must go through the one money writer (finance transaction + cashbook row).
 */
class CommissionPaymentTest extends TestCase
{
    use DatabaseTransactions;

    private CashAccount $till;

    private SalesExecutiveCommission $commission;

    protected function setUp(): void
    {
        parent::setUp();
        $admin = User::whereHas('role', fn ($q) => $q->where('slug', 'admin'))->first() ?? $this->markTestSkipped('no admin');
        $loanId = DB::table('credit_facilities')->value('id') ?? $this->markTestSkipped('needs a loan');
        $branchId = DB::table('credit_facilities')->where('id', $loanId)->value('branch_id');
        $execId = DB::table('users')->where('id', '!=', $admin->id)->value('id');
        $this->till = CashAccount::create([
            'code' => 'CM-' . Str::random(6), 'branch_id' => $branchId, 'account_type' => 'BranchMain', 'name' => 'CommTill ' . Str::random(4),
            'opening_balance' => 0, 'current_balance' => '1000.00', 'status' => 'Active',
        ]);
        ChartOfAccount::forCashTill($this->till->fresh());
        $this->commission = SalesExecutiveCommission::create([
            'credit_facility_id' => $loanId, 'sales_executive_id' => $execId, 'loan_type_id' => DB::table('loan_types')->value('id'), 'cycle_no' => 1, 'from_installment' => 0,
            'commission_type' => 'fixed', 'commission_value' => 500, 'commission_amount' => 500, 'required_installments' => 0,
        ]);
        $this->actingAs($admin, 'sanctum');
    }

    private function pay(float $amount)
    {
        return $this->postJson("/api/sales-executive-commissions/{$this->commission->id}/pay", [
            'payment_date' => now()->toDateString(), 'payment_amount' => $amount, 'payment_method' => 'Cash', 'cash_account_id' => $this->till->id,
        ]);
    }

    public function test_payment_above_the_earned_commission_is_refused(): void
    {
        $this->pay(5000000)->assertStatus(422);
        $this->assertNull($this->commission->fresh()->paid_at);
        $this->assertEquals(1000.0, (float) $this->till->fresh()->current_balance);
    }

    public function test_payment_cannot_overdraw_the_till(): void
    {
        $this->till->update(['current_balance' => '100.00']);
        $this->pay(500)->assertStatus(422);
        $this->assertNull($this->commission->fresh()->paid_at);
        $this->assertEquals(100.0, (float) $this->till->fresh()->current_balance);
    }

    public function test_valid_payment_goes_through_the_money_writer_and_void_restores_it(): void
    {
        $this->pay(500)->assertOk();

        $this->assertEquals(500.0, (float) $this->till->fresh()->current_balance, 'till reduced by 500');
        $txn = DB::table('finance_transactions')->where('source_module', 'SalesExecutiveCommission')->where('source_id', $this->commission->id)->first();
        $this->assertNotNull($txn, 'a finance transaction is recorded');
        $this->assertSame('Completed', $txn->status);
        $this->assertSame(1, DB::table('cashbook_entries')->where('journal_entry_id', $txn->journal_entry_id)->where('direction', 'Out')->count(), 'cashbook row mirrored');
        $je = DB::table('journal_lines')->where('journal_entry_id', $txn->journal_entry_id)->get();
        $this->assertEquals(500.0, (float) $je->firstWhere('account_id', ChartOfAccount::system('5200')->id)->debit, 'Dr 5200');
        $this->assertEquals(500.0, (float) $je->firstWhere('account_id', $this->till->fresh()->chart_of_account_id)->credit, 'Cr till leaf');

        $this->postJson("/api/sales-executive-commissions/{$this->commission->id}/void")->assertOk();
        $this->assertEquals(1000.0, (float) $this->till->fresh()->current_balance, 'void restores the till');
        $this->assertNull($this->commission->fresh()->paid_at);

        // Paying again after a void must move money again (not replay the voided transaction).
        $this->pay(500)->assertOk();
        $this->assertEquals(500.0, (float) $this->till->fresh()->current_balance, 're-payment after void reduces the till again');
    }
}
