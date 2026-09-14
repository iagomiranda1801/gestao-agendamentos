<?php

namespace Tests\Unit\Support;

use App\Support\Money;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_converts_decimal_to_cents(): void
    {
        $this->assertSame(2500, Money::toCents('25.00'));
        $this->assertSame(800, Money::toCents(8));
        $this->assertSame(0, Money::toCents(null));
    }

    public function test_formats_cents_in_brazilian_reais(): void
    {
        $this->assertSame('R$ 25,00', Money::formatCents(2500));
        $this->assertSame('R$ 8,00', Money::formatCents(800));
    }

    public function test_converts_cents_to_decimal_string(): void
    {
        $this->assertSame('25.00', Money::fromCents(2500));
        $this->assertSame('8.00', Money::fromCents(800));
        $this->assertSame('0.00', Money::fromCents(0));
    }
}
