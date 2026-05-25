<?php

declare(strict_types=1);

namespace App\Actions\Invitations;

use App\Exceptions\InvalidInvitation;
use App\Http\Requests\AcceptInvitationRequest;
use App\Models\User;
use App\Models\UserInvitation;
use App\Support\OrganisationSession;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Redeems a magic-link invitation: looks up the token, validates it
 * hasn't expired or been used, creates the user (or attaches an
 * existing one with the same email), records the redemption, then
 * signs the user in with the invitation's organisation pre-bound to
 * the session.
 *
 * Same-email existing accounts are attached to the invited
 * organisation without overwriting their password — they sign in
 * with their existing credentials and just gain an extra membership.
 */
final readonly class AcceptInvitation
{
    public function __invoke(AcceptInvitationRequest $request, string $token): User
    {
        $invitation = $this->lookup($token);

        $user = DB::transaction(
            fn (): User => $this->createOrAttach($invitation, $request),
        );

        Auth::login($user);
        $request->session()->regenerate();
        OrganisationSession::bind(
            $request,
            (string) $invitation->getAttribute('organisation_id'),
        );

        return $user;
    }

    private function lookup(string $token): UserInvitation
    {
        $invitation = UserInvitation::query()
            ->where('token', $token)
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now())
            ->first();

        if (! $invitation instanceof UserInvitation) {
            throw new InvalidInvitation;
        }

        return $invitation;
    }

    private function createOrAttach(UserInvitation $invitation, AcceptInvitationRequest $request): User
    {
        $email = (string) $invitation->getAttribute('email');
        $name = (string) $request->validated('name');
        $password = (string) $request->validated('password');
        $role = (string) $invitation->getAttribute('role')->value;

        $existing = User::query()->where('email', $email)->first();

        if ($existing instanceof User) {
            // Same email already has an account — attach to the org
            // without overwriting their password. They sign in with
            // their existing credentials.
            $invitation->organisation->users()->syncWithoutDetaching([
                $existing->getKey() => ['role' => $role],
            ]);
            $user = $existing;
        } else {
            $user = User::create([
                'name' => $name,
                'email' => $email,
                'password' => Hash::make($password),
            ]);
            $invitation->organisation->users()->attach($user, ['role' => $role]);
        }

        $invitation->update([
            'accepted_at' => CarbonImmutable::now(),
            'accepted_user_id' => $user->getKey(),
        ]);

        return $user;
    }
}
