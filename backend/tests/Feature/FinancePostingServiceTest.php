<?php

namespace Tests\Feature;

use App\Exceptions\InsufficientFundsException;
use App\Models\Branch;
use App\Models\CashAccount;
use App\Models\ChartOfAccount;
use App\Models\FinanceTransaction;
use App\Support\FinancePostingService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Exercises the single money-movement writer against the real schema. Wrapped
 * in DatabaseTransactions so every test rolls back — nothing is kept, the
 * same way the repo's other DB-touching checks work.
 */
class FinancePostingServiceTest extends TestCase
{
    use DatabaseTransactions;

    private CashAccount $till;

    private ChartOfAccount $tillLeaf;

    private ChartOfAccount $principal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->principal = ChartOfAccount::where('code', '1100')->first()
            ?? $this->markTestSkipped('Chart of accounts not seeded in this environment.');

        $branch = Branch::firstOrCreate(
            ['code' => 'FTXT'],
            ['name' => 'Finance Test Branch', 'branch_type' => 'Branch', 'status' => 'Active']
        );

        $this->till = CashAccount::create([
            'code' => 'FTXT-CASH', 'branch_id' => $branch->id, 'account_type' => 'BranchMain',
            'name' => 'Finance Test Till', 'opening_balance' => 0, 'current_balance' => '100000.00', 'status' => 'Active',
        ]);
        $this->tillLeaf = ChartOfAccount::forCashTill($this->till->fresh());
    }

    private function disburse(string $amount, ?string $key = null): FinanceTransaction
    {
        return FinancePostingService::execute([
            'txn_type' => 'DISBURSEMENT',
            'amount' => $amount,
            'entry_date' => now()->toDateString(),
            'branch_id' => $this->till->branch_id,
            'movements' => [['account_type' => 'cash', 'account_id' => $this->till->id, 'delta' => '-' . $amount]],
            'gl_lines' => [
                ['account_id' => $this->principal->id, 'debit' => $amount, 'credit' => 0],
                ['account_id' => $this->tillLeaf->id, 'debit' => 0, 'credit' => $amount],
            ],
            'idempotency_key' => $key,
            'executed_by' => 1,
        ]);
    }

    public function test_it_moves_cash_and_posts_a_linked_journal_entry(): void
    {
        $txn = $this->disburse('40000.00');

        $this->assertStringStartsWith('FTX-', $txn->code);
        $this->assertSame('Out', $txn->direction);
        $this->assertSame('cash', $txn->source_account_type);
        $this->assertSame($this->till->id, (int) $txn->source_account_id);
        $this->assertNotNull($txn->journal_entry_id);
        $this->assertSame($txn->id, (int) $txn->journalEntry->source_id);
        $this->assertSame('60000.00', (string) $this->till->fresh()->current_balance);

        $glDelta = ChartOfAccount::balancesAsOf(now()->toDateString())->get($this->tillLeaf->id)->balance;
        $this->assertSame('-40000.00', $glDelta);
    }

    public function test_a_replayed_idempotency_key_returns_the_original_and_moves_nothing_twice(): void
    {
        $first = $this->disburse('25000.00', 'dup-key-1');
        $second = $this->disburse('25000.00', 'dup-key-1');

        $this->assertSame($first->id, $second->id);
        $this->assertSame('75000.00', (string) $this->till->fresh()->current_balance);
        $this->assertSame(1, FinanceTransaction::where('idempotency_key', 'dup-key-1')->count());
    }

    public function test_it_blocks_an_overdraw_and_leaves_the_balance_untouched(): void
    {
        $this->expectException(InsufficientFundsException::class);
        try {
            $this->disburse('150000.00');
        } finally {
            $this->assertSame('100000.00', (string) $this->till->fresh()->current_balance);
        }
    }

    public function test_reverse_is_symmetric_and_keeps_both_rows(): void
    {
        $txn = $this->disburse('30000.00');
        $reversal = FinancePostingService::reverse($txn->fresh(), 'test', 1);

        $this->assertSame('REVERSAL', $reversal->txn_type);
        $this->assertSame($txn->id, (int) $reversal->reversal_of_id);
        $this->assertSame('Reversed', $txn->fresh()->status);
        $this->assertSame('100000.00', (string) $this->till->fresh()->current_balance);
        $this->assertSame('Reversed', $txn->journalEntry->fresh()->status);
    }
}
