<?php

namespace App\Console\Commands;

use App\Support\TransactionalDataCleaner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Wipes every transactional table (loans, payments, ledger, cash sessions, finance
 * requests, number counters, ...) while keeping the setup data (users, roles,
 * branches, products, chart of accounts, accounts themselves).
 *
 * Same cleaner as the Maintenance screen (TransactionalDataCleaner). Dry run unless --force is given.
 */
class CleanTransactionalData extends Command
{
    protected $signature = 'data:clean
        {--force : actually delete (default is a dry run that only lists what would be wiped)}
        {--include-customers : also wipe customers and their documents/details (kept by default)}';

    protected $description = 'Delete all transactional data, keep master/setup data, reset cash and bank balances';

    public function handle(): int
    {
        $include = (bool) $this->option('include-customers');
        $db = DB::getDatabaseName();
        $tables = collect(TransactionalDataCleaner::tables($include));
        $keep = $include ? array_diff(TransactionalDataCleaner::KEEP, TransactionalDataCleaner::CUSTOMER_TABLES) : TransactionalDataCleaner::KEEP;

        $rows = $tables->map(fn ($t) => [$t, DB::table($t)->count()])->filter(fn ($r) => $r[1] > 0)->values();
        $this->info("Database: {$db}");
        $this->table(['Table to wipe', 'Rows'], $rows->all());
        $this->line('Empty (already clean): ' . ($tables->count() - $rows->count()) . ' tables. Kept: ' . implode(', ', $keep));

        if (! $this->option('force')) {
            $this->warn('Dry run - nothing deleted. Re-run with --force to wipe.');

            return self::SUCCESS;
        }
        if (app()->environment('production') && ! $this->confirm('This is PRODUCTION. Really delete all transactional data?')) {
            return self::FAILURE;
        }

        $wiped = TransactionalDataCleaner::wipe($include);

        $this->info('Done. Wiped ' . count($wiped) . ' tables; cash/bank balances reset to opening balance.');
        $this->line('Uploaded files under storage/app/public are not touched. Re-run ledger:opening-balances if opening balances should be in the ledger.');

        return self::SUCCESS;
    }
}
