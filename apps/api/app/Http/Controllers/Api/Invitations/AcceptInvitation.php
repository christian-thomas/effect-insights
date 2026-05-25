<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Invitations;

use App\Http\Controllers\Controller;
use App\Http\Requests\AcceptInvitationRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Models\UserInvitation;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Response;

class AcceptInvitation extends Controller
{
    public function __invoke(AcceptInvitationRequest $request, string $token): JsonResponse
    {
        $invitation = UserInvitation::query()
            ->where('token', $token)
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now())
            ->first();

        if ($invitation === null) {
            return response()->json([
                'error' => 'invitation_invalid',
                'message' => 'This invitation is invalid, expired, or already used.',
            ], Response::HTTP_NOT_FOUND);
        }

        $email = $invitation->getAttribute('email');
        $name = (string) $request->validated('name');
        $password = (string) $request->validated('password');

        $user = DB::transaction(function () use ($invitation, $email, $name, $password): User {
            $existing = User::query()->where('email', $email)->first();

            if ($existing instanceof User) {
                // Same email already has an account — attach to the org
                // without overwriting their password. They can sign in
                // with their existing credentials.
                $invitation->organisation->users()->syncWithoutDetaching([
                    $existing->getKey() => ['role' => $invitation->getAttribute('role')->value],
                ]);
                $user = $existing;
            } else {
                $user = User::create([
                    'name' => $name,
                    'email' => $email,
                    'password' => Hash::make($password),
                ]);
                $invitation->organisation->users()->attach($user, [
                    'role' => $invitation->getAttribute('role')->value,
                ]);
            }

            $invitation->update([
                'accepted_at' => CarbonImmutable::now(),
                'accepted_user_id' => $user->getKey(),
            ]);

            return $user;
        });

        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put('current_organisation_id', $invitation->getAttribute('organisation_id'));

        // Force 200 — even though we created a user record this acts as a
        // login endpoint as far as the frontend is concerned, so it should
        // mirror /api/login + /api/me's status rather than 201.
        return UserResource::make($user->load('organisations'))
            ->response()
            ->setStatusCode(Response::HTTP_OK);
    }
}
