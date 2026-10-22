<?php

namespace Tests\Unit;

use App\Services\MetalMassCalculator;
use Tests\TestCase;

class MetalMassCalculatorTest extends TestCase
{
    private MetalMassCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new MetalMassCalculator;
    }

    public function test_mass_uses_exact_decimals(): void
    {
        $this->assertSame('520.367', $this->calculator->mass('1234.56', '0.421500'));
        // 0.1 m × 0.3 kg/m: float maths would give 0.030000000000000002
        $this->assertSame('0.030', $this->calculator->mass('0.10', '0.300000'));
        $this->assertSame('0.000', $this->calculator->mass('1000.00', '0.000000'));
    }

    public function test_unknown_factor_gives_unknown_mass_not_zero(): void
    {
        $this->assertNull($this->calculator->mass(null, '0.5'));
        $this->assertNull($this->calculator->mass('100', null));
    }

    public function test_difference_in_kg_and_percent_with_tolerance(): void
    {
        $this->assertSame(['kg' => '-10.000', 'percent' => -2.5, 'exceeds' => false], $this->calculator->difference('390', '400', 3));
        $this->assertSame(['kg' => '-20.000', 'percent' => -5.0, 'exceeds' => true], $this->calculator->difference('380', '400', 3));
        $this->assertSame(['kg' => null, 'percent' => null, 'exceeds' => null], $this->calculator->difference(null, '400', 3));
        // Reference 0: any mass is a difference, percent is undefined
        $this->assertSame(['kg' => '5.000', 'percent' => null, 'exceeds' => true], $this->calculator->difference('5', '0', 3));
        $this->assertSame(['kg' => '0.000', 'percent' => null, 'exceeds' => false], $this->calculator->difference('0', '0', 3));
    }
}
