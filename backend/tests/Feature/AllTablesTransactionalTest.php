<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Every table must be InnoDB: MyISAM ignores DB::transaction(), so a failed
 * request would leave half-saved rows (e.g. a payment without its approval request).
 */
class AllTablesTransactionalTest extends TestCase
{
    public function test_every_table_is_innodb(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Engine check only applies to MySQL.');
        }

        $bad = DB::select(
            "SELECT table_name AS t, engine AS e FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE' AND engine <> 'InnoDB'"
        );

        $this->assertSame([], array_map(fn ($r) => "{$r->t} ({$r->e})", $bad));
    }
}
