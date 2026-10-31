<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Loads a production dump (phpMyAdmin export from the live MariaDB server) into a LOCAL MySQL database.
 *
 * Why not just import it in phpMyAdmin: the live server is MariaDB 11.4 and still has MyISAM tables, so its
 * dump contains things MySQL rejects. The one that stops the import every time is
 *     ALTER TABLE `branch_funding_agreements` ADD UNIQUE KEY `..._code_unique` (`code`) USING HASH
 * -> "#1071 Specified key was too long; max key length is 1000 bytes" (a varchar(255) utf8mb4 key is 1020 bytes,
 * MariaDB hides that behind a hash index, MySQL's MyISAM cannot). This command removes the MariaDB-only
 * `USING HASH`, creates every table as InnoDB (MyISAM has no transactions, which is also the bug the live
 * server needs fixed) and loads the file statement by statement, reporting each error instead of stopping.
 *
 * It never touches the file and refuses to overwrite a database unless --force is given.
 *   php artisan db:import-dump "zemimycf_tazz-investment(6).sql" tazz_live --force
 * then point .env DB_DATABASE (or DB_DATABASE_TEST for tests) at tazz_live.
 */
class ImportDump extends Command
{
    protected $signature = 'db:import-dump
        {file : path to the .sql dump}
        {database : local database to create and load}
        {--force : drop the target database first if it already exists}
        {--keep-myisam : do not convert MyISAM tables to InnoDB}';

    protected $description = 'Load a live (MariaDB) phpMyAdmin dump into a local MySQL database, fixing what MySQL rejects';

    public function handle(): int
    {
        $file = (string) $this->argument('file');
        $db = (string) $this->argument('database');
        if (!is_file($file)) {
            $this->error("File not found: {$file}");

            return self::FAILURE;
        }
        if (!preg_match('/^[A-Za-z0-9_\-]+$/', $db)) {
            $this->error('Database name may contain only letters, digits, _ and -.');

            return self::FAILURE;
        }
        if (in_array(strtolower($db), ['mysql', 'sys', 'information_schema', 'performance_schema'], true)) {
            $this->error('Refusing to load into a system database.');

            return self::FAILURE;
        }
        if (strcasecmp($db, (string) config('database.connections.mysql.database')) === 0 && !$this->option('force')) {
            $this->error("'{$db}' is the database this app is configured to use; pass --force if you really want to replace it.");

            return self::FAILURE;
        }
        $pdo = DB::connection()->getPdo();
        $exists = (bool) DB::selectOne('select 1 x from information_schema.schemata where schema_name = ?', [$db]);
        if ($exists && !$this->option('force')) {
            $this->error("Database '{$db}' already exists. Use --force to drop and reload it.");

            return self::FAILURE;
        }

        $sql = str_replace("\r\n", "\n", (string) file_get_contents($file));
        $changes = [];
        $sql = preg_replace('/\)\s+USING HASH/', ')', $sql, -1, $n);
        $changes[] = "removed {$n} MariaDB 'USING HASH' key option(s)";
        if (!$this->option('keep-myisam')) {
            $sql = str_replace('ENGINE=MyISAM', 'ENGINE=InnoDB', $sql, $n);
            $changes[] = "converted {$n} MyISAM table(s) to InnoDB";
        }
        $sql = preg_replace('/ROW_FORMAT=\w+/', '', $sql);

        $pdo->exec("DROP DATABASE IF EXISTS `{$db}`");
        $pdo->exec("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("USE `{$db}`");
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        $pdo->exec("SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO'");

        $ok = 0;
        $errors = [];
        foreach ($this->statements($sql) as $stmt) {
            try {
                $pdo->exec($stmt);
                $ok++;
            } catch (\Throwable $e) {
                $errors[] = substr($e->getMessage(), 0, 200) . '  <=  ' . substr(preg_replace('/\s+/', ' ', $stmt), 0, 100);
            }
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        $tables = (int) DB::selectOne('select count(*) c from information_schema.tables where table_schema = ? and table_type = "BASE TABLE"', [$db])->c;

        foreach ($changes as $c) {
            $this->line("  {$c}");
        }
        $this->info("Loaded {$ok} statements into '{$db}' ({$tables} tables).");
        foreach ($errors as $e) {
            $this->error("  {$e}");
        }

        return $errors ? self::FAILURE : self::SUCCESS;
    }

    /** @return \Generator<string> one complete statement at a time (a phpMyAdmin dump has no DELIMITER blocks) */
    private function statements(string $sql): \Generator
    {
        $buf = '';
        foreach (explode("\n", $sql) as $line) {
            if ($buf === '' && (trim($line) === '' || str_starts_with($line, '--'))) {
                continue;
            }
            $buf .= $line . "\n";
            // A statement ends at a line ending in ';' that is not inside an open quoted string
            // (a backslash plus the next character, e.g. an escaped quote, is not counted).
            if (preg_match('/;\s*$/', $line) && substr_count(preg_replace('/\\\\./s', '', $buf), "'") % 2 === 0) {
                yield trim($buf);
                $buf = '';
            }
        }
        if (trim($buf) !== '') {
            yield trim($buf);
        }
    }
}
