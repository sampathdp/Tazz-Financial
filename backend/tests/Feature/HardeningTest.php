<?php

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\User;
use App\Rules\Money;
use App\Rules\StrongPassword;
use App\Support\DocStore;
use App\Support\TransactionalDataCleaner;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/** Regression tests for the cross-cutting fixes from docs/audit/full-system-audit.md. */
class HardeningTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): User
    {
        return User::whereHas('role', fn ($q) => $q->where('slug', 'admin'))->first() ?? $this->markTestSkipped('No admin user seeded.');
    }

    public function test_money_rule_accepts_real_amounts_and_rejects_absurd_or_sub_cent_ones(): void
    {
        foreach ([1, 0.01, '1500.50', '9999999999999.99', 100] as $ok) {
            $this->assertTrue(Validator::make(['a' => $ok], ['a' => ['numeric', new Money]])->passes(), "should accept {$ok}");
        }
        foreach ([1e20, '10.005', '12345678901234.00', 1.0E+25] as $bad) {
            $this->assertTrue(Validator::make(['a' => $bad], ['a' => ['numeric', new Money]])->fails(), 'should reject ' . json_encode($bad));
        }
    }

    public function test_an_absurd_payment_amount_is_a_validation_error_not_a_500(): void
    {
        $this->actingAs($this->admin(), 'sanctum');
        $res = $this->postJson('/api/cashbook-entries', [
            'cash_account_id' => DB::table('cash_accounts')->value('id') ?? 1, 'entry_date' => now()->toDateString(), 'direction' => 'In',
            'category' => 'Other', 'amount' => 1e20, 'offset_account_id' => ChartOfAccount::system('3015')->id,
        ]);
        $this->assertSame(422, $res->status(), $res->getContent());
    }

    public function test_password_policy(): void
    {
        $rule = fn (string $pw) => Validator::make(['p' => $pw], ['p' => [new StrongPassword('jane.doe@example.com', 'Jane Doe')]]);
        $this->assertTrue($rule('short')->fails());
        $this->assertTrue($rule('password123')->fails());
        $this->assertTrue($rule('aaaaaaaaaaaa')->fails());
        $this->assertTrue($rule('abcdefghijkl')->fails());
        $this->assertTrue($rule('xx-jane.doe-xx-9')->fails(), 'contains the email local part');
        $this->assertTrue($rule('correct-horse-battery')->passes());
    }

    public function test_creating_a_user_with_a_weak_password_is_refused(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin, 'sanctum');
        $res = $this->postJson('/api/users', [
            'name' => 'Weak Pw', 'email' => 'weak' . uniqid() . '@example.test', 'password' => '123456',
            'role_id' => $admin->role_id, 'branch_id' => $admin->branch_id,
        ]);
        $this->assertSame(422, $res->status());
        $this->assertArrayHasKey('password', $res->json('errors') ?? []);
    }

    public function test_rejecting_an_expense_requires_approver_rights_even_without_a_pending_request(): void
    {
        $admin = $this->admin();
        $roleId = DB::table('roles')->insertGetId(['name' => 'NoApprove', 'slug' => 'noapp_' . uniqid(), 'is_system' => 0, 'can_view_all_branches' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $user = User::forceCreate(['name' => 'No Approver', 'email' => 'noapp' . uniqid() . '@example.test', 'password' => bcrypt('x'), 'role_id' => $roleId, 'branch_id' => $admin->branch_id, 'status' => 'Active']);
        $categoryId = DB::table('expense_categories')->value('id');
        if (!$categoryId) {
            $this->markTestSkipped('No expense category seeded.');
        }
        $expenseId = DB::table('expenses')->insertGetId([
            'code' => 'EXP-' . uniqid(), 'branch_id' => $admin->branch_id, 'expense_category_id' => $categoryId, 'amount' => '10.00',
            'expense_date' => now()->toDateString(), 'status' => 'Pending', 'requested_by' => $admin->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actingAs($user->fresh(), 'sanctum');
        $this->assertSame(403, $this->putJson("/api/expenses/{$expenseId}/reject", [])->status());
    }

    public function test_private_documents_are_not_web_reachable_and_legacy_public_files_still_serve(): void
    {
        Storage::fake('documents');
        Storage::fake('public');
        $new = DocStore::store(UploadedFile::fake()->image('nic.png'), 'customers/1/documents');
        $this->assertTrue(Storage::disk('documents')->exists($new));
        $this->assertFalse(Storage::disk('public')->exists($new), 'new uploads must not land on the web-reachable disk');

        Storage::disk('public')->put('customers/2/documents/old.png', 'legacy');
        $this->assertTrue(DocStore::exists('customers/2/documents/old.png'), 'a file uploaded before the move is still served');
        $this->assertSame(1, DocStore::privatize());
        $this->assertFalse(Storage::disk('public')->exists('customers/2/documents/old.png'));
        $this->assertTrue(Storage::disk('documents')->exists('customers/2/documents/old.png'));
    }

    public function test_the_cleaner_wipes_everything_not_on_the_keep_list(): void
    {
        $wipe = TransactionalDataCleaner::tables();
        foreach (['cash_sessions', 'finance_transactions', 'finance_requests', 'finance_audit_events', 'idempotency_keys', 'manual_journal_entries', 'borrowings', 'credit_facilities', 'journal_entries', 'number_sequences'] as $table) {
            $this->assertContains($table, $wipe, "{$table} must be wiped by a clean");
        }
        foreach (['users', 'roles', 'branches', 'chart_of_accounts', 'cash_accounts', 'bank_accounts', 'loan_types', 'customers', 'migrations'] as $table) {
            $this->assertNotContains($table, $wipe, "{$table} is setup data and must survive a clean");
        }
        $this->assertContains('customers', TransactionalDataCleaner::tables(true), 'customers can be included explicitly');
    }

    public function test_reversing_a_collection_after_the_cash_was_spent_is_refused_not_left_negative(): void
    {
        $branchId = DB::table('branches')->insertGetId(['name' => 'RV', 'code' => 'RV' . substr(uniqid(), -4), 'branch_type' => 'Branch', 'status' => 'Active', 'created_at' => now(), 'updated_at' => now()]);
        $till = \App\Models\CashAccount::create(['code' => 'RV-' . uniqid(), 'branch_id' => $branchId, 'account_type' => 'BranchMain', 'name' => 'RV Till', 'opening_balance' => 0, 'current_balance' => '0.00', 'status' => 'Active']);
        $leaf = ChartOfAccount::forCashTill($till->fresh());
        $income = ChartOfAccount::system('4900')->id;
        $in = \App\Support\FinancePostingService::execute([
            'txn_type' => 'COLLECTION', 'amount' => '500.00', 'entry_date' => now()->toDateString(), 'branch_id' => $branchId,
            'movements' => [['account_type' => 'cash', 'account_id' => $till->id, 'delta' => '500.00']],
            'gl_lines' => [['account_id' => $leaf->id, 'debit' => '500.00', 'credit' => 0], ['account_id' => $income, 'debit' => 0, 'credit' => '500.00']],
        ]);
        \App\Support\FinancePostingService::execute([
            'txn_type' => 'CASH_OUT', 'amount' => '500.00', 'entry_date' => now()->toDateString(), 'branch_id' => $branchId,
            'movements' => [['account_type' => 'cash', 'account_id' => $till->id, 'delta' => '-500.00']],
            'gl_lines' => [['account_id' => ChartOfAccount::system('5010')->id, 'debit' => '500.00', 'credit' => 0], ['account_id' => $leaf->id, 'debit' => 0, 'credit' => '500.00']],
        ]);
        $this->expectException(\App\Exceptions\FinancePostingException::class);
        try {
            \App\Support\FinancePostingService::reverse($in, 'test void');
        } finally {
            $this->assertSame('0.00', (string) $till->fresh()->current_balance, 'the till must not be driven negative');
        }
    }

    public function test_hard_deleting_a_loan_is_blocked_in_production_unless_enabled(): void
    {
        $this->actingAs($this->admin(), 'sanctum');
        $loanId = DB::table('credit_facilities')->value('id');
        if (!$loanId) {
            $this->markTestSkipped('No loan to purge.');
        }
        $this->app['env'] = 'production';
        config(['app.loan_purge_enabled' => false]);
        $this->assertSame(403, $this->deleteJson("/api/loans/{$loanId}/purge")->status());
    }
}
