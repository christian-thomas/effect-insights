<?php

use App\Actions\Passkeys\ConfigureCeremonyStepManager;
use App\Models\Passkey;
use App\Models\User;
use Spatie\LaravelPasskeys\Actions\FindPasskeyToAuthenticateAction;
use Spatie\LaravelPasskeys\Actions\GeneratePasskeyAuthenticationOptionsAction;
use Spatie\LaravelPasskeys\Actions\GeneratePasskeyRegisterOptionsAction;
use Spatie\LaravelPasskeys\Actions\StorePasskeyAction;

return [
    /*
     * Not used — our SPA controllers return JSON rather than redirecting.
     * Kept here because the package's default AuthenticateUsingPasskeyController
     * reads it; we don't route through that controller.
     */
    'redirect_to_after_login' => '/',

    'actions' => [
        'generate_passkey_register_options' => GeneratePasskeyRegisterOptionsAction::class,
        'store_passkey' => StorePasskeyAction::class,
        'generate_passkey_authentication_options' => GeneratePasskeyAuthenticationOptionsAction::class,
        'find_passkey' => FindPasskeyToAuthenticateAction::class,
        // Project-local subclass that whitelists origins from
        // `passkeys.allowed_origins` so the dev admin on
        // `http://localhost:3001` can register passkeys. Production with
        // HTTPS leaves it unset and falls back to spatie's default.
        'configure_ceremony_step_manager_factory' => ConfigureCeremonyStepManager::class,
    ],

    /*
     * Origins whitelisted for the WebAuthn `CheckOrigin` ceremony step.
     * Comma-separated env var; trimmed + split below.
     *
     * - Production: leave empty. HTTPS origins satisfy the default
     *   scheme check without explicit whitelisting.
     * - Local dev: set PASSKEY_ALLOWED_ORIGINS=http://localhost:3001 (the
     *   admin SPA origin) so the `http://` scheme is accepted for the
     *   listed origins only.
     */
    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('PASSKEY_ALLOWED_ORIGINS', '')),
    ))),

    /*
     * Relying-party identifies the platform to authenticators. `id` is the
     * registrable domain that the browser will scope the credential to —
     * in dev it resolves to whatever APP_URL is set to; in prod it'll be
     * `effectinsight.com` (NOT the admin subdomain — using the apex means
     * one credential works across app.effectinsight.com,
     * api.effectinsight.com, etc).
     */
    'relying_party' => [
        'name' => config('app.name'),
        'id' => parse_url(config('app.url'), PHP_URL_HOST),
        'icon' => null,
    ],

    'models' => [
        // Project-local subclass with HasUuids (see App\Models\Passkey).
        'passkey' => Passkey::class,
        'authenticatable' => env('AUTH_MODEL', User::class),
    ],
];
