<?php

namespace Tests\Unit;

use App\Support\Decimal;
use PHPUnit\Framework\TestCase;

class DecimalTest extends TestCase
{
    public function test_sums_without_floating_point_drift(): void
    {
        // 0.1 + 0.2 = 0.30000000000000004 in float maths
        $this->assertSame('0.30', Decimal::sum(['0.1', '0.2']));
        $this->assertSame('1000000.03', Decimal::sum(['999999.01', '1.01', '0.01']));
        $this->assertSame('5.000', Decimal::sum(['1.5', null, '3.5'], 3));
    }

    public function test_difference_and_percent(): void
    {
        $this->assertSame('-100.00', Decimal::diff('900', '1000'));
        $this->assertSame(33.3, Decimal::percent('1', '3'));
        $this->assertNull(Decimal::percent('1', '0'));
    }

    public function test_tolerance_uses_the_larger_of_absolute_and_relative_limits(): void
    {
        // 2% of 1000 = 20 m > 5 m
        $this->assertFalse(Decimal::exceedsTolerance('1000', '1020', 5, 2));
        $this->assertTrue(Decimal::exceedsTolerance('1000', '1020.01', 5, 2));
        // 2% of 100 = 2 m < 5 m
        $this->assertFalse(Decimal::exceedsTolerance('100', '95', 5, 2));
        $this->assertTrue(Decimal::exceedsTolerance('100', '94.99', 5, 2));
    }
}
