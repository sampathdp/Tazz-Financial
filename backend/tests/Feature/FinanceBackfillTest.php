<?php

namespace Tests\Feature;

use App\Models\CashAccount;
use App\Models\FinanceTransaction;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 9d prep — finance:backfill-transactions synthesises append-only
 * finance_transactions for pre-Finance-module history without moving a
 * balance or posting GL, idempotently. DatabaseTransactions.
 */
class FinanceBackfillTest extends TestCase
{
    use DatabaseTransactions;

    public function test_backfills_a_legacy_cashbook_row_once(): void
    {
        $branchId = DB::table('branches')->insertGetId(['name' => 'BF', 'code' => 'BF' . Str::random(3), 'branch_type' => 'Branch', 'status' => 'Active', 'created_at' => now(), 'updated_at' => now()]);
        $till = CashAccount::create(['code' => 'BF-CASH-' . Str::random(3), 'branch_id' => $branchId, 'account_type' => 'BranchMain', 'name' => 'BF Till', 'opening_balance' => 0, 'current_balance' => '4321.00', 'status' => 'Active']);

        $legacyId = DB::table('cashbook_entries')->insertGetId([
            'branch_id' => $branchId, 'cash_account_id' => $till->id, 'entry_date' => now()->toDateString(),
            'direction' => 'In', 'category' => 'Loan Collection', 'amount' => '1500.00', 'balance_after' => '4321.00',
            'source_type' => 'LoanPayment', 'source_id' => 999999, 'description' => 'Legacy receipt',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $before = (string) $till->fresh()->current_balance;

        $this->artisan('finance:backfill-transactions --apply --source=cashbook')->assertExitCode(0);

        // No balance movement, no GL — append-only.
        $this->assertSame($before, (string) $till->fresh()->current_balance);

        $txn = FinanceTransaction::where('idempotency_key', "backfill-cashbook-{$legacyId}")->first();
        $this->assertNotNull($txn);
        $this->assertSame('COLLECTION', $txn->txn_type);
        $this->assertSame('1500.00', (string) $txn->amount);
        $this->assertTrue((bool) ($txn->meta['backfilled'] ?? false));
        $this->assertSame($till->id, $txn->dest_account_id);

        // Idempotent — a second run creates nothing more.
        $this->artisan('finance:backfill-transactions --apply --source=cashbook')->assertExitCode(0);
        $this->assertSame(1, FinanceTransaction::where('idempotency_key', "backfill-cashbook-{$legacyId}")->count());
    }
}
