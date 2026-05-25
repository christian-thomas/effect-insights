<?php

declare(strict_types=1);

namespace App\Actions\Passkeys;

use Spatie\LaravelPasskeys\Actions\ConfigureCeremonyStepManagerFactoryAction;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;

/**
 * Overrides spatie's default no-op action to optionally whitelist origins
 * via env config. Driven entirely by `passkeys.allowed_origins`:
 *
 *   - In production the admin is served over HTTPS, so the default
 *     `CheckOrigin` step (which requires `https://` when no allowlist is
 *     set) passes naturally and this method leaves the factory untouched.
 *   - In local dev the admin runs on `http://localhost:3001`, which the
 *     default check rejects with "Invalid scheme. HTTPS required." — set
 *     `PASSKEY_ALLOWED_ORIGINS=http://localhost:3001` in your .env to
 *     bypass the scheme requirement for the listed origins only.
 *
 * `allowSubdomains: false` keeps the allowlist exact-match, so adding
 * `http://localhost:3001` does NOT also accept arbitrary `*.localhost`.
 */
class ConfigureCeremonyStepManager extends ConfigureCeremonyStepManagerFactoryAction
{
    #[\Override]
    public function execute(): CeremonyStepManagerFactory
    {
        $factory = parent::execute();

        /** @var list<string> $origins */
        $origins = array_values(array_filter(
            (array) config('passkeys.allowed_origins', []),
            fn (mixed $value): bool => is_string($value) && $value !== '',
        ));

        if ($origins !== []) {
            $factory->setAllowedOrigins($origins, allowSubdomains: false);
        }

        return $factory;
    }
}
