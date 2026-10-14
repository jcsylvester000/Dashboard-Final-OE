<?php

namespace App\Domain\Billing;

use App\Domain\Settings\AppSettings;
use App\Models\Invoice;

/**
 * E-66 / E-67: finance rules admins set in the app (stored in app_settings "finance").
 *
 *  - review: "off" (anyone with billing access finalizes), "always" (a second Finance
 *    user must finalize every invoice) or "above" (only invoices whose total reaches the
 *    threshold for their currency).
 *  - backdate_days: how far back an issue date may be set at finalize.
 */
class FinanceSettings
{
    public const REVIEW_MODES = ['off' => 'Off', 'always' => 'Every invoice', 'above' => 'Invoices above an amount'];

    public const DEFAULT_BACKDATE_DAYS = 31;

    public function __construct(private readonly AppSettings $settings) {}

    /**
     * @return array{review: string, thresholds: array<string, int>, backdate_days: int}
     */
    public function get(): array
    {
        $saved = (array) $this->settings->get('finance', []);
        $mode = is_string($saved['review'] ?? null) && array_key_exists($saved['review'], self::REVIEW_MODES) ? $saved['review'] : 'off';
        $thresholds = [];
        foreach ((array) ($saved['thresholds'] ?? []) as $currency => $minor) {
            if (is_string($currency) && is_int($minor) && $minor > 0) {
                $thresholds[$currency] = $minor;
            }
        }
        $days = $saved['backdate_days'] ?? self::DEFAULT_BACKDATE_DAYS;

        return [
            'review' => $mode,
            'thresholds' => $thresholds,
            'backdate_days' => is_int($days) && $days >= 0 && $days <= 366 ? $days : self::DEFAULT_BACKDATE_DAYS,
        ];
    }

    /**
     * @param  array{review: string, thresholds: array<string, int>, backdate_days: int}  $values
     */
    public function save(array $values): void
    {
        $this->settings->set('finance', $values);
    }

    /** Whether this invoice needs a second person to finalize it. */
    public function needsReview(Invoice $invoice): bool
    {
        if ($invoice->kind !== 'invoice') {
            return false; // credit notes are already a reviewed correction with a reason
        }
        $rule = $this->get();

        return match ($rule['review']) {
            'always' => true,
            'above' => isset($rule['thresholds'][$invoice->currency]) && $invoice->total_minor >= $rule['thresholds'][$invoice->currency],
            default => false,
        };
    }
}
