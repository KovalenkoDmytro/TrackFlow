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

    /*
    |--------------------------------------------------------------------------
    | Google (shared operator OAuth for GA4 Data API reporting)
    |--------------------------------------------------------------------------
    |
    | A single Google account is connected once by an operator (see
    | App\Actions\Google and the "connect-google" Gate below) and its refresh
    | token is shared by the whole app to call the GA4 Data/Admin APIs on
    | behalf of every shop's own GA4 property. This is unrelated to the
    | per-shop GA4 Measurement Protocol credentials (measurement_id +
    | api_secret) used by App\Services\GoogleAnalytics4Client for sending
    | events.
    |
    */
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_OAUTH_REDIRECT'),
        'scopes' => [
            'https://www.googleapis.com/auth/analytics.readonly',
            'https://www.googleapis.com/auth/analytics.edit',
        ],
    ],

    'operators' => array_values(array_filter(array_map('trim', explode(',', (string) env('APP_OPERATOR_EMAILS', ''))))),

];
