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
 *  - expense_approval (E-75): expenses wait for a second Finance user before billing.
 *  - receipt_thresholds (E-75): a receipt is required from this amount, per currency.
 */
class FinanceSettings
{
    public const REVIEW_MODES = ['off' => 'Off', 'always' => 'Every invoice', 'above' => 'Invoices above an amount'];

    public const DEFAULT_BACKDATE_DAYS = 31;

    public function __construct(private readonly AppSettings $settings) {}

    /**
     * @return array{review: string, thresholds: array<string, int>, backdate_days: int, expense_approval: bool, receipt_thresholds: array<string, int>}
     */
    public function get(): array
    {
        $saved = (array) $this->settings->get('finance', []);
        $mode = is_string($saved['review'] ?? null) && array_key_exists($saved['review'], self::REVIEW_MODES) ? $saved['review'] : 'off';
        $days = $saved['backdate_days'] ?? self::DEFAULT_BACKDATE_DAYS;

        return [
            'review' => $mode,
            'thresholds' => self::amounts($saved['thresholds'] ?? []),
            'backdate_days' => is_int($days) && $days >= 0 && $days <= 366 ? $days : self::DEFAULT_BACKDATE_DAYS,
            'expense_approval' => ($saved['expense_approval'] ?? false) === true,
            'receipt_thresholds' => self::amounts($saved['receipt_thresholds'] ?? []),
        ];
    }

    /**
     * @return array<string, int>
     */
    private static function amounts(mixed $raw): array
    {
        $out = [];
        foreach ((array) $raw as $currency => $minor) {
            if (is_string($currency) && is_int($minor) && $minor > 0) {
                $out[$currency] = $minor;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $values  merged over what is saved
     */
    public function save(array $values): void
    {
        $this->settings->set('finance', [...(array) $this->settings->get('finance', []), ...$values]);
    }

    /** E-75: receipt required for this expense amount (client currency)? */
    public function needsReceipt(string $currency, int $amountMinor): bool
    {
        $from = $this->get()['receipt_thresholds'][$currency] ?? null;

        return $from !== null && $amountMinor >= $from;
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
