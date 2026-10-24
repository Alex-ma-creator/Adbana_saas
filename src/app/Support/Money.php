<?php

namespace App\Support;

final class Money
{
    public static function cents(string|int|float $value): int
    {
        $parts = explode('.', (string) $value, 2);
        return ((int) $parts[0] * 100) + (int) str_pad($parts[1] ?? '', 2, '0');
    }

    public static function decimal(int $value): string
    {
        return ($value < 0 ? '-' : '').intdiv(abs($value), 100).'.'.str_pad((string) (abs($value) % 100), 2, '0', STR_PAD_LEFT);
    }
}
