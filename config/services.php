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
    | Google (per-shop OAuth for GA4 Admin API Key Event creation)
    |--------------------------------------------------------------------------
    |
    | The app has ONE OAuth client (registered in Google Cloud Console), but
    | every shop connects ITS OWN Google account through it via
    | App\Actions\Google\StartShopGoogleOAuth. The resulting refresh_token is
    | stored per-shop in that shop's own PlatformIntegration credentials
    | (oauth_refresh_token) — there is no shared/global Google account. This
    | is unrelated to the GA4 Measurement Protocol credentials (measurement_id
    | + api_secret) used by App\Services\GoogleAnalytics4Client to send events.
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

];
