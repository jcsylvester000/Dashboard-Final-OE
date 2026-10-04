<?php

namespace App\Domain\Billing;

use InvalidArgumentException;

/**
 * Integer money helpers. Amounts are minor units (centavos / cents); no floats anywhere.
 */
final class Money
{
    /**
     * round(a * b / c) with halves rounded away from zero, in integers only.
     * e.g. 90 minutes at 150000/h: mulDiv(90, 150000, 60) = 225000.
     */
    public static function mulDiv(int $a, int $b, int $c): int
    {
        if ($c <= 0) {
            throw new InvalidArgumentException('Divisor must be positive.');
        }
        $product = $a * $b;
        $sign = $product < 0 ? -1 : 1;
        $abs = abs($product);

        return $sign * intdiv(2 * $abs + $c, 2 * $c);
    }

    /**
     * Tax on an amount: rate in basis points (1200 = 12%).
     */
    public static function tax(int $amountMinor, int $rateBp): int
    {
        return self::mulDiv($amountMinor, $rateBp, 10000);
    }

    /**
     * "1,500.50" / "1500.5" / "1500" -> 150050. Up to 2 decimals; no float parsing.
     */
    public static function parse(string $value): int
    {
        $clean = str_replace([',', ' '], '', trim($value));
        if (! preg_match('/^(-?)(\d{1,12})(?:\.(\d{1,2}))?$/', $clean, $m)) {
            throw new InvalidArgumentException("Not a money amount: {$value}");
        }
        $minor = (int) $m[2] * 100 + (int) str_pad($m[3] ?? '', 2, '0');

        return $m[1] === '-' ? -$minor : $minor;
    }

    /**
     * 150050 -> "1500.50" (for form inputs).
     */
    public static function toDecimal(int $minor): string
    {
        $sign = $minor < 0 ? '-' : '';
        $abs = abs($minor);

        return sprintf('%s%d.%02d', $sign, intdiv($abs, 100), $abs % 100);
    }

    /**
     * 150050, PHP -> "₱1,500.50"; USD -> "$1,500.50".
     */
    public static function format(int $minor, string $currency = 'PHP'): string
    {
        $symbol = match ($currency) {
            'PHP' => "\u{20B1}",
            'USD' => '$',
            default => $currency.' ',
        };
        $sign = $minor < 0 ? '-' : '';
        $abs = abs($minor);

        return $sign.$symbol.number_format(intdiv($abs, 100)).sprintf('.%02d', $abs % 100);
    }

    /** Regex for validated money input strings. */
    public const INPUT_REGEX = '/^\d{1,12}(\.\d{1,2})?$/';
}
