<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * The ledger relies on a handful of database-level guarantees (a journal line is one-sided, a
 * finance transaction is positive, document numbers are unique, ...). They are created by
 * migrations, but a database restored from a dump or hand-patched can silently lack them while
 * its `migrations` table still says everything ran - the application then keeps working and
 * quietly loses its safety net. This reports each missing guarantee and can re-create it.
 */
class VerifySchema extends Command
{
    protected $signature = 'db:verify-schema {--fix : create any missing constraint / column}';

    protected $description = 'Check the integrity constraints the ledger depends on exist (and optionally re-create them)';

    /** table => [constraint name => [expression, description]] */
    private const CHECKS = [
        'journal_lines' => [
            'chk_journal_lines_one_side' => ['((`debit` = 0 AND `credit` > 0) OR (`debit` > 0 AND `credit` = 0))', 'a journal line is debit-only or credit-only, never zero or both'],
        ],
        'finance_transactions' => [
            'chk_ftx_amount' => ['(`amount` > 0)', 'a finance transaction amount is positive'],
        ],
    ];

    /** table.column => DDL that adds it - columns the newest features write to, which a half-applied migration can leave out. */
    private const COLUMNS = [
        'fd_withdrawals.adjusted_amount' => "ALTER TABLE `fd_withdrawals` ADD COLUMN `adjusted_amount` decimal(15,2) unsigned NOT NULL DEFAULT 0 AFTER `total_amount`",
        'fd_withdrawals.settled_credit_facility_id' => "ALTER TABLE `fd_withdrawals` ADD COLUMN `settled_credit_facility_id` bigint unsigned NULL AFTER `closed_at`, ADD CONSTRAINT `fd_withdrawals_settled_credit_facility_id_foreign` FOREIGN KEY (`settled_credit_facility_id`) REFERENCES `credit_facilities` (`id`) ON DELETE SET NULL",
        'fixed_deposits.lien_amount' => "ALTER TABLE `fixed_deposits` ADD COLUMN `lien_amount` decimal(15,2) unsigned NOT NULL DEFAULT 0 AFTER `maturity_amount`, ADD INDEX (`lien_amount`)",
    ];

    /** table.column that must carry a unique index */
    private const UNIQUE = [
        'facility_payments.code', 'credit_facilities.code', 'finance_transactions.code', 'finance_transactions.idempotency_key',
        'journal_entries.code', 'customers.code', 'fd_withdrawals.fixed_deposit_id',
    ];

    public function handle(): int
    {
        $db = DB::getDatabaseName();
        $missing = 0;
        $this->info("Database: {$db}");

        // Storage engine: MyISAM has no transactions, so a payment row survives the rollback that
        // undoes its number counter and the next save collides with it ("record already exists").
        $nonInno = DB::select('select table_name as t, engine as e, table_collation as c from information_schema.tables where table_schema = ? and table_type = "BASE TABLE" and engine <> "InnoDB"', [$db]);
        foreach ($nonInno as $row) {
            $missing++;
            $this->warn("  MISSING  InnoDB engine on {$row->t} (is {$row->e}, {$row->c})");
            if ($this->option('fix')) {
                try {
                    DB::statement('SET FOREIGN_KEY_CHECKS=0');
                    DB::statement("ALTER TABLE `{$row->t}` ENGINE=InnoDB");
                    if (str_starts_with((string) $row->c, 'latin1')) {
                        DB::statement("ALTER TABLE `{$row->t}` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                    }
                    DB::statement('SET FOREIGN_KEY_CHECKS=1');
                    $this->info('           converted');
                    $missing--;
                } catch (\Throwable $e) {
                    DB::statement('SET FOREIGN_KEY_CHECKS=1');
                    $this->error('           could not convert: ' . $e->getMessage());
                }
            }
        }

        foreach (self::CHECKS as $table => $checks) {
            foreach ($checks as $name => [$expr, $why]) {
                $exists = DB::table('information_schema.TABLE_CONSTRAINTS')
                    ->where('CONSTRAINT_SCHEMA', $db)->where('TABLE_NAME', $table)->where('CONSTRAINT_NAME', $name)->exists();
                if ($exists) {
                    $this->line("  ok       {$table}.{$name}");
                    continue;
                }
                $missing++;
                $this->warn("  MISSING  {$table}.{$name}: {$why}");
                if ($this->option('fix')) {
                    try {
                        DB::statement("ALTER TABLE `{$table}` ADD CONSTRAINT `{$name}` CHECK {$expr}");
                        $this->info('           created');
                        $missing--;
                    } catch (\Throwable $e) {
                        $this->error('           could not create (existing rows violate it?): ' . $e->getMessage());
                    }
                }
            }
        }

        foreach (self::COLUMNS as $col => $ddl) {
            [$table, $column] = explode('.', $col);
            if (DB::table('information_schema.COLUMNS')->where('TABLE_SCHEMA', $db)->where('TABLE_NAME', $table)->where('COLUMN_NAME', $column)->exists()) {
                $this->line("  ok       column {$col}");
                continue;
            }
            $missing++;
            $this->warn("  MISSING  column {$col}");
            if ($this->option('fix')) {
                try {
                    DB::statement($ddl);
                    $this->info('           created');
                    $missing--;
                } catch (\Throwable $e) {
                    $this->error('           could not create: ' . $e->getMessage());
                }
            }
        }

        foreach (self::UNIQUE as $col) {
            [$table, $column] = explode('.', $col);
            $has = DB::table('information_schema.STATISTICS')
                ->where('TABLE_SCHEMA', $db)->where('TABLE_NAME', $table)->where('COLUMN_NAME', $column)
                ->where('NON_UNIQUE', 0)->where('SEQ_IN_INDEX', 1)->exists();
            if ($has) {
                $this->line("  ok       unique {$col}");
            } else {
                $missing++;
                $this->warn("  MISSING  unique index on {$col}");
            }
        }

        $missing ? $this->warn("{$missing} guarantee(s) missing.") : $this->info('All guarantees present.');

        return $missing ? self::FAILURE : self::SUCCESS;
    }
}
