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

    'evolution' => [
        'url' => env('EVOLUTION_API_URL'),
        'key' => env('EVOLUTION_API_KEY'),
        'instance' => env('EVOLUTION_INSTANCE'),
        'webhook_token' => env('EVOLUTION_WEBHOOK_TOKEN'),
        'outbound' => [
            'min_interval_seconds' => (int) env('EVOLUTION_OUTBOUND_MIN_INTERVAL', 30),
            'max_interval_seconds' => (int) env('EVOLUTION_OUTBOUND_MAX_INTERVAL', 45),
            'jitter_seconds' => (int) env('EVOLUTION_OUTBOUND_JITTER', 10),
            'daily_limit' => (int) env('EVOLUTION_OUTBOUND_DAILY_LIMIT', 80),
            'circuit_failures' => (int) env('EVOLUTION_OUTBOUND_CIRCUIT_FAILURES', 5),
            'circuit_pause_minutes' => (int) env('EVOLUTION_OUTBOUND_CIRCUIT_PAUSE', 120),
        ],
    ],

];
