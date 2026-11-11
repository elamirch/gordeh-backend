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

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Kavenegar verify/lookup endpoint (the API key is part of the URL). Read through config so it
    // still resolves after `php artisan config:cache`, where env() outside config files returns null.
    'kavenegar' => [
        'lookup_url' => env('SMS_API_URL'),
        // Gets a heads-up SMS for new tickets, patient replies, callback requests and insurance requests. Empty = off.
        'support_admin_phone' => env('SMS_SUPPORT_ADMIN_PHONE'),
    ],

    'metis' => [
        'api_key' => env('METIS_API_KEY'),
        'gemini_model' => env('METIS_GEMINI_MODEL', 'gemini-2.5-pro'),
        'base_url' => env('METIS_BASE_URL', 'https://api.metisai.ir/v1beta/models'),
    ],

];
