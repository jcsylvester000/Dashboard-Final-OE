<?php

/*
| Account and audit settings. Sign-in is email + password only (no two-factor or
| passkeys, owner decision 2026-10-14).
*/
return [
    // E-91: audit rows stay online this long, then move to monthly CSV archives.
    // Money rows (billing.*) are kept longer (BIR books of accounts: 10 years).
    'activity_retention_months' => (int) env('ACTIVITY_RETENTION_MONTHS', 24),
    'billing_activity_retention_months' => (int) env('BILLING_ACTIVITY_RETENTION_MONTHS', 120),
];
