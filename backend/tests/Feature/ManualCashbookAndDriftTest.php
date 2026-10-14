<?php

namespace Tests\Feature;

use App\Models\CashAccount;
use App\Models\ChartOfAccount;
use App\Models\FinanceTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 9b/9c — a manual cashbook entry now posts a balanced GL entry
 * through FinancePostingService (closing the old "till moves, no GL" gap),
 * and finance:check-drift flags a cache-vs-GL mismatch. DatabaseTransactions.
 */
class ManualCashbookAndDriftTest extends TestCase
{
    use DatabaseTransactions;

    private CashAccount $till;

    private User $admin;

    private int $offsetAccountId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::whereHas('role', fn ($q) => $q->where('slug', 'admin'))->first()
            ?? $this->markTestSkipped('No admin user seeded.');
        $this->offsetAccountId = ChartOfAccount::where('account_type', 'Expense')->value('id')
            ?? $this->markTestSkipped('Chart of accounts not seeded.');

        $branchId = DB::table('branches')->insertGetId(['name' => 'MC', 'code' => 'MC' . Str::random(3), 'branch_type' => 'Branch', 'status' => 'Active', 'created_at' => now(), 'updated_at' => now()]);
        $this->till = CashAccount::create(['code' => 'MC-CASH-' . Str::random(3), 'branch_id' => $branchId, 'account_type' => 'BranchMain', 'name' => 'MC Till', 'opening_balance' => 0, 'current_balance' => '10000.00', 'status' => 'Active']);
        ChartOfAccount::forCashTill($this->till);
    }

    public function test_manual_out_entry_posts_gl_and_moves_the_till(): void
    {
        $this->actingAs($this->admin, 'sanctum');

        $res = $this->postJson('/api/cashbook-entries', [
            'cash_account_id' => $this->till->id,
            'entry_date' => now()->toDateString(),
            'direction' => 'Out',
            'category' => 'Expense',
            'description' => 'Tea money',
            'amount' => '750.00',
            'offset_account_id' => $this->offsetAccountId,
        ])->assertStatus(201);

        $this->assertSame('9250.00', (string) $this->till->fresh()->current_balance);
        $this->assertSame('Expense', $res->json('category'));

        $txn = FinanceTransaction::where('source_module', 'ManualCashbook')->latest('id')->first();
        $this->assertNotNull($txn);
        $this->assertSame('ADJUSTMENT', $txn->txn_type);
        $this->assertNotNull($txn->journal_entry_id);
        $this->assertDatabaseHas('journal_lines', ['journal_entry_id' => $txn->journal_entry_id, 'account_id' => $this->offsetAccountId]);

        // Deleting the mirror reverses (does not hard-delete) — balance restored.
        $entryId = $res->json('id');
        \App\Models\FinanceSetting::put('reversal_requires_approval', '1');
        $this->deleteJson("/api/cashbook-entries/{$entryId}")->assertStatus(422); // maker-checker applies to deletes too
        \App\Models\FinanceSetting::put('reversal_requires_approval', '0');
        $this->deleteJson("/api/cashbook-entries/{$entryId}")->assertNoContent();
        $this->assertSame('10000.00', (string) $this->till->fresh()->current_balance);
        $this->assertSame('Reversed', $txn->fresh()->status);
    }

    public function test_manual_entry_requires_an_offset_account(): void
    {
        $this->actingAs($this->admin, 'sanctum');
        $this->postJson('/api/cashbook-entries', [
            'cash_account_id' => $this->till->id,
            'entry_date' => now()->toDateString(),
            'direction' => 'In',
            'category' => 'Other',
            'amount' => '100.00',
        ])->assertStatus(422)->assertJsonValidationErrors('offset_account_id');
    }

    public function test_check_drift_flags_a_manual_cache_change(): void
    {
        // Move the cache without touching the GL — exactly what the job catches.
        DB::table('cash_accounts')->where('id', $this->till->id)->update(['current_balance' => '12345.00']);

        $this->artisan('finance:check-drift')
            ->assertExitCode(1);

        $this->assertTrue(
            \App\Models\FinanceAuditEvent::where('action', 'ledger.drift_detected')->exists()
        );
    }
}
