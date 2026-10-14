<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\CashAccount;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\User;
use App\Support\CurrentBranch;
use App\Support\LedgerPoster;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Audit finding B-2: when one branch's cash pays or receives money for another branch's transaction, each
 * branch's books must still balance on their own. The ledger writes one entry per branch and joins them
 * with 1400 (due from branches) and 2400 (due to branches), which net to zero for the company.
 */
class InterBranchAccountingTest extends TestCase
{
    use DatabaseTransactions;

    private int $a;

    private int $b;

    private ChartOfAccount $tillA;

    private ChartOfAccount $tillB;

    protected function setUp(): void
    {
        parent::setUp();
        $mk = fn (string $n) => DB::table('branches')->insertGetId(['name' => "{$n} " . Str::random(4), 'code' => $n[0] . Str::random(4), 'branch_type' => 'Branch', 'status' => 'Active', 'created_at' => now(), 'updated_at' => now()]);
        $this->a = $mk('Aaa');
        $this->b = $mk('Bbb');
        $till = fn (int $branch) => ChartOfAccount::forCashTill(CashAccount::create(['code' => 'IB-' . Str::random(8), 'branch_id' => $branch, 'account_type' => 'BranchMain', 'name' => 'IBT ' . Str::random(4), 'opening_balance' => 0, 'current_balance' => 0, 'status' => 'Active'])->fresh());
        $this->tillA = $till($this->a);
        $this->tillB = $till($this->b);
        $this->actingAs(User::whereHas('role', fn ($q) => $q->where('slug', 'admin'))->first() ?? $this->markTestSkipped('no admin'), 'sanctum');
        CurrentBranch::flushCache();
        ChartOfAccount::flushBalancesCache();
    }

    private function book(array $lines, int $branch): JournalEntry
    {
        $e = LedgerPoster::post($lines, '2033-05-10', ['source_type' => 'ManualAdjustment', 'description' => 'ib test', 'branch_id' => $branch]);
        ChartOfAccount::flushBalancesCache();

        return $e;
    }

    private function tb(?int $branch): array
    {
        $q = $branch ? "&branch_id={$branch}" : '';

        return $this->getJson("/api/accounting-reports/trial-balance?as_of=2033-12-31{$q}")->assertOk()->json();
    }

    private function bal(array $tb, string $code): float
    {
        $r = collect($tb['rows'])->firstWhere('code', $code);

        return (float) $r['debit_balance'] - (float) $r['credit_balance'];
    }

    public function test_an_expense_of_branch_a_paid_from_branch_b_cash_balances_in_each_branch(): void
    {
        // Branch A's expense, paid out of branch B's till.
        $main = $this->book([
            ['account_id' => ChartOfAccount::system('5010')->id, 'debit' => '300.00'],
            ['account_id' => $this->tillB->id, 'credit' => '300.00'],
        ], $this->a);

        $entries = JournalEntry::where('id', $main->id)->orWhere('related_entry_id', $main->id)->get();
        $this->assertCount(2, $entries, 'one entry per branch');
        $mainLines = DB::table('journal_lines')->where('journal_entry_id', $main->id)->get();
        $this->assertEquals(300.0, (float) $mainLines->firstWhere('account_id', ChartOfAccount::system('2400')->id)->credit, 'A owes B');
        $comp = $entries->firstWhere('id', '!=', $main->id);
        $this->assertSame($this->b, (int) $comp->branch_id);
        $this->assertSame($this->a, (int) $comp->counterparty_branch_id);
        $compLines = DB::table('journal_lines')->where('journal_entry_id', $comp->id)->get();
        $this->assertEquals(300.0, (float) $compLines->firstWhere('account_id', ChartOfAccount::system('1400')->id)->debit, 'B is owed by A');

        foreach ([$this->a, $this->b] as $branch) {
            $this->assertEquals(0.0, (float) $this->tb($branch)['totals']['difference'], 'each branch trial balance foots');
        }
        $whole = $this->tb(null);
        $this->assertEquals($this->bal($whole, '1400'), -$this->bal($whole, '2400'), 'company-wide the two clearing accounts net to zero');
    }

    public function test_cash_received_by_branch_b_for_branch_a_creates_the_mirror_entries(): void
    {
        $main = $this->book([
            ['account_id' => $this->tillB->id, 'debit' => '120.00'],
            ['account_id' => ChartOfAccount::system('4010')->id, 'credit' => '120.00'],
        ], $this->a);
        $mainLines = DB::table('journal_lines')->where('journal_entry_id', $main->id)->get();
        $this->assertEquals(120.0, (float) $mainLines->firstWhere('account_id', ChartOfAccount::system('1400')->id)->debit, 'A is owed by B, who holds A\'s cash');
        $comp = JournalEntry::where('related_entry_id', $main->id)->first();
        $this->assertEquals(120.0, (float) DB::table('journal_lines')->where('journal_entry_id', $comp->id)->where('account_id', ChartOfAccount::system('2400')->id)->value('credit'));
        foreach ([$this->a, $this->b] as $branch) {
            $this->assertEquals(0.0, (float) $this->tb($branch)['totals']['difference']);
        }
    }

    public function test_an_entry_inside_one_branch_is_left_alone(): void
    {
        $main = $this->book([
            ['account_id' => ChartOfAccount::system('5010')->id, 'debit' => '50.00'],
            ['account_id' => $this->tillA->id, 'credit' => '50.00'],
        ], $this->a);
        $this->assertSame(0, JournalEntry::where('related_entry_id', $main->id)->count());
        $this->assertSame(2, DB::table('journal_lines')->where('journal_entry_id', $main->id)->count());
    }

    public function test_reversing_the_main_entry_reverses_every_branch_entry(): void
    {
        $main = $this->book([
            ['account_id' => ChartOfAccount::system('5010')->id, 'debit' => '300.00'],
            ['account_id' => $this->tillB->id, 'credit' => '300.00'],
        ], $this->a);
        LedgerPoster::reverse($main->fresh(), 'undo');
        ChartOfAccount::flushBalancesCache();

        foreach ([$this->a, $this->b] as $branch) {
            $tb = $this->tb($branch);
            $this->assertEquals(0.0, $this->bal($tb, '1400') + $this->bal($tb, '2400'), 'clearing accounts back to zero');
            $this->assertEquals(0.0, $this->bal($tb, '5010'));
        }
        // Reversing the companion afterwards must not blow up (callers loop over every entry of a source).
        $comp = JournalEntry::where('related_entry_id', $main->id)->first();
        $this->assertSame('Reversed', $comp->fresh()->status);
        LedgerPoster::reverse($comp, 'again');
        $this->addToAssertionCount(1);
    }

    public function test_the_inter_branch_report_shows_who_owes_whom(): void
    {
        $this->book([
            ['account_id' => ChartOfAccount::system('5010')->id, 'debit' => '300.00'],
            ['account_id' => $this->tillB->id, 'credit' => '300.00'],
        ], $this->a);
        $r = $this->getJson('/api/accounting-reports/inter-branch?as_of=2033-12-31')->assertOk()->json();

        $aOwesB = collect($r['rows'])->first(fn ($x) => (int) $x['branch_id'] === $this->a && (int) $x['counterparty_branch_id'] === $this->b);
        $bOwedByA = collect($r['rows'])->first(fn ($x) => (int) $x['branch_id'] === $this->b && (int) $x['counterparty_branch_id'] === $this->a);
        $this->assertEquals(300.0, (float) $aOwesB['payable']);
        $this->assertEquals(300.0, (float) $bOwedByA['receivable']);
        $this->assertEquals(0.0, (float) $r['net_total'], 'the whole company nets to zero');
    }
}
