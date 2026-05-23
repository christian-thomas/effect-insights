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

    'cloudflare' => [
        'account_id' => env('CF_ACCOUNT_ID'),
        'api_token' => env('CF_API_TOKEN'),
        'kv_namespace_id' => env('CF_KV_NAMESPACE_ID'),
        // Optional override for tests / local — defaults to Cloudflare's real API.
        'api_base' => env('CF_API_BASE', 'https://api.cloudflare.com/client/v4'),
    ],

    'consent_ingest' => [
        // Shared secret the edge consent worker sends in X-Effect-Ingest-Secret
        // when mirroring events from D1 to Laravel. Must match the
        // CONSENT_INGEST_SECRET env var on the worker.
        'secret' => env('CONSENT_INGEST_SECRET'),
    ],

];
