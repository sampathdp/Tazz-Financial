<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Builds the database the test suite runs against: a copy of the working database (structure and
 * data - the suite relies on the seeded chart of accounts, approval types, roles and admin user),
 * named "<DB_DATABASE>_test". Re-run it to refresh the copy (it is dropped and rebuilt) after the
 * working schema changes. Never run it with APP_ENV=testing.
 */
class CloneDatabaseForTests extends Command
{
    protected $signature = 'db:clone-for-tests
        {--no-migrate : skip running pending migrations on the copy}
        {--adopt-pending : record every pending migration as already applied instead of running it (for a working database restored from a dump whose migrations table lags behind its real structure)}';

    protected $description = 'Create / refresh the <database>_test copy used by the test suite';

    public function handle(): int
    {
        $source = (string) config('database.connections.mysql.database');
        if (preg_match('/_test(ing)?$/', $source)) {
            $this->error("The working database '{$source}' already looks like a test database.");

            return self::FAILURE;
        }
        $target = $source . '_test';
        $pdo = DB::connection()->getPdo();

        $pdo->exec("DROP DATABASE IF EXISTS `{$target}`");
        $pdo->exec("CREATE DATABASE `{$target}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');

        $tables = DB::select('select table_name as t from information_schema.tables where table_schema = ? and table_type = "BASE TABLE"', [$source]);
        foreach ($tables as $row) {
            $t = $row->t;
            $pdo->exec("CREATE TABLE `{$target}`.`{$t}` LIKE `{$source}`.`{$t}`");
            // Generated columns can't be inserted into; list the rest explicitly.
            $cols = collect(DB::select('select column_name as c from information_schema.columns where table_schema = ? and table_name = ? and (generation_expression is null or generation_expression = "")', [$source, $t]))
                ->map(fn ($r) => "`{$r->c}`")->implode(',');
            $pdo->exec("INSERT INTO `{$target}`.`{$t}` ({$cols}) SELECT {$cols} FROM `{$source}`.`{$t}`");
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        $this->info('Copied ' . count($tables) . " tables into '{$target}'.");

        if ($this->option('adopt-pending')) {
            $recorded = DB::connection()->getPdo()->query("SELECT migration FROM `{$target}`.migrations")->fetchAll(\PDO::FETCH_COLUMN);
            $files = array_map(fn ($f) => basename($f, '.php'), glob(database_path('migrations/*.php')));
            $adopt = array_diff($files, $recorded);
            $stmt = $pdo->prepare("INSERT INTO `{$target}`.migrations (migration, batch) VALUES (?, 1)");
            foreach ($adopt as $m) {
                $stmt->execute([$m]);
            }
            $this->info('Recorded ' . count($adopt) . ' pending migration(s) as already applied.');
        } elseif (!$this->option('no-migrate')) {
            config(['database.connections.clone_test' => array_merge(config('database.connections.mysql'), ['database' => $target])]);
            try {
                $this->call('migrate', ['--force' => true, '--database' => 'clone_test']);
            } catch (\Throwable $e) {
                // Typically a working database whose `migrations` table lags behind its real structure
                // (a restored dump): a pending migration then tries to add something that already exists.
                $this->warn('Could not bring the copy up to date: ' . $e->getMessage());
                $this->warn("Fix the working database's migrations table (record the migrations that are already applied), then re-run.");

                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }
}
