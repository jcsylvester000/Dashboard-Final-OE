<?php

/*
| API v1 (P7).
*/
return [
    // Requests per minute per token.
    'per_minute' => (int) env('API_PER_MINUTE', 120),

    // Longest a new token may live (days); every token expires.
    'max_token_days' => (int) env('API_MAX_TOKEN_DAYS', 365),
];
