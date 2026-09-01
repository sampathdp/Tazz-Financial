<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected function setUp(): void
    {
        parent::setUp();
        // The GL balance memo (ChartOfAccount::balancesAsOf) is "per request";
        // a test run is one long process, so clear it between cases or a
        // prior test's figures leak into the next.
        \App\Models\ChartOfAccount::flushBalancesCache();
    }
}
