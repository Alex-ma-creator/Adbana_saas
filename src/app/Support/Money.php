<?php

namespace App\Support;

use InvalidArgumentException;

final class Money
{
    public static function cents(string|int|float $value): int
    {
        $text = is_float($value) ? number_format($value, 2, '.', '') : (string) $value;
        if (! preg_match('/^(-?)([0-9]+)(?:\.([0-9]{1,2}))?$/', $text, $parts)) {
            throw new InvalidArgumentException('Importe monetario inválido.');
        }
        $cents = ((int) $parts[2] * 100) + (int) str_pad($parts[3] ?? '', 2, '0');
        return $parts[1] === '-' ? -$cents : $cents;
    }

    public static function decimal(int $value): string
    {
        return ($value < 0 ? '-' : '').intdiv(abs($value), 100).'.'.str_pad((string) (abs($value) % 100), 2, '0', STR_PAD_LEFT);
    }
}
