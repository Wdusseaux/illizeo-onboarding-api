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

    'mailtrap-sdk' => [
        'host' => env('MAILTRAP_HOST', 'send.api.mailtrap.io'),
        'apiKey' => env('MAILTRAP_API_KEY'),
        'inboxId' => env('MAILTRAP_INBOX_ID'),
    ],

    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_MODEL', 'claude-opus-4-6'),
    ],

    'stripe' => [
        'mode' => env('STRIPE_MODE', 'live'),
        'key' => env('STRIPE_MODE', 'live') === 'test' ? env('STRIPE_TEST_KEY') : env('STRIPE_KEY'),
        'secret' => env('STRIPE_MODE', 'live') === 'test' ? env('STRIPE_TEST_SECRET') : env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_MODE', 'live') === 'test' ? env('STRIPE_TEST_WEBHOOK_SECRET') : env('STRIPE_WEBHOOK_SECRET'),
        'live_key' => env('STRIPE_KEY'),
        'live_secret' => env('STRIPE_SECRET'),
        'live_webhook' => env('STRIPE_WEBHOOK_SECRET'),
        'test_key' => env('STRIPE_TEST_KEY'),
        'test_secret' => env('STRIPE_TEST_SECRET'),
        'test_webhook' => env('STRIPE_TEST_WEBHOOK_SECRET'),
    ],

];
