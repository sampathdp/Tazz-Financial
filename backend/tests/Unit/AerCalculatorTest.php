<?php

namespace Tests\Unit;

use App\Support\AerCalculator;
use PHPUnit\Framework\TestCase;

class AerCalculatorTest extends TestCase
{
    /** Worked example from the spec: a 2.52% monthly reducing rate over 12
     *  installments implies an EMI of ~9760.52 on a 100000 principal, and
     *  compounds to a ~34.80% AER. Feeding that EMI back in should recover
     *  both figures. */
    public function test_recovers_the_spec_worked_example()
    {
        $result = AerCalculator::calculate(100000.0, 9760.52, 12, 1.0);

        $this->assertNotNull($result);
        $this->assertEqualsWithDelta(2.52, $result['monthly'], 0.01);
        $this->assertEqualsWithDelta(34.80, $result['annual'], 0.02);
    }

    public function test_compounds_periodic_rate_up_through_monthly_for_non_monthly_frequencies()
    {
        // Weekly frequency: 4 periods/month. A weekly EMI implying a small
        // periodic rate should compound up via periodsPerMonth before annualizing.
        $result = AerCalculator::calculate(50000.0, 1050.0, 52, 4.0);

        $this->assertNotNull($result);
        $this->assertGreaterThan(0, $result['monthly']);
        $this->assertGreaterThan($result['monthly'], $result['annual']);
    }

    public function test_returns_null_for_non_positive_principal()
    {
        $this->assertNull(AerCalculator::calculate(0.0, 100.0, 12, 1.0));
        $this->assertNull(AerCalculator::calculate(-1000.0, 100.0, 12, 1.0));
    }

    public function test_returns_null_for_non_positive_rental()
    {
        $this->assertNull(AerCalculator::calculate(10000.0, 0.0, 12, 1.0));
        $this->assertNull(AerCalculator::calculate(10000.0, -50.0, 12, 1.0));
    }

    public function test_returns_null_for_invalid_installment_count()
    {
        $this->assertNull(AerCalculator::calculate(10000.0, 900.0, 0, 1.0));
    }

    /** A rental at or below straight-line principal/n implies zero or negative
     *  interest — not a valid flat-rate EMI to invert, so this must skip rather
     *  than return a nonsensical negative rate. */
    public function test_returns_null_when_rental_does_not_exceed_straight_line_principal()
    {
        $this->assertNull(AerCalculator::calculate(12000.0, 1000.0, 12, 1.0));
    }
}
