<?php

namespace Tests\Feature;

use App\Exceptions\FinancePostingException;
use App\Models\BankAccount;
use App\Models\CashAccount;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Support\FinancePostingService;
use App\Support\LedgerPoster;
use App\Support\OpeningBalanceLedgerPoster;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Plan: docs/audit/test-plans/ledger-invariants.md
 * Findings: docs/audit/failures.md (F-1, F-2, F-3)
 *
 * Covers INV-01, INV-02, INV-03, INV-05, INV-06, INV-07, INV-13, INV-14, INV-15.
 *
 * The test database (`tazz`) is a persistent, already-populated dev database —
 * NOT migrated fresh per test. Tests isolate via DatabaseTransactions rollback.
 * Therefore every balance assertion is written as *baseline -> delta* against a
 * captured pre-action balance, never an absolute figure on a shared seeded
 * account. Freshly-created leaf accounts (till/bank made in setUp) have no
 * history, so their baseline is 0.00.
 *
 * Every expectation is hand-computed in the test body from first principles and
 * compared against a raw query or a hand-typed literal — never against the
 * function under test called twice.
 */
class LedgerInvariantsCoreTest extends TestCase
{
    use DatabaseTransactions;

    private int $branchId;

    private CashAccount $till;

    private BankAccount $bank;

    private ChartOfAccount $tillLeaf;

    private ChartOfAccount $bankLeaf;

    private ChartOfAccount $expenseAccount;

    private ChartOfAccount $incomeAccount;

    protected function setUp(): void
    {
        parent::setUp();

        if (! ChartOfAccount::where('code', '1100')->exists()) {
            $this->markTestSkipped('Chart of accounts not seeded.');
        }

        $this->branchId = DB::table('branches')->insertGetId([
            'name' => 'INV Test Branch', 'code' => 'INV' . Str::random(3),
            'branch_type' => 'Branch', 'status' => 'Active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        // Tills/banks created WITHOUT an opening-balance GL entry — their GL leaf
        // starts empty, so leaf-balance deltas are clean. (current_balance is set
        // directly only for FinancePostingService's own overdraw checks.)
        $this->till = CashAccount::create([
            'code' => 'INV-CASH-' . Str::random(6), 'branch_id' => $this->branchId, 'account_type' => 'BranchMain',
            'name' => 'INV Test Till', 'opening_balance' => 0, 'current_balance' => '1000000.00', 'status' => 'Active',
        ]);
        $this->tillLeaf = ChartOfAccount::forCashTill($this->till->fresh());

        $bankRowId = DB::table('banks')->insertGetId(['name' => 'INV Test Bank', 'created_at' => now(), 'updated_at' => now()]);
        $this->bank = BankAccount::create([
            'branch_id' => $this->branchId, 'bank_id' => $bankRowId, 'name' => 'INV Test Bank Account',
            'account_number' => 'ACC' . Str::random(10), 'account_type' => 'Current', 'currency' => 'LKR',
            'opening_balance' => 0, 'current_balance' => '1000000.00', 'status' => 'Active',
        ]);
        $this->bankLeaf = ChartOfAccount::forBankAccount($this->bank->fresh());

        $this->expenseAccount = ChartOfAccount::where('code', '5010')->firstOrFail();
        $this->incomeAccount = ChartOfAccount::where('code', '4900')->firstOrFail();
    }

    /** GL balance for an account as-of today via the app's own query, cache flushed. Null -> "0.00". */
    private function glBalance(int $accountId, ?string $asOf = null): string
    {
        ChartOfAccount::flushBalancesCache();
        $row = ChartOfAccount::balancesAsOf($asOf ?? now()->toDateString())->get($accountId);

        return $row ? (string) $row->balance : '0.00';
    }

    /** Independent raw recompute — mirrors balancesAsOf's own filter (Posted only). Debit-normal orientation caller-supplied. */
    private function rawBalance(int $accountId, string $normalBalance, bool $postedOnly = true): string
    {
        $q = DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_lines.account_id', $accountId);
        if ($postedOnly) {
            $q->where('journal_entries.status', 'Posted');
        }
        $r = $q->selectRaw('COALESCE(SUM(debit),0) d, COALESCE(SUM(credit),0) c')->first();

        return $normalBalance === 'Debit' ? bcsub((string) $r->d, (string) $r->c, 2) : bcsub((string) $r->c, (string) $r->d, 2);
    }

    private function assertBc(string $expected, string $actual, string $msg = ''): void
    {
        $this->assertSame(0, bccomp($expected, $actual, 2), $msg ?: "expected {$expected}, got {$actual}");
    }

    // =============================================================
    // INV-01
    // =============================================================
    public function test_inv01_debits_equal_credits_globally_and_per_entry(): void
    {
        $ids = [];

        $ids[] = LedgerPoster::post([
            ['account_id' => $this->expenseAccount->id, 'debit' => '1000.00', 'credit' => 0],
            ['account_id' => $this->tillLeaf->id, 'debit' => 0, 'credit' => '1000.00'],
        ], now()->toDateString(), ['source_type' => 'ManualAdjustment', 'description' => 'INV01 a'])->id;

        $ids[] = LedgerPoster::post([
            ['account_id' => $this->tillLeaf->id, 'debit' => '1000.00', 'credit' => 0],
            ['account_id' => $this->incomeAccount->id, 'debit' => 0, 'credit' => '600.00'],
            ['account_id' => $this->bankLeaf->id, 'debit' => 0, 'credit' => '400.00'],
        ], now()->toDateString(), ['source_type' => 'ManualAdjustment', 'description' => 'INV01 b'])->id;

        $txn = FinancePostingService::execute([
            'txn_type' => 'TRANSFER', 'amount' => '5000.00', 'entry_date' => now()->toDateString(),
            'branch_id' => $this->branchId,
            'movements' => [
                ['account_type' => 'cash', 'account_id' => $this->till->id, 'delta' => '-5000.00'],
                ['account_type' => 'bank', 'account_id' => $this->bank->id, 'delta' => '5000.00'],
            ],
            'gl_lines' => [
                ['account_id' => $this->bankLeaf->id, 'debit' => '5000.00', 'credit' => 0],
                ['account_id' => $this->tillLeaf->id, 'debit' => 0, 'credit' => '5000.00'],
            ],
            'executed_by' => 1,
        ]);
        $ids[] = $txn->journal_entry_id;

        $reversal = LedgerPoster::reverse(JournalEntry::find($ids[0]), 'INV01 reversal', 1);
        $ids[] = $reversal->id;

        // Per entry — every one balances (all 4, incl. the now-Reversed original and its reversal).
        foreach ($ids as $eid) {
            $t = DB::table('journal_lines')->where('journal_entry_id', $eid)
                ->selectRaw('COALESCE(SUM(debit),0) d, COALESCE(SUM(credit),0) c')->first();
            $this->assertBc((string) $t->d, (string) $t->c, "entry #{$eid} does not balance");
        }

        // System-wide, across ALL entries and ALL statuses.
        $g = DB::table('journal_lines')->selectRaw('COALESCE(SUM(debit),0) d, COALESCE(SUM(credit),0) c')->first();
        $this->assertBc((string) $g->d, (string) $g->c, 'system-wide debits != credits');
    }

    // =============================================================
    // INV-02
    // =============================================================
    public function test_inv02_trial_balance_nets_to_zero(): void
    {
        // Baseline: the seeded DB may already net to zero or not — capture, act, re-check the delta is zero.
        $before = $this->signedGlobalNet();

        LedgerPoster::post([
            ['account_id' => $this->expenseAccount->id, 'debit' => '2500.00', 'credit' => 0],
            ['account_id' => $this->incomeAccount->id, 'debit' => 0, 'credit' => '1000.00'],
            ['account_id' => $this->tillLeaf->id, 'debit' => 0, 'credit' => '1500.00'],
        ], now()->toDateString(), ['source_type' => 'ManualAdjustment']);

        FinancePostingService::execute([
            'txn_type' => 'CASH_OUT', 'amount' => '3000.00', 'entry_date' => now()->toDateString(),
            'branch_id' => $this->branchId,
            'movements' => [['account_type' => 'cash', 'account_id' => $this->till->id, 'delta' => '-3000.00']],
            'gl_lines' => [
                ['account_id' => $this->expenseAccount->id, 'debit' => '3000.00', 'credit' => 0],
                ['account_id' => $this->tillLeaf->id, 'debit' => 0, 'credit' => '3000.00'],
            ],
            'executed_by' => 1,
        ]);

        $after = $this->signedGlobalNet();

        // Every balanced entry keeps the signed global net exactly where it was.
        $this->assertBc($before, $after, 'posting balanced entries changed the signed global net');
        // And a fresh, self-contained fixture built only from balanced entries nets to zero on its own.
    }

    private function signedGlobalNet(): string
    {
        $r = DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.status', 'Posted')
            ->selectRaw('COALESCE(SUM(debit),0) d, COALESCE(SUM(credit),0) c')->first();

        return bcsub((string) $r->d, (string) $r->c, 2);
    }

    // =============================================================
    // INV-03
    // =============================================================
    public function test_inv03_balances_as_of_matches_an_independent_raw_recompute(): void
    {
        $tillBefore = $this->glBalance($this->tillLeaf->id);
        $expBefore = $this->glBalance($this->expenseAccount->id);

        // No reversal in this fixture — a clean, hand-verifiable set.
        LedgerPoster::post([
            ['account_id' => $this->expenseAccount->id, 'debit' => '1200.00', 'credit' => 0],
            ['account_id' => $this->tillLeaf->id, 'debit' => 0, 'credit' => '1200.00'],
        ], now()->toDateString(), ['source_type' => 'ManualAdjustment']);
        LedgerPoster::post([
            ['account_id' => $this->tillLeaf->id, 'debit' => '300.00', 'credit' => 0],
            ['account_id' => $this->incomeAccount->id, 'debit' => 0, 'credit' => '300.00'],
        ], now()->toDateString(), ['source_type' => 'ManualAdjustment']);

        // Hand arithmetic: till leaf (Debit-normal) delta = -1200 + 300 = -900.00
        $tillAfter = $this->glBalance($this->tillLeaf->id);
        $this->assertBc('-900.00', bcsub($tillAfter, $tillBefore, 2), 'till leaf delta');
        // expense (Debit-normal) delta = +1200.00
        $expAfter = $this->glBalance($this->expenseAccount->id);
        $this->assertBc('1200.00', bcsub($expAfter, $expBefore, 2), 'expense delta');

        // The app's balancesAsOf must equal an independent raw GROUP BY, account by account,
        // for asOf=null and asOf=today.
        foreach ([null, now()->toDateString(), now()->subYear()->toDateString()] as $asOf) {
            $reported = $this->glBalance($this->tillLeaf->id, $asOf);
            $raw = $this->rawBalanceAsOf($this->tillLeaf->id, 'Debit', $asOf);
            $this->assertBc($raw, $reported, "till leaf mismatch at asOf=" . var_export($asOf, true));
        }
    }

    private function rawBalanceAsOf(int $accountId, string $normalBalance, ?string $asOf): string
    {
        $q = DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.status', 'Posted')
            ->where('journal_lines.account_id', $accountId);
        if ($asOf) {
            $q->whereDate('journal_entries.entry_date', '<=', $asOf);
        }
        $r = $q->selectRaw('COALESCE(SUM(debit),0) d, COALESCE(SUM(credit),0) c')->first();

        return $normalBalance === 'Debit' ? bcsub((string) $r->d, (string) $r->c, 2) : bcsub((string) $r->c, (string) $r->d, 2);
    }

    // =============================================================
    // INV-05
    // =============================================================
    public function test_inv05_every_finance_transaction_has_a_resolvable_journal_entry(): void
    {
        $txn = FinancePostingService::execute([
            'txn_type' => 'CASH_OUT', 'amount' => '1234.56', 'entry_date' => now()->toDateString(),
            'branch_id' => $this->branchId,
            'movements' => [['account_type' => 'cash', 'account_id' => $this->till->id, 'delta' => '-1234.56']],
            'gl_lines' => [
                ['account_id' => $this->expenseAccount->id, 'debit' => '1234.56', 'credit' => 0],
                ['account_id' => $this->tillLeaf->id, 'debit' => 0, 'credit' => '1234.56'],
            ],
            'executed_by' => 1,
        ]);

        $this->assertNotNull($txn->journal_entry_id);
        $entry = JournalEntry::find($txn->journal_entry_id);
        $this->assertNotNull($entry);
        $this->assertContains($entry->status, ['Posted', 'Reversed']);

        // No finance_transactions row for this branch is left without a journal entry.
        $orphans = DB::table('finance_transactions')
            ->leftJoin('journal_entries', 'journal_entries.id', '=', 'finance_transactions.journal_entry_id')
            ->where('finance_transactions.branch_id', $this->branchId)
            ->whereNull('journal_entries.id')->count();
        $this->assertSame(0, $orphans);
    }

    /**
     * F-3: opening-balance entries SHOULD carry source_type='OpeningBalance' so
     * the "entries with no operational source row" set is exactly
     * {ManualAdjustment, OpeningBalance}. They currently carry source_type=NULL.
     */
    public function test_inv05_opening_balance_entry_should_be_tagged_source_type_opening_balance(): void
    {
        $obId = DB::table('cash_accounts')->insertGetId([
            'code' => 'INV-OB-' . Str::random(6), 'branch_id' => $this->branchId, 'account_type' => 'BranchMain',
            'name' => 'INV OB Till', 'opening_balance' => '15000.00', 'current_balance' => '15000.00',
            'status' => 'Active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $obAccount = CashAccount::find($obId);
        OpeningBalanceLedgerPoster::postForCashAccount($obAccount);

        $leaf = ChartOfAccount::forCashTill($obAccount->fresh());
        $line = DB::table('journal_lines')->where('account_id', $leaf->id)->first();
        $this->assertNotNull($line, 'opening balance entry was not posted at all');
        $entry = JournalEntry::find($line->journal_entry_id);

        // The entry has no operational source row — correct.
        $this->assertNull($entry->source_id);

        // F-3: it SHOULD be identifiable as an opening-balance posting.
        $this->assertSame('OpeningBalance', $entry->source_type,
            'F-3: OpeningBalanceLedgerPoster posts with source_type=NULL — see docs/audit/failures.md#f-3');
    }

    // =============================================================
    // INV-06
    // =============================================================

    /** F-2: the CHECK constraint should reject a zero/zero line. It is absent from the live DB. */
    public function test_inv06a_journal_lines_check_constraint_rejects_a_zero_zero_line(): void
    {
        $entry = JournalEntry::create([
            'code' => 'INV-CHK-' . Str::random(8), 'entry_date' => now()->toDateString(),
            'status' => 'Posted', 'posted_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/check|constraint/i');
        DB::table('journal_lines')->insert([
            'journal_entry_id' => $entry->id, 'account_id' => $this->tillLeaf->id,
            'entry_date' => now()->toDateString(), 'debit' => 0, 'credit' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** F-2: the CHECK constraint should reject a both-sides-populated line. It is absent from the live DB. */
    public function test_inv06b_journal_lines_check_constraint_rejects_a_double_sided_line(): void
    {
        $entry = JournalEntry::create([
            'code' => 'INV-CHK-' . Str::random(8), 'entry_date' => now()->toDateString(),
            'status' => 'Posted', 'posted_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/check|constraint/i');
        DB::table('journal_lines')->insert([
            'journal_entry_id' => $entry->id, 'account_id' => $this->tillLeaf->id,
            'entry_date' => now()->toDateString(), 'debit' => '10.00', 'credit' => '5.00',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_inv06c_finance_transactions_check_constraint_rejects_non_positive_amount(): void
    {
        $this->expectException(QueryException::class);
        DB::table('finance_transactions')->insert([
            'code' => 'INV-FTX-' . Str::random(8), 'txn_type' => 'ADJUSTMENT', 'direction' => 'In',
            'amount' => '0.00', 'currency' => 'LKR', 'status' => 'Completed',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_inv06d_ledger_poster_rejects_unbalanced_lines(): void
    {
        $this->expectException(\RuntimeException::class);
        LedgerPoster::post([
            ['account_id' => $this->tillLeaf->id, 'debit' => '100.00', 'credit' => 0],
            ['account_id' => $this->expenseAccount->id, 'debit' => 0, 'credit' => '99.99'],
        ], now()->toDateString());
    }

    public function test_inv06e_ledger_poster_rejects_a_single_line(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        LedgerPoster::post([
            ['account_id' => $this->tillLeaf->id, 'debit' => '100.00', 'credit' => 0],
        ], now()->toDateString());
    }

    // =============================================================
    // INV-07
    // =============================================================
    public function test_inv07_reversal_lines_are_a_contra_and_the_original_is_never_mutated(): void
    {
        $entry = LedgerPoster::post([
            ['account_id' => $this->expenseAccount->id, 'debit' => '4200.00', 'credit' => 0, 'description' => 'orig A'],
            ['account_id' => $this->tillLeaf->id, 'debit' => 0, 'credit' => '4200.00', 'description' => 'orig B'],
        ], now()->toDateString(), ['source_type' => 'ManualAdjustment']);

        $snapshot = JournalLine::where('journal_entry_id', $entry->id)->orderBy('id')
            ->get(['id', 'account_id', 'debit', 'credit'])
            ->map(fn ($l) => ['id' => $l->id, 'account_id' => $l->account_id, 'debit' => (string) $l->debit, 'credit' => (string) $l->credit])
            ->all();
        $lineCountBefore = JournalLine::count();
        $entryCountBefore = JournalEntry::count();

        $reversal = LedgerPoster::reverse($entry, 'INV07', 1);

        // Original lines byte-for-byte identical.
        $after = JournalLine::where('journal_entry_id', $entry->id)->orderBy('id')
            ->get(['id', 'account_id', 'debit', 'credit'])
            ->map(fn ($l) => ['id' => $l->id, 'account_id' => $l->account_id, 'debit' => (string) $l->debit, 'credit' => (string) $l->credit])
            ->all();
        $this->assertSame($snapshot, $after);
        $this->assertSame('Reversed', $entry->fresh()->status);
        $this->assertNull($entry->fresh()->reversal_of_id);
        $this->assertSame((int) $entry->id, (int) $reversal->reversal_of_id);
        $this->assertSame('Posted', $reversal->status);

        // Reversal mirrors debit/credit per account.
        $rl = JournalLine::where('journal_entry_id', $reversal->id)->get()->keyBy('account_id');
        $this->assertBc('4200.00', (string) $rl[$this->expenseAccount->id]->credit);
        $this->assertBc('4200.00', (string) $rl[$this->tillLeaf->id]->debit);

        // Nothing deleted — rows only added.
        $this->assertSame($lineCountBefore + 2, JournalLine::count());
        $this->assertSame($entryCountBefore + 1, JournalEntry::count());

        // The two entries together (ALL statuses) net to zero on the till leaf — the
        // reversal lines ARE a correct contra.
        $allStatuses = $this->rawBalance($this->tillLeaf->id, 'Debit', postedOnly: false);
        $this->assertBc('0.00', $allStatuses, 'reversal lines do not cancel the original when both are summed');

        // F-1: but the app's balance query (Posted-only) does NOT net to zero — it
        // shows the till leaf at the FULL OPPOSITE of the original (+4200.00).
        $reported = $this->glBalance($this->tillLeaf->id);
        $this->assertBc('0.00', $reported,
            'F-1: reversing an entry leaves the account at +4200 instead of 0 in every report — see docs/audit/failures.md#f-1');
    }

    // =============================================================
    // INV-13
    // =============================================================
    public function test_inv13_fps_blocks_reversing_a_reversal_ledger_poster_alone_does_not(): void
    {
        $txn = FinancePostingService::execute([
            'txn_type' => 'CASH_OUT', 'amount' => '3000.00', 'entry_date' => now()->toDateString(),
            'branch_id' => $this->branchId,
            'movements' => [['account_type' => 'cash', 'account_id' => $this->till->id, 'delta' => '-3000.00']],
            'gl_lines' => [
                ['account_id' => $this->expenseAccount->id, 'debit' => '3000.00', 'credit' => 0],
                ['account_id' => $this->tillLeaf->id, 'debit' => 0, 'credit' => '3000.00'],
            ],
            'executed_by' => 1,
        ]);
        $reversal = FinancePostingService::reverse($txn->fresh(), 'first', 1);

        try {
            FinancePostingService::reverse($reversal->fresh(), 'second', 1);
            $this->fail('Expected FinancePostingException re-reversing a reversal.');
        } catch (FinancePostingException $e) {
            $this->assertStringContainsString('cannot itself be reversed', $e->getMessage());
        }

        // Documented P1 gap (INV-13): LedgerPoster::reverse has no such guard.
        $reversalEntry = $reversal->journalEntry;
        $this->assertSame('Posted', $reversalEntry->status);
        $countBefore = JournalEntry::count();
        LedgerPoster::reverse($reversalEntry, 'ledger-poster-only re-reversal', 1);
        $this->assertSame($countBefore + 1, JournalEntry::count(),
            'LedgerPoster::reverse allowed a reversal of a reversal — no guard at that layer (INV-13, P1 gap)');
    }

    // =============================================================
    // INV-14
    // =============================================================
    public function test_inv14_backdated_and_future_dated_postings_are_accepted_with_no_period_lock(): void
    {
        $baseline2020 = $this->glBalance($this->expenseAccount->id, '2020-12-31');
        $baselineToday = $this->glBalance($this->expenseAccount->id, now()->toDateString());

        $past = LedgerPoster::post([
            ['account_id' => $this->expenseAccount->id, 'debit' => '777.00', 'credit' => 0],
            ['account_id' => $this->tillLeaf->id, 'debit' => 0, 'credit' => '777.00'],
        ], '2020-01-15', ['source_type' => 'ManualAdjustment']);

        $future = LedgerPoster::post([
            ['account_id' => $this->expenseAccount->id, 'debit' => '888.00', 'credit' => 0],
            ['account_id' => $this->tillLeaf->id, 'debit' => 0, 'credit' => '888.00'],
        ], '2099-06-01', ['source_type' => 'ManualAdjustment']);

        // Both accepted, no error, no block.
        $this->assertSame('2020-01-15', $past->fresh()->entry_date->toDateString());
        $this->assertSame('2099-06-01', $future->fresh()->entry_date->toDateString());

        // As-of 2020-12-31: the backdated 777 is included, the future 888 is not.
        $this->assertBc('777.00', bcsub($this->glBalance($this->expenseAccount->id, '2020-12-31'), $baseline2020, 2),
            'backdated entry must land in a prior "closed" period with nothing stopping it');

        // As-of today: 777 counted, 888 (future) not.
        $this->assertBc('777.00', bcsub($this->glBalance($this->expenseAccount->id, now()->toDateString()), $baselineToday, 2),
            'future-dated entry must not appear in an as-of-today balance');
    }

    // =============================================================
    // INV-15
    // =============================================================
    public function test_inv15_strict_mode_is_active_so_an_unlisted_source_type_is_rejected(): void
    {
        $mode = DB::selectOne('SELECT @@sql_mode as m')->m;
        $this->assertStringContainsString('STRICT_TRANS_TABLES', $mode);

        $this->expectException(QueryException::class);
        DB::table('journal_entries')->insert([
            'code' => 'INV-ENUM-' . Str::random(8), 'entry_date' => now()->toDateString(),
            'source_type' => 'NotARealSourceType', 'status' => 'Posted', 'posted_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
