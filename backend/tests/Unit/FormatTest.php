<?php

namespace Tests\Unit;

use App\Support\Format;
use PHPUnit\Framework\TestCase;

class FormatTest extends TestCase
{
    public function test_numbers_match_the_ui_format(): void
    {
        $this->assertSame('4 971,74 m', Format::metres('4971.740'));
        $this->assertSame('418', Format::number('418.000', 3));
        $this->assertSame('-100 m', Format::metres('-100.00'));
        $this->assertSame('—', Format::number(null));
    }
}
