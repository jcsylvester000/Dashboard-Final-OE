<?php

namespace App\Domain\Billing;

use App\Domain\Identity\ActivityLogger;
use App\Models\ExchangeRate;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * E-103 / E-104: PHP exchange rates Finance enters by hand (e.g. the BSP reference rate).
 * The base currency is PHP. A day without its own rate uses the last earlier one and is
 * flagged as such. Money stays in integer minor units; conversion uses integer maths on
 * the rate in millionths, rounded half away from zero.
 */
class FxRates
{
    public const BASE = 'PHP';

    public function __construct(private readonly ActivityLogger $activity) {}

    /**
     * @return array{rate: string, on: string, stale: bool}|null null when no rate exists yet
     */
    public function rate(string $currency, CarbonImmutable|string $date): ?array
    {
        $day = $date instanceof CarbonImmutable ? $date->toDateString() : CarbonImmutable::parse($date)->toDateString();
        if ($currency === self::BASE) {
            return ['rate' => '1.000000', 'on' => $day, 'stale' => false];
        }
        $row = ExchangeRate::query()->where('currency', $currency)->whereDate('rate_on', '<=', $day)->orderByDesc('rate_on')->first();

        return $row === null ? null : ['rate' => (string) $row->rate, 'on' => $row->rate_on->toDateString(), 'stale' => $row->rate_on->toDateString() !== $day];
    }

    /** Minor units of a currency -> PHP minor units at a rate ("56.123456"). */
    public static function toPhp(int $minor, ?string $rate): ?int
    {
        if ($rate === null) {
            return null;
        }

        return Money::mulDiv($minor, self::micros($rate), 1_000_000);
    }

    /** "56.123456" -> 56123456 without floats. */
    public static function micros(string $rate): int
    {
        if (preg_match('/^(\d{1,12})(?:\.(\d{1,6}))?\d*$/', trim($rate), $m) !== 1) {
            throw new \InvalidArgumentException("Not a rate: {$rate}");
        }

        return (int) $m[1] * 1_000_000 + (int) str_pad($m[2] ?? '', 6, '0');
    }

    /**
     * Save a day's rate. Changing a saved rate needs a reason and is logged with the old value.
     */
    public function set(string $currency, string $date, string $rate, ?string $note, User $by): ExchangeRate
    {
        if ($currency === self::BASE) {
            throw new BillingException('PHP is the base currency; it has no rate.');
        }
        $existing = ExchangeRate::query()->where('currency', $currency)->whereDate('rate_on', $date)->first();
        if ($existing !== null && trim((string) $note) === '') {
            throw new BillingException(__('There is already a :c rate for :d. Give a reason to change it.', ['c' => $currency, 'd' => $date]));
        }
        $before = $existing?->rate;
        $row = $existing ?? new ExchangeRate(['currency' => $currency, 'rate_on' => $date]);
        $row->fill(['rate' => $rate, 'note' => $note !== null && trim($note) !== '' ? trim($note) : null, 'created_by' => $by->id])->save();

        $this->activity->log($existing !== null ? 'billing.fx-rate-changed' : 'billing.fx-rate-added', $row, [
            'currency' => $currency, 'date' => $date, 'before' => $before !== null ? ['rate' => (string) $before] : null, 'after' => ['rate' => $rate], 'note' => $row->note,
        ], $by);

        return $row;
    }
}
