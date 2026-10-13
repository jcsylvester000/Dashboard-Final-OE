<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    // UploadThing (team file drive). UPLOADTHING_TOKEN is the v7 token from the dashboard
    // (base64 JSON with apiKey, appId and regions). Files are stored private and served
    // through short-lived signed links; set UPLOADTHING_ACL= (empty) if the app doesn't
    // allow per-file ACLs (check with: php artisan uploadthing:check).
    'uploadthing' => [
        'token' => env('UPLOADTHING_TOKEN'),
        'acl' => env('UPLOADTHING_ACL', 'private'),
        'api_url' => env('UPLOADTHING_API_URL', 'https://api.uploadthing.com'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
