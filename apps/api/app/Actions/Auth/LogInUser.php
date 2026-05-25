<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Actions\Passkeys\AuthenticateWithPasskey;
use App\Models\User;
use App\Support\OrganisationSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Password login flow. Validates credentials, attempts auth via the
 * web guard, regenerates the session (fixation defence), then
 * pre-seeds the active organisation so the very next request has
 * tenant context bound.
 *
 * Mirrors {@see AuthenticateWithPasskey} on the
 * happy-path side — both return the now-authenticated User and leave
 * the controller to render UserResource.
 */
final readonly class LogInUser
{
    public function __invoke(Request $request): User
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials, remember: true)) {
            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        $request->session()->regenerate();

        /** @var User $user */
        $user = Auth::user();
        OrganisationSession::bindDefault($request, $user);

        return $user;
    }
}
