<?php

namespace App\Domain\Exports;

/**
 * E-49 / E-81: one report, ready to export as CSV, Excel or PDF. Column types decide the
 * number format: text, number (whole), hours / days (2 decimals), percent (0-100 value),
 * money (integer minor units; shown with 2 decimals) and date (Y-m-d text).
 */
final class ExportTable
{
    /**
     * @param  array<string, string>  $filters  label => value, printed on every export
     * @param  list<array{key: string, label: string, type: string, currency?: string|null, sum?: bool}>  $columns
     * @param  list<array<string, mixed>>  $rows
     * @param  string|null  $chartKey  numeric column drawn as bars in the PDF
     */
    public function __construct(
        public readonly string $title,
        public readonly array $filters,
        public readonly array $columns,
        public readonly array $rows,
        public readonly ?string $chartKey = null,
        public readonly ?string $labelKey = null,
    ) {}

    /**
     * Whether a column gets a total (money only when asked, as currencies may differ).
     *
     * @param  array{key: string, label: string, type: string, currency?: string|null, sum?: bool}  $col
     */
    public function sums(array $col): bool
    {
        return $col['sum'] ?? in_array($col['type'], ['number', 'hours'], true);
    }

    public function fileName(string $ext): string
    {
        return trim(preg_replace('/[^A-Za-z0-9]+/', '-', strtolower($this->title)) ?? 'report', '-').'-'.now()->format('Y-m-d').'.'.$ext;
    }

    /** A cell as a plain value for CSV (money in major units). */
    public static function plain(mixed $value, string $type): string|int|float
    {
        if ($value === null) {
            return '';
        }
        if ($type === 'money' && is_numeric($value)) {
            return round(((int) $value) / 100, 2);
        }
        if (is_int($value) || is_float($value)) {
            return $value;
        }
        $text = (string) $value;
        // Stop spreadsheet formula injection from names and titles.
        $first = substr(ltrim($text, " \n"), 0, 1);

        return in_array($first, ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$text : $text;
    }
}
