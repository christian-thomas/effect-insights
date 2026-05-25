<?php

declare(strict_types=1);

namespace App\Actions\Passkeys;

use App\Exceptions\InvalidPasskeyRegistration;
use App\Models\User;
use Illuminate\Http\Request;
use Spatie\LaravelPasskeys\Actions\StorePasskeyAction;
use Spatie\LaravelPasskeys\Exceptions\InvalidPasskey;
use Spatie\LaravelPasskeys\Exceptions\InvalidPasskeyOptions;
use Spatie\LaravelPasskeys\Models\Passkey;

/**
 * Step 2 of WebAuthn registration. Pulls the challenge that step 1
 * stashed in the session, verifies the browser's
 * `PublicKeyCredential` response against it, and persists the new
 * passkey row tagged with the operator-chosen label.
 *
 * Both failure modes (missing session challenge, or spatie's
 * verification rejecting the credential) surface as
 * {@see InvalidPasskeyRegistration} so the global handler renders
 * the right status + message.
 */
final readonly class RegisterPasskey
{
    public function __construct(
        private StorePasskeyAction $store,
    ) {}

    /**
     * @param  array{passkey: string, name: string}  $validated
     */
    public function __invoke(Request $request, User $user, array $validated): Passkey
    {
        $passkeyOptionsJson = (string) $request->session()->pull('passkey-register-options', '');
        if ($passkeyOptionsJson === '') {
            throw InvalidPasskeyRegistration::missingChallenge();
        }

        try {
            return $this->store->execute(
                authenticatable: $user,
                passkeyJson: $validated['passkey'],
                passkeyOptionsJson: $passkeyOptionsJson,
                hostName: $request->getHost(),
                additionalProperties: ['name' => $validated['name']],
            );
        } catch (InvalidPasskey|InvalidPasskeyOptions $exception) {
            throw InvalidPasskeyRegistration::fromSpatie($exception);
        }
    }
}
