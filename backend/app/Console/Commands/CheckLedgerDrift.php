<?php

namespace App\Console\Commands;

use App\Models\BankAccount;
use App\Models\CashAccount;
use App\Models\ChartOfAccount;
use App\Models\FinanceAuditEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Re-asserts the invariant FinancePostingService is built around: every
 * till / bank account's current_balance (the reconciled cache) must equal
 * its own GL leaf's derived balance (the source of truth). Nothing enforces
 * this at write time — a stray raw UPDATE, a half-migrated flow or a bug in a
 * poster can pull the two apart — so this runs on a schedule and shouts when
 * they disagree.
 *
 * Same comparison as the Ledger Drift accounting report
 * (AccountingReportController::ledgerDrift), but headless: it logs each
 * drifted account to the errors channel, records a finance_audit_event, and
 * exits non-zero so a monitor/cron alert fires.
 */
class CheckLedgerDrift extends Command
{
    protected $signature = 'finance:check-drift
        {--tolerance=0.00 : absolute variance (in currency) to ignore as rounding noise}
        {--json : print the drifted rows as JSON instead of a table}';

    protected $description = 'Compare every cash/bank current_balance against its GL leaf balance and report any drift';

    public function handle(): int
    {
        $tolerance = (string) $this->option('tolerance');
        $balances = ChartOfAccount::balancesAsOf();

        $rows = collect();
        foreach (CashAccount::whereNotNull('chart_of_account_id')->with('chartOfAccount')->get() as $till) {
            $rows->push($this->row('Cash', $till, $balances));
        }
        foreach (BankAccount::whereNotNull('chart_of_account_id')->with('chartOfAccount')->get() as $bank) {
            $rows->push($this->row('Bank', $bank, $balances));
        }

        $drifted = $rows
            ->filter(fn ($r) => bccomp($this->abs($r['variance']), $tolerance, 2) > 0)
            ->sortByDesc(fn ($r) => (float) $this->abs($r['variance']))
            ->values();

        if ($drifted->isEmpty()) {
            $this->info(sprintf('Ledger drift check: %d accounts, all in agreement.', $rows->count()));

            return self::SUCCESS;
        }

        if ($this->option('json')) {
            $this->line($drifted->toJson(JSON_PRETTY_PRINT));
        } else {
            $this->table(
                ['Kind', 'Account', 'GL Code', 'Cache', 'Ledger', 'Drift'],
                $drifted->map(fn ($r) => [$r['kind'], $r['name'], $r['gl_code'], $r['cache'], $r['gl'], $r['variance']])->all(),
            );
        }

        $total = $drifted->reduce(fn ($carry, $r) => bcadd($carry, $r['variance'], 2), '0.00');
        $this->error(sprintf('Ledger drift found on %d of %d accounts (net %s).', $drifted->count(), $rows->count(), $total));

        Log::channel('errors')->warning('Ledger drift detected', [
            'drifted' => $drifted->count(),
            'checked' => $rows->count(),
            'net_variance' => $total,
            'accounts' => $drifted->map(fn ($r) => [
                'kind' => $r['kind'], 'id' => $r['id'], 'name' => $r['name'],
                'gl_code' => $r['gl_code'], 'cache' => $r['cache'], 'gl' => $r['gl'], 'variance' => $r['variance'],
            ])->all(),
        ]);

        FinanceAuditEvent::record('ledger.drift_detected', null, [
            'amount' => $total,
            'meta' => [
                'drifted' => $drifted->count(),
                'checked' => $rows->count(),
                'accounts' => $drifted->map(fn ($r) => $r['name'] . ' (' . $r['gl_code'] . '): ' . $r['variance'])->all(),
            ],
        ]);

        return self::FAILURE;
    }

    private function row(string $kind, $account, $balances): array
    {
        $gl = $account->chartOfAccount;
        $cache = (string) $account->current_balance;
        $glBalance = optional($balances->get($gl->id))->balance ?? '0.00';

        return [
            'kind' => $kind,
            'id' => $account->id,
            'name' => $account->name,
            'gl_code' => $gl->code,
            'cache' => $cache,
            'gl' => (string) $glBalance,
            'variance' => bcsub($cache, (string) $glBalance, 2),
        ];
    }

    private function abs(string $n): string
    {
        return bccomp($n, '0.00', 2) < 0 ? bcmul($n, '-1', 2) : $n;
    }
}
