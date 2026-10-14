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
];
