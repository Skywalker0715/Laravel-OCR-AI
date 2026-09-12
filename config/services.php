<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

        'cohere' => [
        'api_key' => env('COHERE_API_KEY'),
        // 'command-light' sudah tidak tersedia di Cohere (hapus Sep 2025).
        // Model default command-r7b-12-2024 (lebih ringan/cepat); override
        // lewat .env (COHERE_MODEL) bila inginkan model lain. Lihat
        // https://docs.cohere.com/docs/models
        'model' => env('COHERE_MODEL', 'command-r7b-12-2024'),
    ],

];
