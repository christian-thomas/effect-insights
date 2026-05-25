<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Invitations;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateUserInvitationRequest;
use App\Http\Resources\UserInvitationResource;
use App\Models\Organisation;
use App\Models\User;
use App\Notifications\UserInvited;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\HttpFoundation\Response;

class CreateInvitation extends Controller
{
    public function __invoke(
        CreateUserInvitationRequest $request,
        Organisation $organisation,
    ): JsonResponse {
        /** @var User $superuser */
        $superuser = $request->user();
        $invitation = $request->persist($organisation, $superuser);

        // Notify::route avoids the need for a "User" recipient — the invitee
        // doesn't have an account yet, the address is all we have.
        Notification::route('mail', $invitation->getAttribute('email'))
            ->notify(new UserInvited($invitation->load(['organisation', 'invitedBy'])));

        return UserInvitationResource::make($invitation->load('invitedBy'))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }
}
