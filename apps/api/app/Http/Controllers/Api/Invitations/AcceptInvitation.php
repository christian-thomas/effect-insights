<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Invitations;

use App\Actions\Invitations\AcceptInvitation as AcceptInvitationAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\AcceptInvitationRequest;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class AcceptInvitation extends Controller
{
    public function __invoke(
        AcceptInvitationRequest $request,
        string $token,
        AcceptInvitationAction $accept,
    ): JsonResponse {
        $user = $accept($request, $token);

        // Force 200 — this acts as a login endpoint as far as the frontend
        // is concerned, so it should mirror /api/login + /api/me's status
        // rather than 201.
        return UserResource::make($user->load('organisations'))
            ->response()
            ->setStatusCode(Response::HTTP_OK);
    }
}
