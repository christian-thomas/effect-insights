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
        // ID of the D1 database the edge consent worker writes to (the
        // `consent-events:archive` job reads from this). `wrangler d1 list`.
        'd1_database_id' => env('CF_D1_DATABASE_ID'),
        // Optional override for tests / local — defaults to Cloudflare's real API.
        'api_base' => env('CF_API_BASE', 'https://api.cloudflare.com/client/v4'),
    ],

    'consent_ingest' => [
        // Shared secret the edge consent worker sends in X-Effect-Ingest-Secret
        // when mirroring events from D1 to Laravel. Must match the
        // CONSENT_INGEST_SECRET env var on the worker.
        'secret' => env('CONSENT_INGEST_SECRET'),
    ],

    'scanner' => [
        // URL of the deployed Cloudflare scanner worker (apps/edge/wrangler.scanner.toml).
        // POST /scan with { urls, domainFqdn } and the X-Effect-Scanner-Secret header.
        'worker_url' => env('SCANNER_WORKER_URL'),
        // Shared secret — must match the SCANNER_SECRET set on the worker
        // via `wrangler secret put SCANNER_SECRET`.
        'secret' => env('SCANNER_WORKER_SECRET'),
        'timeout_seconds' => (int) env('SCANNER_TIMEOUT_SECONDS', 60),
    ],

    'banner_config' => [
        // HMAC-SHA-256 key used to sign banner config payloads when they're
        // published to Cloudflare KV. Same value lives on the edge config
        // worker (`wrangler secret put BANNER_CONFIG_SIGNING_KEY`); the worker
        // re-computes the signature on read and rejects mismatches.
        // Generate with `openssl rand -hex 32`.
        'signing_key' => env('BANNER_CONFIG_SIGNING_KEY'),
    ],

    'banner' => [
        // Base URL the admin preview iframe loads cmp.js from. Defaults to
        // the production CDN; in dev, point at the local Vite/Bun banner
        // server (e.g. http://localhost:4173/dist) so previews use unbuilt
        // bundles immediately.
        'cdn_url' => env('BANNER_CDN_URL', 'https://assets.effectcmp.com'),
    ],

];
