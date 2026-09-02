<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Re-adds chk_journal_lines_one_side (F-2).
 *
 * The original create-table migration (2026_08_01_000007) defined this
 * constraint, but it was lost when the schema was rebuilt from a mysqldump
 * taken without it — so on the live database a (debit = 0, credit = 0) or a
 * two-sided journal line inserts with no error, leaving LedgerPoster::post()'s
 * PHP-level total-balance check as the only guard (and that check does not look
 * at individual lines).
 *
 * A journal line is strictly one-sided and non-zero: exactly one of debit /
 * credit is positive, the other is zero.
 *
 * Verified against the live data before writing this: zero rows violate it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if ($this->exists()) {
            return;
        }
        DB::statement(<<<'SQL'
            ALTER TABLE `journal_lines` ADD CONSTRAINT `chk_journal_lines_one_side` CHECK (
                (`debit` = 0 AND `credit` > 0) OR (`debit` > 0 AND `credit` = 0)
            )
        SQL);
    }

    public function down(): void
    {
        if ($this->exists()) {
            DB::statement('ALTER TABLE `journal_lines` DROP CHECK `chk_journal_lines_one_side`');
        }
    }

    private function exists(): bool
    {
        return DB::table('information_schema.TABLE_CONSTRAINTS')
            ->whereRaw('TABLE_SCHEMA = DATABASE()')
            ->where('TABLE_NAME', 'journal_lines')
            ->where('CONSTRAINT_NAME', 'chk_journal_lines_one_side')
            ->exists();
    }
};
