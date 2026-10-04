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

    // Private disk for stored invoice PDFs (never public).
    'disk' => env('BILLING_PDF_DISK', 'local'),
];
