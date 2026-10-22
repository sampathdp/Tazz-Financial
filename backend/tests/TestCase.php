<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected function setUp(): void
    {
        parent::setUp();
        // Safety net: these tests insert and (for TRUNCATE/DDL) permanently change data.
        $db = \Illuminate\Support\Facades\DB::connection()->getDatabaseName();
        if (!preg_match('/_test(ing)?$/', (string) $db) && !getenv('ALLOW_TESTS_ON_ANY_DB')) {
            throw new \RuntimeException("Refusing to run tests against '{$db}': the database name must end in _test. Run `php artisan db:clone-for-tests` once, or set ALLOW_TESTS_ON_ANY_DB=1.");
        }
        // The GL balance memo (ChartOfAccount::balancesAsOf) is "per request";
        // a test run is one long process, so clear it between cases or a
        // prior test's figures leak into the next.
        \App\Models\ChartOfAccount::flushBalancesCache();
    }
}
