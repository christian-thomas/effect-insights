<?php

declare(strict_types=1);

namespace App\Actions\Passkeys;

use App\Actions\Auth\LogInUser;
use App\Models\User;
use App\Support\OrganisationSession;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Spatie\LaravelPasskeys\Actions\FindPasskeyToAuthenticateAction;
use Spatie\LaravelPasskeys\Events\PasskeyUsedToAuthenticateEvent;
use Spatie\LaravelPasskeys\Http\Requests\AuthenticateUsingPasskeysRequest;
use Spatie\LaravelPasskeys\Models\Passkey;

/**
 * Step 2 of WebAuthn authentication. Pulls the stashed challenge from
 * session, matches the incoming `passkey` payload against a stored
 * credential, verifies the signature, logs the user in via Sanctum
 * session, fires spatie's authentication event for listeners, and
 * pre-seeds the active organisation so the very next request is
 * tenant-bound.
 *
 * Mirrors {@see LogInUser} on the happy-path side so
 * the admin SPA can swap between password and passkey login without
 * any other plumbing changes.
 */
final readonly class AuthenticateWithPasskey
{
    public function __construct(
        private FindPasskeyToAuthenticateAction $find,
    ) {}

    public function __invoke(Request $request): User
    {
        $validated = $request->validate([
            'passkey' => ['required', 'string'],
        ]);

        $passkeyOptionsJson = (string) $request->session()->pull(
            'passkey-authentication-options',
            '',
        );

        if ($passkeyOptionsJson === '') {
            throw new AuthenticationException(
                'No authentication options found in session — request a fresh challenge.',
            );
        }

        $passkey = $this->find->execute($validated['passkey'], $passkeyOptionsJson);

        if (! $passkey instanceof Passkey) {
            throw new AuthenticationException('Invalid passkey.');
        }

        /** @var User|null $user */
        $user = $passkey->authenticatable;
        if ($user === null) {
            throw new AuthenticationException('Passkey is no longer linked to a user.');
        }

        Auth::login($user, remember: true);
        $request->session()->regenerate();
        OrganisationSession::bindDefault($request, $user);

        // Spatie's event requires its own FormRequest subtype, but we
        // deliberately bypass spatie's controller to serve JSON rather
        // than redirects. Wrap the incoming request in the expected type
        // so any third-party listeners receive the correct object shape.
        $spatieRequest = AuthenticateUsingPasskeysRequest::createFrom($request);
        event(new PasskeyUsedToAuthenticateEvent($passkey, $spatieRequest));

        return $user;
    }
}
