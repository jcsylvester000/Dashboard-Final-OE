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
     * Split an amount by weights so the parts always add up to the amount exactly
     * (largest remainder; ties go to the earlier part). No weights or all zero: the first part takes it all.
     *
     * @param  array<int, int>  $weights
     * @return list<int>
     */
    public static function allocate(int $amount, array $weights): array
    {
        if ($weights === []) {
            return [];
        }
        $total = array_sum(array_map(fn (int $w) => max(0, $w), $weights));
        if ($total <= 0) {
            return array_map(fn (int $i) => $i === 0 ? $amount : 0, array_keys($weights));
        }
        $sign = $amount < 0 ? -1 : 1;
        $abs = abs($amount);
        $parts = [];
        $remainders = [];
        foreach ($weights as $i => $w) {
            $exact = intdiv($abs * max(0, $w), $total);
            $parts[$i] = $exact;
            $remainders[$i] = ($abs * max(0, $w)) % $total;
        }
        $left = $abs - array_sum($parts);
        arsort($remainders, SORT_NUMERIC);
        // arsort keeps equal remainders in index order (stable sort since PHP 8).
        $bonus = [];
        foreach (array_keys($remainders) as $i) {
            if ($left <= 0) {
                break;
            }
            $bonus[$i] = 1;
            $left--;
        }
        $out = [];
        foreach (array_keys($weights) as $i) {
            $out[] = $sign * ($parts[$i] + ($bonus[$i] ?? 0));
        }

        return $out;
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
     * 150050, PHP -> "₱1,500.50"; USD -> "$1,500.50"; EUR -> "€1,500.50".
     */
    public static function format(int $minor, string $currency = 'PHP'): string
    {
        $symbol = config('billing.currency_meta.'.$currency.'.symbol');
        $symbol = is_string($symbol) ? $symbol : $currency.' ';
        $sign = $minor < 0 ? '-' : '';
        $abs = abs($minor);

        return $sign.$symbol.number_format(intdiv($abs, 100)).sprintf('.%02d', $abs % 100);
    }

    /**
     * E-102: on documents a non-peso amount always carries its ISO code so "$" can't be
     * misread (USD vs AUD/SGD): 150050, USD -> "USD 1,500.50"; PHP -> "₱1,500.50".
     */
    public static function formatForDocument(int $minor, string $currency): string
    {
        if ($currency === 'PHP') {
            return self::format($minor, 'PHP');
        }
        $sign = $minor < 0 ? '-' : '';
        $abs = abs($minor);

        return $sign.$currency.' '.number_format(intdiv($abs, 100)).sprintf('.%02d', $abs % 100);
    }

    /**
     * E-80: amount in words for invoices, e.g. 150050 PHP ->
     * "One thousand five hundred pesos and 50/100". No intl extension needed.
     */
    public static function inWords(int $minor, string $currency): string
    {
        $abs = abs($minor);
        $whole = intdiv($abs, 100);
        $cents = $abs % 100;
        $unit = match ($currency) {
            'PHP' => 'pesos',
            'USD' => 'US dollars',
            'EUR' => 'euros',
            'GBP' => 'pounds sterling',
            'AUD' => 'Australian dollars',
            'SGD' => 'Singapore dollars',
            'CAD' => 'Canadian dollars',
            default => $currency,
        };
        $words = ucfirst(self::spell($whole));

        return ($minor < 0 ? 'Minus '.lcfirst($words) : $words).' '.$unit.sprintf(' and %02d/100', $cents);
    }

    private static function spell(int $n): string
    {
        $ones = ['zero', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten',
            'eleven', 'twelve', 'thirteen', 'fourteen', 'fifteen', 'sixteen', 'seventeen', 'eighteen', 'nineteen'];
        $tens = ['', '', 'twenty', 'thirty', 'forty', 'fifty', 'sixty', 'seventy', 'eighty', 'ninety'];

        if ($n < 20) {
            return $ones[$n];
        }
        if ($n < 100) {
            return $tens[intdiv($n, 10)].($n % 10 ? '-'.$ones[$n % 10] : '');
        }
        if ($n < 1000) {
            return $ones[intdiv($n, 100)].' hundred'.($n % 100 ? ' '.self::spell($n % 100) : '');
        }
        foreach ([1_000_000_000 => 'billion', 1_000_000 => 'million', 1000 => 'thousand'] as $size => $name) {
            if ($n >= $size) {
                return self::spell(intdiv($n, $size)).' '.$name.($n % $size ? ' '.self::spell($n % $size) : '');
            }
        }

        return (string) $n;
    }

    /** Regex for validated money input strings. */
    public const INPUT_REGEX = '/^\d{1,12}(\.\d{1,2})?$/';
}
