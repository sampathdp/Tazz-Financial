<?php

namespace App\Console\Commands;

use App\Models\FinanceAuditEvent;
use App\Support\BorrowingLedgerCheck;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Headless borrowings-vs-ledger reconciliation (see BorrowingLedgerCheck): logs
 * each disagreement to the errors channel, records a finance_audit_event and
 * exits non-zero so a monitor/cron alert fires. Sits beside finance:check-drift,
 * which covers the cash and bank accounts.
 */
class CheckBorrowingLedger extends Command
{
    protected $signature = 'borrowings:check-ledger
        {--tolerance=0.00 : absolute variance (in currency) to ignore as rounding noise}
        {--json : print the findings as JSON instead of tables}';

    protected $description = 'Compare every borrowing and the borrowing liability accounts against the general ledger';

    public function handle(): int
    {
        $result = BorrowingLedgerCheck::run((string) $this->option('tolerance'));
        $found = count($result['borrowings']) + count($result['accounts']);

        if ($found === 0) {
            $this->info('Borrowings ledger check: all borrowings and liability accounts agree with the ledger.');

            return self::SUCCESS;
        }

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT));
        } else {
            if ($result['borrowings']) {
                $this->table(['Borrowing', 'What', 'GL', 'Borrowing', 'Ledger', 'Drift'], array_map(
                    fn ($r) => [$r['number'], $r['what'], $r['gl_code'], $r['borrowing'], $r['ledger'], $r['variance']], $result['borrowings']
                ));
            }
            if ($result['accounts']) {
                $this->table(['GL', 'Sum of borrowings', 'Ledger', 'Drift'], array_map(
                    fn ($r) => [$r['gl_code'], $r['borrowings'], $r['ledger'], $r['variance']], $result['accounts']
                ));
            }
        }
        $this->error("Borrowings ledger check found {$found} disagreement(s).");

        Log::channel('errors')->warning('Borrowings ledger drift detected', $result);
        FinanceAuditEvent::record('ledger.borrowing_drift_detected', null, [
            'amount' => null,
            'meta' => [
                'borrowings' => array_map(fn ($r) => "{$r['number']} {$r['what']}: {$r['variance']}", $result['borrowings']),
                'accounts' => array_map(fn ($r) => "{$r['gl_code']}: {$r['variance']}", $result['accounts']),
            ],
        ]);

        return self::FAILURE;
    }
}
