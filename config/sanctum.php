<?php

/*
| Sanctum (P7). Only the keys we change; the package fills in the rest.
| guard = []: API requests authenticate by bearer token only, never by a browser session.
*/
return [
    'guard' => [],

    // Token lifetime is set per token (Settings > API tokens, max config('api.max_token_days')).
    'expiration' => null,
];
