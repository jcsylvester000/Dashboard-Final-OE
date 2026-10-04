<?php

namespace App\Domain\Billing;

use RuntimeException;

/**
 * A billing rule was broken (edit a finalized invoice, overpay, bill a locked period...).
 * Rendered as a 422 validation-style error.
 */
class BillingException extends RuntimeException {}
