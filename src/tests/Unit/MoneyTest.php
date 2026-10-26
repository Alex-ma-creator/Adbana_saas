<?php

namespace Tests\Unit;

use App\Support\Money;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_convierte_centavos_y_conserva_el_signo(): void
    {
        foreach (['0.10' => 10, '18.50' => 1850, '-0.50' => -50, '-12.35' => -1235, '100' => 10000] as $importe => $centavos) {
            $this->assertSame($centavos, Money::cents((string) $importe));
            $this->assertSame($centavos, Money::cents(Money::decimal($centavos)));
        }
        $this->assertSame('0.30', Money::decimal(Money::cents('0.10') + Money::cents('0.20')));
    }

    public function test_rechaza_precision_no_admitida(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::cents('10.005');
    }
}
