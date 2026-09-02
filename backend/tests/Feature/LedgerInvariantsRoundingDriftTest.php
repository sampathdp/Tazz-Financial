<?php

namespace Tests\Feature;

use App\Models\CashAccount;
use App\Models\ChartOfAccount;
use App\Support\FinancePostingService;
use App\Support\LedgerPoster;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Plan: docs/audit/test-plans/ledger-invariants.md — INV-08.
 *
 * 1,000 randomised balanced postings with awkward decimal amounts, then assert
 * total drift is exactly zero:
 *   - system-wide debits == credits (all entries, all statuses);
 *   - every cash-account current_balance moved by EXACTLY the hand-summed total
 *     of the FinancePostingService legs that touched it;
 *   - every cash leaf's GL balance == that same hand total (cache == GL, the
 *     invariant FPS is built around).
 *
 * Every till-leaf-touching posting goes through FPS so cache and GL stay locked.
 * Pure-LedgerPoster entries only touch P&L accounts. No reversals (F-1 would
 * inject a known residual). Deterministic seed.
 */
class LedgerInvariantsRoundingDriftTest extends TestCase
{
    use DatabaseTransactions;

    public function test_inv08_a_thousand_awkward_transactions_net_to_exactly_zero(): void
    {
        if (! ChartOfAccount::where('code', '1100')->exists()) {
            $this->markTestSkipped('Chart of accounts not seeded.');
        }

        mt_srand(20260901);
        $start = '100000000.00';

        $branchId = DB::table('branches')->insertGetId([
            'name' => 'INV Drift Branch', 'code' => 'IDR' . Str::random(3),
            'branch_type' => 'Branch', 'status' => 'Active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $tills = [];
        $leaves = [];
        foreach ([1, 2, 3] as $i) {
            $t = CashAccount::create([
                'code' => "IDR-CASH-{$i}-" . Str::random(5), 'branch_id' => $branchId, 'account_type' => 'BranchMain',
                'name' => "INV Drift Till {$i}", 'opening_balance' => 0, 'current_balance' => $start,
                'allow_negative' => true, 'status' => 'Active',
            ]);
            $tills[$i] = $t;
            $leaves[$i] = ChartOfAccount::forCashTill($t->fresh());
        }

        $expense = ChartOfAccount::where('code', '5010')->firstOrFail();
        $income = ChartOfAccount::where('code', '4900')->firstOrFail();

        // Hand-summed movement of each till's cache/GL, tracked independently of the code.
        $delta = [1 => '0.00', 2 => '0.00', 3 => '0.00'];

        $awkward = fn () => number_format(mt_rand(101, 98_765_432) / 100, 2, '.', '');

        for ($n = 0; $n < 1000; $n++) {
            switch ($n % 3) {
                case 0:
                    // Pure LedgerPoster, P&L only — exercises the balance check's bcmath, no cash leaf.
                    $a = $awkward();
                    $b = $awkward();
                    LedgerPoster::post([
                        ['account_id' => $expense->id, 'debit' => bcadd($a, $b, 2), 'credit' => 0],
                        ['account_id' => $income->id, 'debit' => 0, 'credit' => $a],
                        ['account_id' => $income->id, 'debit' => 0, 'credit' => $b],
                    ], now()->toDateString(), ['source_type' => 'ManualAdjustment']);
                    break;

                case 1:
                    // FPS CASH_OUT from a rotating till.
                    $i = ($n % 3) + 1;
                    $amt = $awkward();
                    FinancePostingService::execute([
                        'txn_type' => 'CASH_OUT', 'amount' => $amt, 'entry_date' => now()->toDateString(),
                        'branch_id' => $branchId,
                        'movements' => [['account_type' => 'cash', 'account_id' => $tills[$i]->id, 'delta' => '-' . $amt]],
                        'gl_lines' => [
                            ['account_id' => $expense->id, 'debit' => $amt, 'credit' => 0],
                            ['account_id' => $leaves[$i]->id, 'debit' => 0, 'credit' => $amt],
                        ],
                        'executed_by' => 1,
                    ]);
                    $delta[$i] = bcsub($delta[$i], $amt, 2);
                    break;

                default:
                    // FPS TRANSFER between two distinct tills.
                    $i = ($n % 3) + 1;
                    $j = ($i % 3) + 1;
                    $amt = $awkward();
                    FinancePostingService::execute([
                        'txn_type' => 'TRANSFER', 'amount' => $amt, 'entry_date' => now()->toDateString(),
                        'branch_id' => $branchId,
                        'movements' => [
                            ['account_type' => 'cash', 'account_id' => $tills[$i]->id, 'delta' => '-' . $amt],
                            ['account_type' => 'cash', 'account_id' => $tills[$j]->id, 'delta' => $amt],
                        ],
                        'gl_lines' => [
                            ['account_id' => $leaves[$j]->id, 'debit' => $amt, 'credit' => 0],
                            ['account_id' => $leaves[$i]->id, 'debit' => 0, 'credit' => $amt],
                        ],
                        'executed_by' => 1,
                    ]);
                    $delta[$i] = bcsub($delta[$i], $amt, 2);
                    $delta[$j] = bcadd($delta[$j], $amt, 2);
                    break;
            }
        }

        // 1) System-wide debits == credits, exactly.
        $g = DB::table('journal_lines')->selectRaw('COALESCE(SUM(debit),0) d, COALESCE(SUM(credit),0) c')->first();
        $this->assertSame(0, bccomp((string) $g->d, (string) $g->c, 2), 'system-wide drift is not zero');

        // 2) current_balance moved by exactly the hand total.
        foreach ([1, 2, 3] as $i) {
            $this->assertSame(bcadd($start, $delta[$i], 2), (string) $tills[$i]->fresh()->current_balance,
                "till {$i} current_balance drifted from the hand total");
        }

        // 3) GL leaf balance == hand total == cache delta.
        ChartOfAccount::flushBalancesCache();
        $balances = ChartOfAccount::balancesAsOf(now()->toDateString());
        foreach ([1, 2, 3] as $i) {
            $glLeaf = (string) ($balances->get($leaves[$i]->id)->balance ?? '0.00');
            $this->assertSame(0, bccomp($glLeaf, $delta[$i], 2), "till {$i} GL leaf {$glLeaf} != hand delta {$delta[$i]}");
            $cacheDelta = bcsub((string) $tills[$i]->fresh()->current_balance, $start, 2);
            $this->assertSame(0, bccomp($cacheDelta, $glLeaf, 2), "till {$i} cache-vs-GL drift");
        }
    }
}
