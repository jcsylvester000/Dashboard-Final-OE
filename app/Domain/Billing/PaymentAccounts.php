<?php

namespace App\Domain\Billing;

use App\Domain\Settings\AppSettings;
use App\Models\BillingProfile;

/**
 * E-106: the agency's payment details per currency (PHP bank / GCash, USD account,
 * EUR IBAN + BIC, Wise or PayPal links). Documents show the set for their currency;
 * a client's own instructions (billing profile) still take priority when filled in.
 */
class PaymentAccounts
{
    public function __construct(private readonly AppSettings $settings) {}

    /**
     * @return array<string, string>
     */
    public function all(): array
    {
        $out = [];
        foreach ((array) $this->settings->get('payment_accounts', []) as $currency => $text) {
            if (is_string($currency) && is_string($text) && trim($text) !== '') {
                $out[$currency] = $text;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, string|null>  $accounts
     */
    public function save(array $accounts): void
    {
        $this->settings->set('payment_accounts', array_filter(array_map(fn ($t) => trim((string) $t), $accounts), fn ($t) => $t !== ''));
    }

    /** What a document in this currency tells the client to pay to. */
    public function for(?BillingProfile $profile, string $currency): ?string
    {
        $own = $profile?->payment_instructions;
        if ($own !== null && trim($own) !== '') {
            return $own;
        }

        return $this->all()[$currency] ?? null;
    }
}
