<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Compares a database with the schema that running every migration produces.
 *
 *   db:schema-export   writes that schema (engines, columns, primary/unique indexes, foreign keys, CHECK
 *                      constraints) to database/schema/expected-schema.json. Run it on a database built only by
 *                      migrations (restore database/schema/mysql-schema.dump, then `php artisan migrate`).
 *   db:schema-diff     reports everything the current database lacks or has different, and with --print-sql
 *                      prints the ALTER statements that fix it, or with --fix runs them.
 *
 * Why: a database restored from a dump or patched by hand can have its `migrations` table say "done" while the
 * real structure lacks columns, foreign keys, CHECK constraints or AUTO_INCREMENT (found on the dev database:
 * 36 CHECKs, ~58 foreign keys, a primary key and several columns missing). The application keeps running and
 * quietly loses its safety net. Nothing here deletes data or drops anything.
 */
class SchemaBaseline extends Command
{
    protected $signature = 'db:schema-diff
        {--baseline= : path to the baseline JSON (default database/schema/expected-schema.json)}
        {--print-sql : print the fix statements instead of running them}
        {--fix : run the fix statements (each is tried separately; failures are reported)}
        {--export : write the baseline from THIS database instead of comparing}';

    protected $description = 'Compare this database with the migration-built baseline schema; print or apply the fixes';

    public function handle(): int
    {
        $path = $this->option('baseline') ?: database_path('schema/expected-schema.json');
        if ($this->option('export')) {
            file_put_contents($path, json_encode($this->snapshot(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $this->info("Baseline written to {$path}");

            return self::SUCCESS;
        }
        if (!is_file($path)) {
            $this->error("No baseline at {$path}. Run `php artisan db:schema-diff --export` on a migration-built database first.");

            return self::FAILURE;
        }
        $expected = json_decode(file_get_contents($path), true);
        $actual = $this->snapshot();

        $problems = [];   // [description, sql|null]
        foreach ($expected['tables'] as $table => $t) {
            if (!isset($actual['tables'][$table])) {
                $problems[] = ["table `{$table}` is missing (run `php artisan migrate`)", null];
                continue;
            }
            $a = $actual['tables'][$table];
            if ($t['engine'] !== $a['engine']) {
                $problems[] = ["`{$table}` engine is {$a['engine']}, expected {$t['engine']}", "ALTER TABLE `{$table}` ENGINE={$t['engine']}"];
            }
            foreach ($t['columns'] as $name => $c) {
                if (!isset($a['columns'][$name])) {
                    $problems[] = ["column `{$table}`.`{$name}` is missing", $this->addColumn($table, $name, $c)];
                } elseif ($a['columns'][$name]['type'] !== $c['type'] || $a['columns'][$name]['auto'] !== $c['auto']) {
                    $problems[] = [sprintf('column `%s`.`%s` is %s%s, expected %s%s', $table, $name, $a['columns'][$name]['type'], $a['columns'][$name]['auto'] ? ' AUTO_INCREMENT' : '', $c['type'], $c['auto'] ? ' AUTO_INCREMENT' : ''),
                        $this->safeToModify($a['columns'][$name], $c) ? $this->modifyColumn($table, $name, $c, $t['pk']) : null];
                }
            }
            if ($t['pk'] && $a['pk'] !== $t['pk']) {
                $problems[] = ["`{$table}` primary key is [" . implode(',', $a['pk']) . '], expected [' . implode(',', $t['pk']) . ']',
                    $a['pk'] ? null : "ALTER TABLE `{$table}` ADD PRIMARY KEY (`" . implode('`,`', $t['pk']) . '`)'];
            }
            foreach ($t['unique'] as $idx => $cols) {
                if (!$this->hasIndexOn($a, $cols, true)) {
                    $problems[] = ["unique index `{$table}`.`{$idx}` (" . implode(',', $cols) . ') is missing',
                        "ALTER TABLE `{$table}` ADD UNIQUE INDEX `{$idx}` (`" . implode('`,`', $cols) . '`)'];
                }
            }
            foreach ($t['fks'] as $fk => $f) {
                if (!isset($a['fks'][$fk]) && !$this->hasEquivalentFk($a, $f)) {
                    $problems[] = ["foreign key `{$table}`.`{$fk}` is missing",
                        "ALTER TABLE `{$table}` ADD CONSTRAINT `{$fk}` FOREIGN KEY (`" . implode('`,`', $f['cols']) . "`) REFERENCES `{$f['ref_table']}` (`" . implode('`,`', $f['ref_cols']) . "`) ON DELETE {$f['on_delete']} ON UPDATE {$f['on_update']}"];
                }
            }
            foreach ($t['checks'] as $name => $clause) {
                // MySQL/MariaDB add a json_valid() check to JSON columns themselves and name it differently; not compared.
                if (preg_match('/_chk_\d+$/', $name) && str_contains($clause, 'json_valid')) {
                    continue;
                }
                if (!isset($a['checks'][$name])) {
                    $problems[] = ["CHECK `{$table}`.`{$name}` is missing", "ALTER TABLE `{$table}` ADD CONSTRAINT `{$name}` CHECK " . stripslashes($clause)];
                }
            }
        }

        if (!$problems) {
            $this->info('Database matches the baseline.');

            return self::SUCCESS;
        }
        // Columns first, then keys, then constraints: later statements depend on earlier ones.
        $order = function ($p) {
            $q = (string) $p[1];

            return str_contains($q, 'ENGINE=') ? 0 : (str_contains($q, 'ADD COLUMN') ? 1 : (str_contains($q, 'ADD PRIMARY') ? 2 : (str_contains($q, ' MODIFY ') ? 3 : (str_contains($q, 'ADD UNIQUE') ? 4 : (str_contains($q, 'FOREIGN KEY') ? 5 : 6)))));
        };
        usort($problems, fn ($x, $y) => $order($x) <=> $order($y));

        $unfixable = 0;
        $failed = 0;
        foreach ($problems as [$why, $sql]) {
            if ($this->option('print-sql') && $sql) {
                $this->line($sql . ';');
                continue;
            }
            $this->warn('  ' . $why);
            if (!$sql) {
                $unfixable++;
                $this->line('     (needs a migration or manual review)');
            } elseif ($this->option('fix')) {
                try {
                    DB::statement($sql);
                    $this->info('     fixed');
                } catch (\Throwable $e) {
                    $failed++;
                    $this->error('     could not apply (existing data may violate it): ' . substr($e->getMessage(), 0, 200));
                }
            }
        }
        if (!$this->option('print-sql')) {
            $this->warn(count($problems) . " difference(s); {$unfixable} need manual attention; {$failed} failed to apply.");
        }

        return self::FAILURE;
    }

    /** Only changes that cannot lose data: AUTO_INCREMENT, or an enum that gains values. Anything else (shrinking varchar, json vs text) is reported for a human. */
    private function safeToModify(array $actual, array $expected): bool
    {
        if ($expected['generated']) {
            return false;
        }
        if ($actual['type'] === $expected['type']) {
            return true; // only AUTO_INCREMENT differs
        }
        if (str_starts_with($actual['type'], 'enum(') && str_starts_with($expected['type'], 'enum(')) {
            preg_match_all("/'((?:[^']|'')*)'/", $actual['type'], $x);
            preg_match_all("/'((?:[^']|'')*)'/", $expected['type'], $y);

            return !array_diff($x[1], $y[1]);
        }

        return false;
    }

    private function hasIndexOn(array $table, array $cols, bool $unique): bool
    {
        foreach (array_merge($table['unique'] ?? [], $unique ? [] : ($table['index'] ?? [])) as $existing) {
            if ($existing === $cols) {
                return true;
            }
        }

        return $unique && $table['pk'] === $cols;
    }

    private function hasEquivalentFk(array $table, array $f): bool
    {
        foreach ($table['fks'] as $x) {
            if ($x['cols'] === $f['cols'] && $x['ref_table'] === $f['ref_table'] && $x['ref_cols'] === $f['ref_cols']) {
                return true;
            }
        }

        return false;
    }

    private function colSql(string $name, array $c): string
    {
        $sql = "`{$name}` {$c['type']}" . ($c['collation'] && str_contains($c['type'], 'char') ? '' : '');
        $sql .= $c['nullable'] ? ' NULL' : ' NOT NULL';
        if ($c['default'] !== null && !$c['auto']) {
            $d = $c['default'];
            $sql .= (preg_match('/^(CURRENT_TIMESTAMP|current_timestamp)/', (string) $d) || $c['default_is_expr']) ? " DEFAULT {$d}" : ' DEFAULT ' . DB::getPdo()->quote((string) $d);
        } elseif ($c['nullable'] && !$c['auto']) {
            $sql .= ' DEFAULT NULL';
        }

        return $sql . ($c['auto'] ? ' AUTO_INCREMENT' : '');
    }

    private function addColumn(string $table, string $name, array $c): ?string
    {
        if ($c['generated']) {
            return null;
        }

        return "ALTER TABLE `{$table}` ADD COLUMN " . $this->colSql($name, $c);
    }

    private function modifyColumn(string $table, string $name, array $c, array $pk): string
    {
        // AUTO_INCREMENT needs the column to be a key; the primary key fix runs first when it is missing.
        return "ALTER TABLE `{$table}` MODIFY " . $this->colSql($name, $c) . (($c['auto'] && !in_array($name, $pk, true)) ? ', ADD UNIQUE INDEX (`' . $name . '`)' : '');
    }

    private function snapshot(): array
    {
        $db = DB::getDatabaseName();
        $out = ['tables' => []];
        foreach (DB::select('select table_name t, engine e from information_schema.tables where table_schema = ? and table_type = "BASE TABLE" order by 1', [$db]) as $r) {
            $out['tables'][$r->t] = ['engine' => $r->e, 'columns' => [], 'pk' => [], 'unique' => [], 'index' => [], 'fks' => [], 'checks' => []];
        }
        foreach (DB::select('select table_name t, column_name c, column_type ty, is_nullable n, column_default d, extra x, collation_name co from information_schema.columns where table_schema = ? order by table_name, ordinal_position', [$db]) as $r) {
            if (!isset($out['tables'][$r->t])) {
                continue;
            }
            $x = strtolower((string) $r->x);
            $out['tables'][$r->t]['columns'][$r->c] = [
                'type' => $r->ty, 'nullable' => $r->n === 'YES', 'default' => $r->d,
                'default_is_expr' => str_contains($x, 'default_generated'),
                'auto' => str_contains($x, 'auto_increment'),
                'generated' => str_contains($x, 'generated') && !str_contains($x, 'default_generated'),
                'collation' => $r->co,
            ];
        }
        $idx = [];
        foreach (DB::select('select table_name t, index_name i, non_unique nu, column_name c from information_schema.statistics where table_schema = ? order by table_name, index_name, seq_in_index', [$db]) as $r) {
            $idx[$r->t][$r->i]['nu'] = (int) $r->nu;
            $idx[$r->t][$r->i]['cols'][] = $r->c;
        }
        foreach ($idx as $t => $indexes) {
            foreach ($indexes as $name => $i) {
                if (!isset($out['tables'][$t])) {
                    continue;
                }
                if ($name === 'PRIMARY') {
                    $out['tables'][$t]['pk'] = $i['cols'];
                } elseif ($i['nu'] === 0) {
                    $out['tables'][$t]['unique'][$name] = $i['cols'];
                } else {
                    $out['tables'][$t]['index'][$name] = $i['cols'];
                }
            }
        }
        $fk = [];
        foreach (DB::select('select k.table_name t, k.constraint_name n, k.column_name c, k.referenced_table_name rt, k.referenced_column_name rc, r.delete_rule dr, r.update_rule ur
              from information_schema.key_column_usage k join information_schema.referential_constraints r on r.constraint_schema = k.constraint_schema and r.constraint_name = k.constraint_name and r.table_name = k.table_name
              where k.table_schema = ? and k.referenced_table_name is not null order by k.table_name, k.constraint_name, k.ordinal_position', [$db]) as $r) {
            $f = &$fk[$r->t][$r->n];
            $f['cols'][] = $r->c;
            $f['ref_cols'][] = $r->rc;
            $f['ref_table'] = $r->rt;
            $f['on_delete'] = $r->dr;
            $f['on_update'] = $r->ur;
            unset($f);
        }
        foreach ($fk as $t => $list) {
            if (isset($out['tables'][$t])) {
                $out['tables'][$t]['fks'] = $list;
            }
        }
        foreach (DB::select('select tc.table_name t, tc.constraint_name n, cc.check_clause cl from information_schema.table_constraints tc join information_schema.check_constraints cc on cc.constraint_schema = tc.constraint_schema and cc.constraint_name = tc.constraint_name where tc.table_schema = ? and tc.constraint_type = "CHECK"', [$db]) as $r) {
            if (isset($out['tables'][$r->t])) {
                $out['tables'][$r->t]['checks'][$r->n] = $r->cl;
            }
        }

        return $out;
    }
}
