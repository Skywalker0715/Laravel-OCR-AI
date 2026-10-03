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
        // Model default command-a-03-2025 (akurasi paling baik, tapi paling
        // mahal per token); override lewat .env (COHERE_MODEL) bila ingin
        // lebih hemat, mis. command-r7b-12-2024. Lihat
        // https://docs.cohere.com/docs/models
        'model' => env('COHERE_MODEL', 'command-a-03-2025'),
        // Model opsional khusus untuk fitur "Tanya AI" (FinancialInsightService).
        // Bisa berbeda dari model parsing struk. Kosong = pakai COHERE_MODEL.
        'insight_model' => env('COHERE_INSIGHT_MODEL'),
        // Batas harian pertanyaan Tanya AI per user (default 10).
        'daily_limit' => (int) env('AI_INSIGHT_DAILY_LIMIT', 10),
        // Batas ANTI-SPAM per menit per user (default 30).
        //
        // Berbeda sifatnya dengan daily_limit: yang ini DIHIT untuk setiap
        // percobaan (sukses maupun gagal), sedangkan daily_limit hanya dipotong
        // kalau Cohere benar-benar menjawab. Nilai ini sengaja LEBIH BESAR dari
        // daily_limit supaya user yang bertanya cepat tidak terkunci "terlalu
        // sering" padahal kuota hariannya masih banyak — tugasnya hanya
        // menahan bot atau klik bertubi-tubi, bukan mengatur jatah harian.
        'burst_limit' => (int) env('AI_INSIGHT_BURST_LIMIT', 30),
    ],

];
