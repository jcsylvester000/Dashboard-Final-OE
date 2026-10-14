<?php

/*
| Account security (E-87). People whose role can manage users, roles, money or the
| system must turn on two-factor sign-in (authenticator app or passkey) within this
| many days of first being asked; after that they can only reach the security page.
*/
return [
    // Off only in the test suite (phpunit.xml), where time travel would trip deadlines.
    'enforce_two_factor' => (bool) env('TWO_FACTOR_ENFORCED', true),
    'two_factor_grace_days' => (int) env('TWO_FACTOR_GRACE_DAYS', 7),

    // E-91: audit rows stay online this long, then move to monthly CSV archives.
    // Money rows (billing.*) are kept longer (BIR books of accounts: 10 years).
    'activity_retention_months' => (int) env('ACTIVITY_RETENTION_MONTHS', 24),
    'billing_activity_retention_months' => (int) env('BILLING_ACTIVITY_RETENTION_MONTHS', 120),
];
