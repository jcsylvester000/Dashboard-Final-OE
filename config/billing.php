<?php

/*
| Agency details printed on PDF invoices and work reports (P6). Set in .env.
*/
return [
    'company' => [
        'name' => env('BILLING_COMPANY_NAME', env('APP_NAME', 'OverEasy')),
        'address' => env('BILLING_COMPANY_ADDRESS'),
        'tax_id' => env('BILLING_COMPANY_TIN'),
        'contact' => env('BILLING_COMPANY_CONTACT'),
    ],

    /*
    | E-101: currencies a client can be billed in. Minor units = digits after the
    | decimal point (all 2 here). Turn more on with BILLING_CURRENCIES=PHP,USD,EUR,GBP.
    */
    'currencies' => array_values(array_filter(array_map('trim', explode(',', (string) env('BILLING_CURRENCIES', 'PHP,USD,EUR'))))),
    'currency_meta' => [
        'PHP' => ['symbol' => "\u{20B1}", 'name' => 'Philippine peso', 'decimals' => 2],
        'USD' => ['symbol' => '$', 'name' => 'US dollar', 'decimals' => 2],
        'EUR' => ['symbol' => "\u{20AC}", 'name' => 'Euro', 'decimals' => 2],
        'GBP' => ['symbol' => "\u{00A3}", 'name' => 'British pound', 'decimals' => 2],
        'AUD' => ['symbol' => 'A$', 'name' => 'Australian dollar', 'decimals' => 2],
        'SGD' => ['symbol' => 'S$', 'name' => 'Singapore dollar', 'decimals' => 2],
        'CAD' => ['symbol' => 'C$', 'name' => 'Canadian dollar', 'decimals' => 2],
    ],

    // Private disk for stored invoice PDFs (never public).
    'disk' => env('BILLING_PDF_DISK', 'local'),
];
