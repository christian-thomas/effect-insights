<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Invitations;

use App\Http\Controllers\Controller;
use App\Models\UserInvitation;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class ShowInvitation extends Controller
{
    public function __invoke(string $token): JsonResponse
    {
        $invitation = UserInvitation::query()
            ->where('token', $token)
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now())
            ->with('organisation')
            ->first();

        if ($invitation === null) {
            return response()->json([
                'error' => 'invitation_invalid',
                'message' => 'This invitation is invalid, expired, or already used.',
            ], Response::HTTP_NOT_FOUND);
        }

        return response()->json([
            'email' => $invitation->getAttribute('email'),
            'role' => $invitation->getAttribute('role')->value,
            'expiresAt' => $invitation->getAttribute('expires_at')->toIso8601String(),
            'organisation' => [
                'id' => $invitation->organisation->getKey(),
                'name' => $invitation->organisation->getAttribute('name'),
            ],
        ]);
    }
}
