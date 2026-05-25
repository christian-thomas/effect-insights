<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Passkeys;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Spatie\LaravelPasskeys\Actions\FindPasskeyToAuthenticateAction;
use Spatie\LaravelPasskeys\Events\PasskeyUsedToAuthenticateEvent;
use Spatie\LaravelPasskeys\Http\Requests\AuthenticateUsingPasskeysRequest;
use Spatie\LaravelPasskeys\Models\Passkey;

/**
 * Step 2 of WebAuthn authentication. Receives the
 * AuthenticatorAssertionResponse from `navigator.credentials.get()`,
 * matches it against a stored passkey, verifies the signature, logs
 * the user in via Sanctum session, and returns the user envelope
 * matching {@see Login}.
 *
 * Mirrors {@see \App\Http\Controllers\Api\Auth\Login} on the
 * happy-path side so the admin SPA can swap between password and
 * passkey login without any other plumbing changes:
 *
 *   - Session regenerated (prevents fixation).
 *   - Active organisation pre-seeded from the user's first membership
 *     so the very next request already has tenant context.
 *   - UserResource with `organisations` eager-loaded.
 */
class AuthenticateWithPasskey extends Controller
{
    public function __invoke(
        Request $request,
        FindPasskeyToAuthenticateAction $find,
    ): JsonResponse {
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

        $passkey = $find->execute($validated['passkey'], $passkeyOptionsJson);

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

        // Mirror Login.php — pre-seed the session with the first
        // organisation so the very next request has tenant context.
        $defaultOrganisationId = $user->organisations()->value('organisations.id');
        if ($defaultOrganisationId !== null) {
            $request->session()->put('current_organisation_id', $defaultOrganisationId);
        }

        // Spatie's event requires its own FormRequest subtype, but we
        // deliberately bypass spatie's controller to serve JSON rather
        // than redirects. Wrap the incoming request in the expected type
        // so any third-party listeners receive the correct object shape.
        $spatieRequest = AuthenticateUsingPasskeysRequest::createFrom($request);
        event(new PasskeyUsedToAuthenticateEvent($passkey, $spatieRequest));

        return UserResource::make($user->load('organisations'))->response();
    }
}
